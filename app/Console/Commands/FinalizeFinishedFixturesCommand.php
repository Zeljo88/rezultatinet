<?php

namespace App\Console\Commands;

use App\Jobs\FinalizeFinishedFixtures;
use App\Models\ApiCallLog;
use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Services\ApiFootballService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FinalizeFinishedFixturesCommand extends Command
{
    protected $signature = 'finalize:finished-fixtures';
    protected $description = 'Find fixtures stuck in 2H/ET at 88+ min or P (penalty) for 60+ min and finalize them via individual API calls';

    public function handle(ApiFootballService $api): int
    {
        // Find fixtures stuck in:
        // - 2H or ET with elapsed >= 88 min and not updated in last 15 min
        // - P (Penalty in progress) not updated in last 60 min (penalties are done)
        $stuckFixtures = Fixture::where(function ($q) {
            $q->whereIn('status_short', ['2H', 'ET'])
                ->where('elapsed_minute', '>=', 88)
                ->where('updated_at', '<', now()->subMinutes(15));
        })->orWhere(function ($q) {
            $q->where('status_short', 'P')
                ->where('updated_at', '<', now()->subMinutes(60));
        })->get();

        if ($stuckFixtures->isEmpty()) {
            $this->info('No stuck fixtures found.');
            return 0;
        }

        $this->info("Found {$stuckFixtures->count()} stuck fixture(s). Checking each via API...");
        Log::info("FinalizeFinishedFixtures (manual): found {$stuckFixtures->count()} stuck fixtures.");

        // Show breakdown by status
        $byStatus = $stuckFixtures->groupBy('status_short')->map->count();
        foreach ($byStatus as $status => $count) {
            $this->line("  - {$status}: {$count} fixture(s)");
        }

        $updated = 0;
        $skipped = 0;
        $bar = $this->output->createProgressBar($stuckFixtures->count());
        $bar->start();

        foreach ($stuckFixtures as $fixture) {
            if (ApiCallLog::getTodayCount() >= 7500) {
                $this->warn('Daily API budget reached (7500), stopping early.');
                Log::warning('FinalizeFinishedFixtures: daily budget reached, stopping early.');
                break;
            }

            $data = $api->getFixtureById($fixture->api_fixture_id);
            ApiCallLog::create([
                'endpoint'    => '/fixtures?id=' . $fixture->api_fixture_id,
                'called_date' => today(),
            ]);

            if (empty($data)) {
                $skipped++;
                $bar->advance();
                continue;
            }

            $statusShort = $data['fixture']['status']['short'] ?? null;
            $statusLong  = $data['fixture']['status']['long'] ?? null;
            $elapsed     = $data['fixture']['status']['elapsed'] ?? null;

            if (!in_array($statusShort, ['FT', 'AET', 'PEN'])) {
                $skipped++;
                $bar->advance();
                continue;
            }

            $fixture->update([
                'status_short'   => $statusShort,
                'status_long'    => $statusLong,
                'elapsed_minute' => $elapsed,
            ]);

            FixtureScore::updateOrCreate(
                ['fixture_id' => $fixture->id],
                [
                    'goals_home'    => $data['goals']['home'] ?? null,
                    'goals_away'    => $data['goals']['away'] ?? null,
                    'home_fulltime' => $data['score']['fulltime']['home'] ?? null,
                    'away_fulltime' => $data['score']['fulltime']['away'] ?? null,
                    'home_halftime' => $data['score']['halftime']['home'] ?? null,
                    'away_halftime' => $data['score']['halftime']['away'] ?? null,
                ]
            );

            Log::info("FinalizeFinishedFixtures: updated fixture id={$fixture->id} api_id={$fixture->api_fixture_id} → {$statusShort} (was {$fixture->status_short})");
            $updated++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done! Updated: {$updated}, Skipped/still-live: {$skipped}");
        Log::info("FinalizeFinishedFixtures (manual): done. updated={$updated}, skipped={$skipped}");

        return 0;
    }
}
