<?php

namespace App\Jobs;

use App\Contracts\ApiFootballQuotaStore;
use App\Exceptions\ApiFootballBlocked;
use App\Exceptions\ApiFootballLocalAttemptLimitReached;
use App\Exceptions\ApiFootballRetryableFailure;
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

    private const BACKLOG_SCAN = 'finalizer-backlog-id';

    private const BACKLOG_STATE_SCHEMA = ApiFootballQuotaStore::REPAIR_SCAN_STATE_SCHEMA;

    private const BACKLOG_ID_WINDOW = 10000;

    private const BACKLOG_STATE_CAS_ATTEMPTS = 3;

    public function uniqueId(): string
    {
        return 'finalize-finished-fixtures';
    }

    public function handle(ApiFootballService $api, ApiFootballQuotaStore $quota): void
    {
        $limit = min(5, max(1, (int) config('api_football.repair.finalizer_per_run', 5)));
        $scanLimit = min(self::MAX_SCAN_PER_LANE, max(self::MIN_SCAN_PER_LANE, $limit * 20));
        $now = now();
        $backlogState = $this->currentBacklogState($quota);
        $states = [
            'recent' => $this->newScanState(),
            'old' => $this->newScanState($backlogState['cursor'], $backlogState['ceiling']),
        ];
        $seen = [];
        $checked = $updated = 0;
        $stopReason = null;
        $providerAttemptsRemaining = 5;
        $claimProviderAttempt = static function () use (&$providerAttemptsRemaining): bool {
            if ($providerAttemptsRemaining <= 0) {
                return false;
            }

            $providerAttemptsRemaining--;

            return true;
        };

        // The first logical opportunity is reserved for an eligible backlog
        // fixture. Recent work follows immediately and reclaims every slot when
        // no backlog fixture is eligible. Retries share the same five tokens.
        for ($slot = 0; $slot < $limit; $slot++) {
            $preferred = $slot % 2 === 0 ? 'old' : 'recent';
            $fallback = $preferred === 'recent' ? 'old' : 'recent';
            $selectedLane = $preferred;
            $fixture = $this->nextEligible(
                $preferred, $states[$preferred], $seen, $quota, $now, $scanLimit
            );
            if (! $fixture) {
                $selectedLane = $fallback;
                $fixture = $this->nextEligible(
                    $fallback, $states[$fallback], $seen, $quota, $now, $scanLimit
                );
            }

            if (! $fixture) {
                break;
            }

            $checked++;
            $providerAttemptsBefore = $providerAttemptsRemaining;
            try {
                $data = $api->getFixtureById(
                    $fixture->api_fixture_id,
                    'FinalizeFinishedFixtures',
                    'fixture_repair',
                    $claimProviderAttempt,
                );
            } catch (ApiFootballLocalAttemptLimitReached) {
                // No reservation or HTTP occurred for the denied claim. Persist
                // inspected progress, but never pass an unrequested old fixture.
                if ($selectedLane === 'old' && $providerAttemptsRemaining === $providerAttemptsBefore) {
                    $states['old']['last_id'] = max(
                        $states['old']['start_id'],
                        (int) $fixture->id - 1,
                    );
                }
                $quota->releaseRepair($fixture->id);
                $stopReason = 'local_attempt_limit';
                break;
            } catch (ApiFootballRetryableFailure) {
                // A transient provider/transport failure must not consume the
                // fixture's bounded repair-attempt allowance. The cursor may move
                // on, and the fixture becomes eligible again next generation.
                $quota->releaseRepair($fixture->id);

                continue;
            } catch (ApiFootballBlocked) {
                // External quota, circuit, provider, or accounting denial remains
                // fail-closed. No progress from this run is committed.
                $quota->releaseRepair($fixture->id);
                $stopReason = 'external_blocked';
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
        $scanStateAdvanced = null;
        if (
            $stopReason !== 'external_blocked'
            && $states['old']['last_id'] !== null
            && $states['old']['last_id'] !== $backlogState['cursor']
        ) {
            $nextBacklogState = $backlogState;
            $nextBacklogState['cursor'] = $states['old']['last_id'];
            $scanStateAdvanced = $quota->compareAndSetRepairScanState(
                self::BACKLOG_SCAN,
                $backlogState,
                $nextBacklogState,
            );
        }
        if ($checked || $updated || $stopReason) {
            $backlogGeneration = $backlogState['generation'];
            $backlogCursor = $backlogState['cursor'];
            $backlogCeiling = $backlogState['ceiling'];
            Log::channel('api_football')->info('repair_finalizer_run', compact(
                'checked', 'updated', 'limit', 'scannedRecent', 'scannedOld', 'stopReason',
                'backlogGeneration', 'backlogCursor', 'backlogCeiling', 'scanStateAdvanced'
            ));
        }
    }

    /**
     * @return array{schema: int, generation: int, cursor: int, ceiling: int}
     */
    private function currentBacklogState(ApiFootballQuotaStore $quota): array
    {
        $lastState = null;

        for ($attempt = 0; $attempt < self::BACKLOG_STATE_CAS_ATTEMPTS; $attempt++) {
            $state = $quota->repairScanState(self::BACKLOG_SCAN);
            if ($state === null) {
                $initial = [
                    'schema' => self::BACKLOG_STATE_SCHEMA,
                    'generation' => 1,
                    'cursor' => 0,
                    'ceiling' => $this->backlogCeiling(),
                ];
                if ($quota->compareAndSetRepairScanState(self::BACKLOG_SCAN, null, $initial)) {
                    return $initial;
                }

                $lastState = $initial;

                continue;
            }

            if (
                $state['cursor'] < $state['ceiling']
                || $state['generation'] === ApiFootballQuotaStore::REPAIR_SCAN_STATE_MAX_INTEGER
            ) {
                return $state;
            }

            $nextGeneration = [
                'schema' => self::BACKLOG_STATE_SCHEMA,
                'generation' => $state['generation'] + 1,
                'cursor' => 0,
                'ceiling' => $this->backlogCeiling(),
            ];
            if ($quota->compareAndSetRepairScanState(self::BACKLOG_SCAN, $state, $nextGeneration)) {
                return $nextGeneration;
            }

            $lastState = $state;
        }

        return $quota->repairScanState(self::BACKLOG_SCAN) ?? $lastState ?? [
            'schema' => self::BACKLOG_STATE_SCHEMA,
            'generation' => 1,
            'cursor' => 0,
            'ceiling' => $this->backlogCeiling(),
        ];
    }

    private function backlogCeiling(): int
    {
        return min(
            (int) (Fixture::query()->max('id') ?? 0),
            ApiFootballQuotaStore::REPAIR_SCAN_STATE_MAX_INTEGER,
        );
    }

    /**
     * @return array{buffer: array<int, Fixture>, loaded: bool, scanned: int, start_id: int, ceiling: int, last_id: ?int, range_end: ?int, range_complete: bool}
     */
    private function newScanState(int $startId = 0, int $ceiling = 0): array
    {
        return [
            'buffer' => [],
            'loaded' => false,
            'scanned' => 0,
            'start_id' => $startId,
            'ceiling' => $ceiling,
            'last_id' => null,
            'range_end' => null,
            'range_complete' => false,
        ];
    }

    /**
     * @param  array{buffer: array<int, Fixture>, loaded: bool, scanned: int, start_id: int, ceiling: int, last_id: ?int, range_end: ?int, range_complete: bool}  $state
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
                $backlog = $this->backlogCandidates(
                    $now,
                    $state['start_id'],
                    $state['ceiling'],
                    $scanLimit,
                );
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
    private function backlogCandidates(
        CarbonInterface $now,
        int $cursorId,
        int $ceiling,
        int $scanLimit,
    ): array {
        if ($cursorId >= $ceiling) {
            return ['fixtures' => [], 'range_end' => $ceiling, 'range_complete' => true];
        }

        // The ceiling is fixed for this generation. Newer IDs are served by
        // the recent lane and cannot postpone this finite cohort's completion.
        $rangeStart = $cursorId;
        $rangeEnd = min($ceiling, $rangeStart + self::BACKLOG_ID_WINDOW);
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
