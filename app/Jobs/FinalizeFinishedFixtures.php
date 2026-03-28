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

    /** Daily API call budget ceiling — leave 500 buffer for other jobs */
    private const API_BUDGET = 7000;

    public function handle(ApiFootballService $api): void
    {
        // Find fixtures stuck in live/intermediate states that should be finished
        $stuckFixtures = Fixture::where(function ($q) {
            // Standard 90min or ET stuck mid-game
            $q->whereIn('status_short', ['2H', 'ET'])
                ->where('elapsed_minute', '>=', 88)
                ->where('updated_at', '<', now()->subMinutes(15));
        })->orWhere(function ($q) {
            // Penalty shootout in progress, not updated in >60 min
            $q->where('status_short', 'P')
                ->where('updated_at', '<', now()->subMinutes(60));
        })->orWhere(function ($q) {
            // PEN / AET already set as final status but scores may be missing.
            // CRITICAL: kick_off window is SHORT (6h) to avoid accumulating old
            // PEN/AET fixtures that create an infinite retry loop.
            // After 6h, these fixtures should have been processed and won't re-enter.
            // Also require >60min cooldown (not 15min) to prevent tight retry loops.
            $q->whereIn('status_short', ['PEN', 'AET'])
                ->where('updated_at', '<', now()->subMinutes(60))
                ->where('kick_off', '>=', now()->subHours(6));
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
            if (ApiCallLog::getTodayCount() >= self::API_BUDGET) {
                Log::warning('FinalizeFinishedFixtures: daily API budget reached (' . self::API_BUDGET . '), stopping early.');
                break;
            }

            $data = $api->getFixtureById($fixture->api_fixture_id);
            ApiCallLog::create([
                'endpoint'    => '/fixtures?id=' . $fixture->api_fixture_id,
                'called_date' => today(),
            ]);

            if (empty($data)) {
                Log::warning("FinalizeFinishedFixtures: empty API response for fixture id={$fixture->id} api_id={$fixture->api_fixture_id}");
                // IMPORTANT: touch() to push updated_at forward so we don't retry immediately
                $fixture->touch();
                $skipped++;
                continue;
            }

            $statusShort = $data['fixture']['status']['short'] ?? null;
            $statusLong  = $data['fixture']['status']['long'] ?? null;
            $elapsed     = $data['fixture']['status']['elapsed'] ?? null;

            // Only update if the match is truly finished
            if (!in_array($statusShort, ['FT', 'AET', 'PEN'])) {
                Log::info("FinalizeFinishedFixtures: fixture id={$fixture->id} api_id={$fixture->api_fixture_id} still not finished (status={$statusShort}), skipping.");
                // IMPORTANT: touch() so updated_at advances — prevents re-processing same fixture
                // every 5 minutes until it's actually FT!
                $fixture->touch();
                $skipped++;
                continue;
            }

            $origStatus  = $fixture->status_short;
            $origElapsed = $fixture->elapsed_minute;

            // If status hasn't changed (e.g. PEN→PEN, AET→AET), just touch() with long
            // cooldown so it won't be re-processed for 4 hours. This prevents infinite loops
            // where API persistently returns the same terminal status.
            if ($statusShort === $fixture->status_short) {
                Log::info("FinalizeFinishedFixtures: fixture id={$fixture->id} already {$statusShort}, touching to suppress retries.");
                // Use a direct DB update to push updated_at far into the future (4h cooldown)
                Fixture::where('id', $fixture->id)->update(['updated_at' => now()->addHours(4)]);
                $skipped++;
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
                    'goals_home'      => $data['goals']['home'] ?? null,
                    'goals_away'      => $data['goals']['away'] ?? null,
                    'home_halftime'   => $data['score']['halftime']['home'] ?? null,
                    'away_halftime'   => $data['score']['halftime']['away'] ?? null,
                    'home_fulltime'   => $data['score']['fulltime']['home'] ?? null,
                    'away_fulltime'   => $data['score']['fulltime']['away'] ?? null,
                    'home_extratime'  => $data['score']['extratime']['home'] ?? null,
                    'away_extratime'  => $data['score']['extratime']['away'] ?? null,
                    'home_penalties'  => $data['score']['penalty']['home'] ?? null,
                    'away_penalties'  => $data['score']['penalty']['away'] ?? null,
                ]
            );

            Log::info("FinalizeFinishedFixtures: updated fixture id={$fixture->id} api_id={$fixture->api_fixture_id} → {$statusShort} (was {$origStatus}@{$origElapsed}min)");
            $updated++;
        }

        Log::info("FinalizeFinishedFixtures: done. updated={$updated}, skipped={$skipped}, total_checked={$stuckFixtures->count()}");
    }
}
