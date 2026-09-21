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

    private const MAX_PAGE_SIZE = 50;

    public function uniqueId(): string
    {
        return 'finalize-finished-fixtures';
    }

    public function handle(ApiFootballService $api, ApiFootballQuotaStore $quota): void
    {
        $limit = max(1, (int) config('api_football.repair.finalizer_per_run', 5));
        $scanLimit = min(self::MAX_SCAN_PER_LANE, max(self::MIN_SCAN_PER_LANE, $limit * 20));
        $pageSize = min(self::MAX_PAGE_SIZE, max(20, $limit * 4));
        $now = now();
        $states = [
            'recent' => $this->newScanState(),
            'old' => $this->newScanState(),
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
                $preferred, $states[$preferred], $seen, $quota, $now, $pageSize, $scanLimit
            ) ?? $this->nextEligible(
                $fallback, $states[$fallback], $seen, $quota, $now, $pageSize, $scanLimit
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
        if ($checked || $updated || $stopReason) {
            Log::channel('api_football')->info('repair_finalizer_run', compact(
                'checked', 'updated', 'limit', 'scannedRecent', 'scannedOld', 'stopReason'
            ));
        }
    }

    /**
     * @return array{buffer: array<int, Fixture>, cursor_at: ?string, cursor_id: ?int, scanned: int, exhausted: bool}
     */
    private function newScanState(): array
    {
        return [
            'buffer' => [],
            'cursor_at' => null,
            'cursor_id' => null,
            'scanned' => 0,
            'exhausted' => false,
        ];
    }

    /**
     * @param  array{buffer: array<int, Fixture>, cursor_at: ?string, cursor_id: ?int, scanned: int, exhausted: bool}  $state
     * @param  array<int, bool>  $seen
     */
    private function nextEligible(
        string $lane,
        array &$state,
        array &$seen,
        ApiFootballQuotaStore $quota,
        CarbonInterface $now,
        int $pageSize,
        int $scanLimit,
    ): ?Fixture {
        while (! $state['exhausted'] && $state['scanned'] < $scanLimit) {
            if ($state['buffer'] === []) {
                $query = $this->candidateQuery($now);
                $descending = $lane === 'recent';

                if ($state['cursor_at'] !== null) {
                    $operator = $descending ? '<' : '>';
                    $idOperator = $descending ? '<' : '>';
                    $query->where(function (Builder $cursor) use ($state, $operator, $idOperator): void {
                        $cursor->where('updated_at', $operator, $state['cursor_at'])
                            ->orWhere(function (Builder $sameTime) use ($state, $idOperator): void {
                                $sameTime->where('updated_at', $state['cursor_at'])
                                    ->where('id', $idOperator, $state['cursor_id']);
                            });
                    });
                }

                $remaining = $scanLimit - $state['scanned'];
                $page = $query
                    ->orderBy('updated_at', $descending ? 'desc' : 'asc')
                    ->orderBy('id', $descending ? 'desc' : 'asc')
                    ->limit(min($pageSize, $remaining))
                    ->get();

                if ($page->isEmpty()) {
                    $state['exhausted'] = true;

                    return null;
                }

                $last = $page->last();
                $state['cursor_at'] = (string) $last->getRawOriginal('updated_at');
                $state['cursor_id'] = (int) $last->id;
                $state['buffer'] = $page->all();
            }

            /** @var Fixture $fixture */
            $fixture = array_shift($state['buffer']);
            $state['scanned']++;
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

        return null;
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
