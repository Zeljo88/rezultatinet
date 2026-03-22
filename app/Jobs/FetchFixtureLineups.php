<?php

namespace App\Jobs;

use App\Models\ApiCallLog;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Services\ApiFootballService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchFixtureLineups implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $fixtureId,
        public readonly int $apiFixtureId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->fixtureId;
    }

    public function handle(ApiFootballService $api): void
    {
        $fixture = Fixture::with(['homeTeam', 'awayTeam'])->find($this->fixtureId);

        if (!$fixture) {
            return;
        }

        // Skip if lineups were already fetched today
        if ($fixture->lineups_fetched_at && $fixture->lineups_fetched_at->isToday()) {
            return;
        }

        // Mark as fetched immediately (before API call) to prevent spam
        // even if the API call fails or returns empty data
        $fixture->update(['lineups_fetched_at' => now()]);

        // Budget guard
        if (ApiCallLog::getTodayCount() >= 7500) {
            Log::warning("API daily budget reached, skipping lineup fetch for fixture {$this->fixtureId}");
            return;
        }

        try {
            $lineupData = $api->getLineups($this->apiFixtureId);
            ApiCallLog::create(['endpoint' => '/fixtures/lineups', 'called_date' => today()]);

            if (empty($lineupData)) {
                Log::info("No lineup data returned for fixture {$this->fixtureId}");
                return;
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
                if (!$side) continue;

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
                        'formation'   => $teamLineup['formation'] ?? null,
                        'coach_name'  => $teamLineup['coach']['name'] ?? null,
                        'startxi'     => $startxi,
                        'substitutes' => $substitutes,
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning("Lineup sync failed for fixture {$this->fixtureId}: " . $e->getMessage());
        }
    }
}
