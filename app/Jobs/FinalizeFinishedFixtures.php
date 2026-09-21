<?php

namespace App\Jobs;

use App\Contracts\ApiFootballQuotaStore;
use App\Exceptions\ApiFootballBlocked;
use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Services\ApiFootballService;
use App\Support\FootballFixtureStatus;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FinalizeFinishedFixtures implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 300;

    private const MIN_SCAN_PER_LANE = 40;

    private const MAX_SCAN_PER_LANE = 200;

    private const BACKLOG_CURSOR = 'finalizer-backlog-id';

    private const BACKLOG_ID_WINDOW = 10000;

    public function uniqueId(): string
    {
        return 'finalize-finished-fixtures';
    }

    public function handle(ApiFootballService $api, ApiFootballQuotaStore $quota): void
    {
        $limit = max(1, (int) config('api_football.repair.finalizer_per_run', 5));
        $scanLimit = min(self::MAX_SCAN_PER_LANE, max(self::MIN_SCAN_PER_LANE, $limit * 20));
        $now = now();
        $storedBacklogCursor = $quota->repairScanCursor(self::BACKLOG_CURSOR);
        $backlogCursor = $storedBacklogCursor ?? 0;
        $states = [
            'recent' => $this->newScanState(),
            'old' => $this->newScanState($backlogCursor),
        ];
        $seen = [];
        $checked = $updated = 0;
        $stopReason = null;

        // Alternate the first lane each scheduler window, then alternate within the
        // run. This reserves deterministic progress for both fresh and old backlog.
        $recentFirst = intdiv(now('UTC')->timestamp, 300) % 2 === 0;

        for ($slot = 0; $slot < $limit; $slot++) {
            $preferred = ($slot + ($recentFirst ? 0 : 1)) % 2 === 0 ? 'recent' : 'old';
            $fallback = $preferred === 'recent' ? 'old' : 'recent';
            $fixture = $this->nextEligible(
                $preferred, $states[$preferred], $seen, $quota, $now, $scanLimit
            ) ?? $this->nextEligible(
                $fallback, $states[$fallback], $seen, $quota, $now, $scanLimit
            );

            if (! $fixture) {
                break;
            }

            $checked++;
            try {
                $data = $api->getFixtureById($fixture->api_fixture_id, 'FinalizeFinishedFixtures');
            } catch (ApiFootballBlocked) {
                // A denied reservation made no provider call and must not consume a
                // fixture attempt/cooldown. Release only this job's short scan lock.
                $quota->releaseRepair($fixture->id);
                $stopReason = 'provider_blocked';
                break;
            }

            if (empty($data)) {
                $quota->recordRepair($fixture->id, 'empty');

                continue;
            }

            $status = FootballFixtureStatus::normalize($data['fixture']['status']['short'] ?? null);
            if (! FootballFixtureStatus::isTerminal($status)) {
                $quota->recordRepair($fixture->id, 'still_'.($status ?? 'unknown'));

                continue;
            }
            if (! FootballFixtureStatus::canPersist($fixture->status_short, $status)) {
                $quota->recordRepair($fixture->id, 'ignored_regression_'.$status, true);

                continue;
            }

            $fixture->update([
                'status_short' => $status,
                'status_long' => $data['fixture']['status']['long'] ?? null,
                'elapsed_minute' => $data['fixture']['status']['elapsed'] ?? null,
            ]);
            FixtureScore::updateOrCreate(['fixture_id' => $fixture->id], [
                'goals_home' => $data['goals']['home'] ?? null, 'goals_away' => $data['goals']['away'] ?? null,
                'home_halftime' => $data['score']['halftime']['home'] ?? null, 'away_halftime' => $data['score']['halftime']['away'] ?? null,
                'home_fulltime' => $data['score']['fulltime']['home'] ?? null, 'away_fulltime' => $data['score']['fulltime']['away'] ?? null,
                'home_extratime' => $data['score']['extratime']['home'] ?? null, 'away_extratime' => $data['score']['extratime']['away'] ?? null,
                'home_penalties' => $data['score']['penalty']['home'] ?? null, 'away_penalties' => $data['score']['penalty']['away'] ?? null,
            ]);
            $quota->recordRepair($fixture->id, 'finalized_'.$status, true);
            $updated++;
        }

        $scannedRecent = $states['recent']['scanned'];
        $scannedOld = $states['old']['scanned'];
        $cursorAdvanced = null;
        if ($stopReason === null && $states['old']['last_id'] !== null) {
            $cursorAdvanced = $quota->advanceRepairScanCursor(
                self::BACKLOG_CURSOR,
                $backlogCursor,
                $states['old']['last_id'],
            );
        }
        if ($checked || $updated || $stopReason) {
            Log::channel('api_football')->info('repair_finalizer_run', compact(
                'checked', 'updated', 'limit', 'scannedRecent', 'scannedOld', 'stopReason',
                'backlogCursor', 'cursorAdvanced'
            ));
        }
    }

    /**
     * @return array{buffer: array<int, Fixture>, loaded: bool, scanned: int, start_id: int, last_id: ?int, range_end: ?int, range_complete: bool}
     */
    private function newScanState(int $startId = 0): array
    {
        return [
            'buffer' => [],
            'loaded' => false,
            'scanned' => 0,
            'start_id' => $startId,
            'last_id' => null,
            'range_end' => null,
            'range_complete' => false,
        ];
    }

    /**
     * @param  array{buffer: array<int, Fixture>, loaded: bool, scanned: int, start_id: int, last_id: ?int, range_end: ?int, range_complete: bool}  $state
     * @param  array<int, bool>  $seen
     */
    private function nextEligible(
        string $lane,
        array &$state,
        array &$seen,
        ApiFootballQuotaStore $quota,
        CarbonInterface $now,
        int $scanLimit,
    ): ?Fixture {
        if (! $state['loaded']) {
            if ($lane === 'recent') {
                $state['buffer'] = $this->recentCandidates($now, $scanLimit);
            } else {
                $backlog = $this->backlogCandidates($now, $state['start_id'], $scanLimit);
                $state['buffer'] = $backlog['fixtures'];
                $state['range_end'] = $backlog['range_end'];
                $state['range_complete'] = $backlog['range_complete'];
            }
            $state['loaded'] = true;
        }

        while ($state['buffer'] !== [] && $state['scanned'] < $scanLimit) {
            /** @var Fixture $fixture */
            $fixture = array_shift($state['buffer']);
            $state['scanned']++;
            if ($lane === 'old') {
                $state['last_id'] = (int) $fixture->id;
            }
            if (isset($seen[$fixture->id])) {
                continue;
            }
            $seen[$fixture->id] = true;

            if (! $fixture->api_fixture_id) {
                continue;
            }
            if ($quota->acquireRepair($fixture->id, 'FinalizeFinishedFixtures')) {
                return $fixture;
            }
        }

        if ($lane === 'old' && $state['range_complete'] && $state['range_end'] !== null) {
            $state['last_id'] = $state['range_end'];
        }

        return null;
    }

    /** @return array<int, Fixture> */
    private function recentCandidates(CarbonInterface $now, int $scanLimit): array
    {
        return $this->candidateQuery($now)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($scanLimit)
            ->get()
            ->all();
    }

    /** @return array{fixtures: array<int, Fixture>, range_end: ?int, range_complete: bool} */
    private function backlogCandidates(CarbonInterface $now, int $cursorId, int $scanLimit): array
    {
        $maxId = (int) (Fixture::query()->max('id') ?? 0);
        if ($maxId === 0) {
            return ['fixtures' => [], 'range_end' => null, 'range_complete' => true];
        }

        // Cursor loss or expiry restarts at zero. Reaching the snapshotted
        // immutable-ID ceiling wraps exactly once on the next invocation.
        $rangeStart = $cursorId >= $maxId ? 0 : $cursorId;
        $rangeEnd = min($maxId, $rangeStart + self::BACKLOG_ID_WINDOW);
        $fixtures = $this->candidateQuery($now)
            ->where('id', '>', $rangeStart)
            ->where('id', '<=', $rangeEnd)
            ->orderBy('id')
            ->limit($scanLimit)
            ->get();

        return [
            'fixtures' => $fixtures->all(),
            'range_end' => $rangeEnd,
            'range_complete' => $fixtures->count() < $scanLimit,
        ];
    }

    private function candidateQuery(CarbonInterface $now): Builder
    {
        return Fixture::query()->where(function (Builder $candidates) use ($now): void {
            $candidates->where(function (Builder $query) use ($now): void {
                $query->whereIn('status_short', ['2H', 'ET'])
                    ->where('elapsed_minute', '>=', 88)
                    ->where('updated_at', '<', $now->copy()->subMinutes(15));
            })->orWhere(function (Builder $query) use ($now): void {
                $query->where('status_short', 'P')
                    ->where('updated_at', '<', $now->copy()->subMinutes(60));
            })->orWhere(function (Builder $query) use ($now): void {
                $query->whereIn('status_short', ['PEN', 'AET'])
                    ->where('updated_at', '<', $now->copy()->subHours(2))
                    ->where('kick_off', '>=', $now->copy()->subHours(6));
            });
        });
    }
}
