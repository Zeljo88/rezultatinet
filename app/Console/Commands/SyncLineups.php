<?php

namespace App\Console\Commands;

use App\Models\ApiCallLog;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Services\ApiFootballService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SyncLineups extends Command
{
    protected $signature   = 'sync:lineups';
    protected $description = 'Sync match lineups from API-Sports for today/tomorrow fixtures';

    public function handle(ApiFootballService $api): int
    {
        $budget = 6500;

        if (ApiCallLog::getTodayCount() >= $budget) {
            $this->warn('Daily API budget reached. Skipping lineup sync.');
            return self::SUCCESS;
        }

        // Fixtures from today and tomorrow that need lineups
        $fixtures = Fixture::whereBetween('kick_off', [
                now()->startOfDay(),
                now()->addDay()->endOfDay(),
            ])
            ->whereNotNull('api_fixture_id')
            // Only fetch lineups for matches that are NOT "NS" (Not Started)
            // OR for NS matches within 2 hours of kick-off
            ->where(function ($q) {
                $q->where(function ($q2) {
                    // Non-NS statuses: lineups may be available
                    $q2->whereNotIn('status_short', ['NS', 'TBD', 'CANC', 'ABD', 'AWD', 'WO']);
                })->orWhere(function ($q2) {
                    // NS fixtures kicking off within 2 hours
                    $q2->where('status_short', 'NS')
                       ->where('kick_off', '<=', now()->addHours(2));
                });
            })
            // Not already synced
            ->whereDoesntHave('lineups')
            ->get();

        $this->info("Found {$fixtures->count()} fixtures needing lineup sync.");
        $synced = 0;

        foreach ($fixtures as $fixture) {
            if (ApiCallLog::getTodayCount() >= $budget) {
                $this->warn('Budget reached mid-sync, stopping.');
                break;
            }

            $lineupData = $api->getLineups($fixture->api_fixture_id);
            ApiCallLog::create(['endpoint' => '/fixtures/lineups', 'called_date' => today()]);

            if (empty($lineupData)) {
                $this->line("  No lineup data for fixture #{$fixture->id} (api: {$fixture->api_fixture_id})");
                continue;
            }

            foreach ($lineupData as $teamLineup) {
                $side = null;
                $teamApiId = $teamLineup['team']['id'] ?? null;
                if ($teamApiId) {
                    if ($fixture->homeTeam && $fixture->homeTeam->api_team_id == $teamApiId) {
                        $side = 'home';
                    } elseif ($fixture->awayTeam && $fixture->awayTeam->api_team_id == $teamApiId) {
                        $side = 'away';
                    }
                }

                // Fallback: use order in response
                if (!$side) {
                    static $sideIndex = 0;
                    $side = $sideIndex === 0 ? 'home' : 'away';
                    $sideIndex = ($sideIndex + 1) % 2;
                }

                $startxi = collect($teamLineup['startXI'] ?? [])->map(fn($p) => [
                    'number' => $p['player']['number'] ?? null,
                    'name'   => $p['player']['name'] ?? null,
                    'pos'    => $p['player']['pos'] ?? null,
                    'grid'   => $p['player']['grid'] ?? null,
                ])->toArray();

                $substitutes = collect($teamLineup['substitutes'] ?? [])->map(fn($p) => [
                    'number' => $p['player']['number'] ?? null,
                    'name'   => $p['player']['name'] ?? null,
                    'pos'    => $p['player']['pos'] ?? null,
                ])->toArray();

                FixtureLineup::updateOrCreate(
                    ['fixture_id' => $fixture->id, 'team_side' => $side],
                    [
                        'formation'  => $teamLineup['formation'] ?? null,
                        'coach_name' => $teamLineup['coach']['name'] ?? null,
                        'startxi'    => $startxi,
                        'substitutes' => $substitutes,
                    ]
                );
            }

            $synced++;
            $this->line("  ✓ Synced lineups for fixture #{$fixture->id}");
        }

        $this->info("Lineup sync complete. Synced: {$synced} fixtures.");
        Log::info("SyncLineups: synced {$synced} fixtures.");

        return self::SUCCESS;
    }
}
