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
 * Finds fixtures that kicked off more than 3 hours ago but are still
 * in a non-final status and re-fetches correct status from the API.
 *
 * OPTIMIZED: Night guard (01-07 UTC) skips processing entirely when
 * no live matches exist in DB, preventing unnecessary API usage.
 */
class FixZombieFixtures implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum fixtures to process per run (API budget protection) */
    private const MAX_PER_RUN = 10;

    /** Daily API call budget ceiling */
    private const API_BUDGET = 7000;

    /** Statuses considered truly "final" — no re-fetch needed */
    private const FINAL_STATUSES = [
        'FT', 'AET', 'PEN', 'AWD', 'WO', 'CANC', 'ABD', 'PST', 'INT', 'SUSP', 'TBD', 'NS',
    ];

    public function handle(ApiFootballService $api): void
    {
        // Night guard — 01:00 to 07:00 UTC, skip entirely if no live matches
        $hour = (int) now()->format('G');
        if ($hour >= 1 && $hour < 7) {
            $hasLive = Fixture::whereIn('status_short', ['1H', 'HT', '2H', 'ET', 'P', 'BT'])->exists();
            if (!$hasLive) {
                Log::info('FixZombieFixtures: night-time (01-07 UTC), no live matches — skipping.');
                return;
            }
        }

        $zombies = Fixture::where('kick_off', '<', now()->subHours(3))
            ->whereNotIn('status_short', self::FINAL_STATUSES)
            ->where('updated_at', '<', now()->subMinutes(60))  // cooldown: don't re-fetch same zombie < 60min
            ->orderBy('kick_off', 'desc')
            ->limit(self::MAX_PER_RUN)
            ->get(['id', 'api_fixture_id', 'status_short', 'elapsed_minute', 'kick_off', 'updated_at']);

        if ($zombies->isEmpty()) {
            Log::info('FixZombieFixtures: no zombie fixtures found. ✓');
            return;
        }

        $count = $zombies->count();
        Log::warning("FixZombieFixtures: found {$count} zombie fixture(s) — re-fetching from API.");

        $byStatus = $zombies->groupBy('status_short')->map->count();
        $statusSummary = $byStatus->map(fn($c, $s) => "{$s}:{$c}")->implode(', ');
        Log::info("FixZombieFixtures: breakdown = [{$statusSummary}]");

        $repaired = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($zombies as $fixture) {
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
                // touch() to prevent retry next run (will wait until next natural cycle)
                $fixture->touch();
                $failed++;
                continue;
            }

            $newStatus  = $data['fixture']['status']['short'] ?? null;
            $newLong    = $data['fixture']['status']['long']  ?? null;
            $newElapsed = $data['fixture']['status']['elapsed'] ?? null;
            $oldStatus  = $fixture->status_short;

            if ($newStatus === $oldStatus) {
                Log::info("FixZombieFixtures: fixture id={$fixture->id} still {$oldStatus} in API, skipping.");
                // touch() to push updated_at — prevents immediate re-fetch next 30min cycle
                // for fixtures genuinely stuck by API delay
                $fixture->touch();
                $skipped++;
                continue;
            }

            $fixture->update([
                'status_short'   => $newStatus,
                'status_long'    => $newLong,
                'elapsed_minute' => $newElapsed,
            ]);

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

        $remaining = Fixture::where('kick_off', '<', now()->subHours(3))
            ->whereNotIn('status_short', self::FINAL_STATUSES)
            ->where('updated_at', '<', now()->subMinutes(60))
            ->count();

        if ($remaining > 0) {
            Log::warning("FixZombieFixtures: {$remaining} zombie fixture(s) still remain.");
        }
    }
}
