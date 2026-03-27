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

/**
 * FixZombieFixtures — Safety net job that runs every 30 minutes.
 *
 * Finds ALL fixtures that kicked off more than 3 hours ago but are still
 * in a non-final status (not FT, AET, PEN, Cancelled, Postponed, etc.)
 * and re-fetches the correct status from the API.
 *
 * This prevents "zombie" matches that get stuck in live states (2H, 1H,
 * HT, ET, P, PEN, etc.) from remaining broken indefinitely.
 */
class FixZombieFixtures implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum fixtures to process per run (API budget protection) */
    private const MAX_PER_RUN = 20;

    /** Daily API call budget ceiling */
    private const API_BUDGET = 7400;

    /** Statuses considered truly "final" — no re-fetch needed */
    private const FINAL_STATUSES = [
        'FT',   // Full Time
        'AET',  // After Extra Time (already final — score stored)
        'PEN',  // After Penalties   (already final — score stored)
        'AWD',  // Awarded
        'WO',   // Walkover
        'CANC', // Cancelled
        'ABD',  // Abandoned
        'PST',  // Postponed
        'INT',  // Interrupted
        'SUSP', // Suspended
        'TBD',  // To Be Defined
        'NS',   // Not Started (future fixture, shouldn't appear here but safe to skip)
    ];

    public function handle(ApiFootballService $api): void
    {
        $zombies = Fixture::where('kick_off', '<', now()->subHours(3))
            ->whereNotIn('status_short', self::FINAL_STATUSES)
            ->orderBy('kick_off', 'desc')
            ->limit(self::MAX_PER_RUN)
            ->get(['id', 'api_fixture_id', 'status_short', 'elapsed_minute', 'kick_off']);

        if ($zombies->isEmpty()) {
            Log::info('FixZombieFixtures: no zombie fixtures found. ✓');
            return;
        }

        $count = $zombies->count();
        Log::warning("FixZombieFixtures: found {$count} zombie fixture(s) — re-fetching from API.");

        // Log breakdown by status
        $byStatus = $zombies->groupBy('status_short')->map->count();
        $statusSummary = $byStatus->map(fn($c, $s) => "{$s}:{$c}")->implode(', ');
        Log::info("FixZombieFixtures: breakdown = [{$statusSummary}]");

        $repaired = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($zombies as $fixture) {
            // Respect daily API budget
            if (ApiCallLog::getTodayCount() >= self::API_BUDGET) {
                Log::warning('FixZombieFixtures: daily API budget reached, stopping early.');
                break;
            }

            $data = $api->getFixtureById($fixture->api_fixture_id);

            ApiCallLog::create([
                'endpoint'    => '/fixtures?id=' . $fixture->api_fixture_id,
                'called_date' => today(),
            ]);

            if (empty($data)) {
                Log::error("FixZombieFixtures: empty API response for fixture id={$fixture->id}");
                $failed++;
                continue;
            }

            $newStatus = $data['fixture']['status']['short'] ?? null;
            $newLong   = $data['fixture']['status']['long']  ?? null;
            $newElapsed = $data['fixture']['status']['elapsed'] ?? null;
            $oldStatus  = $fixture->status_short;

            if ($newStatus === $oldStatus) {
                // Still the same status — API might be delayed, skip
                Log::info("FixZombieFixtures: fixture id={$fixture->id} still {$oldStatus} in API, skipping.");
                $skipped++;
                continue;
            }

            // Update fixture status
            $fixture->update([
                'status_short'   => $newStatus,
                'status_long'    => $newLong,
                'elapsed_minute' => $newElapsed,
            ]);

            // Always update scores to ensure completeness
            FixtureScore::updateOrCreate(
                ['fixture_id' => $fixture->id],
                [
                    'goals_home'      => $data['goals']['home'] ?? null,
                    'goals_away'      => $data['goals']['away'] ?? null,
                    'home_halftime'   => $data['score']['halftime']['home']  ?? null,
                    'away_halftime'   => $data['score']['halftime']['away']  ?? null,
                    'home_fulltime'   => $data['score']['fulltime']['home']  ?? null,
                    'away_fulltime'   => $data['score']['fulltime']['away']  ?? null,
                    'home_extratime'  => $data['score']['extratime']['home'] ?? null,
                    'away_extratime'  => $data['score']['extratime']['away'] ?? null,
                    'home_penalties'  => $data['score']['penalty']['home']   ?? null,
                    'away_penalties'  => $data['score']['penalty']['away']   ?? null,
                ]
            );

            Log::info(
                "FixZombieFixtures: REPAIRED fixture id={$fixture->id} api_id={$fixture->api_fixture_id} " .
                "kick_off={$fixture->kick_off} | {$oldStatus} → {$newStatus}"
            );

            $repaired++;
        }

        Log::info(
            "FixZombieFixtures: run complete. " .
            "zombies_found={$count}, repaired={$repaired}, skipped={$skipped}, failed={$failed}"
        );

        // Warn if there are MORE zombies beyond the MAX_PER_RUN limit
        $remaining = Fixture::where('kick_off', '<', now()->subHours(3))
            ->whereNotIn('status_short', self::FINAL_STATUSES)
            ->count();

        if ($remaining > 0) {
            Log::warning("FixZombieFixtures: {$remaining} zombie fixture(s) still remain (will be processed in next run).");
        }
    }
}
