<?php

namespace App\Jobs;

use App\Models\ApiCallLog;
use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Services\ApiFootballService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FinalizeFinishedFixtures implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ApiFootballService $api): void
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
            Log::info('FinalizeFinishedFixtures: no stuck fixtures found, nothing to do.');
            return;
        }

        Log::info("FinalizeFinishedFixtures: found {$stuckFixtures->count()} stuck fixture(s) to check.");

        $updated = 0;
        $skipped = 0;

        foreach ($stuckFixtures as $fixture) {
            // Respect daily API budget
            if (ApiCallLog::getTodayCount() >= 7500) {
                Log::warning('FinalizeFinishedFixtures: daily API budget reached (7500), stopping early.');
                break;
            }

            $data = $api->getFixtureById($fixture->api_fixture_id);
            ApiCallLog::create([
                'endpoint'    => '/fixtures?id=' . $fixture->api_fixture_id,
                'called_date' => today(),
            ]);

            if (empty($data)) {
                Log::warning("FinalizeFinishedFixtures: empty API response for fixture id={$fixture->id} api_id={$fixture->api_fixture_id}");
                $skipped++;
                continue;
            }

            $statusShort = $data['fixture']['status']['short'] ?? null;
            $statusLong  = $data['fixture']['status']['long'] ?? null;
            $elapsed     = $data['fixture']['status']['elapsed'] ?? null;

            // Only update if the match is truly finished
            if (!in_array($statusShort, ['FT', 'AET', 'PEN'])) {
                Log::info("FinalizeFinishedFixtures: fixture id={$fixture->id} api_id={$fixture->api_fixture_id} still not finished (status={$statusShort}), skipping.");
                $skipped++;
                continue;
            }

            // Update fixture status
            $fixture->update([
                'status_short'   => $statusShort,
                'status_long'    => $statusLong,
                'elapsed_minute' => $elapsed,
            ]);

            // Update final scores
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

            $origStatus = $fixture->status_short;
$origElapsed = $fixture->elapsed_minute;
            Log::info("FinalizeFinishedFixtures: updated fixture id={$fixture->id} api_id={$fixture->api_fixture_id} → {$statusShort} (was {$origStatus}@{$origElapsed}min)");
            $updated++;
        }

        Log::info("FinalizeFinishedFixtures: done. updated={$updated}, skipped={$skipped}, total_checked={$stuckFixtures->count()}");
    }
}
