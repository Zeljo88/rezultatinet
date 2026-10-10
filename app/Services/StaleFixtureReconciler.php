<?php

namespace App\Services;

use App\Exceptions\ApiFootballBlocked;
use App\Exceptions\ApiFootballLocalAttemptLimitReached;
use App\Exceptions\ApiFootballProviderResponseException;
use App\Exceptions\ApiFootballRetryableFailure;
use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Services\ApiFootball\ApiFootballGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class StaleFixtureReconciler
{
    public function __construct(private StaleFixturePolicy $policy, private ApiFootballGateway $gateway) {}

    public function run(StaleFixtureJournal $journal): array
    {
        $cohort = $this->policy->cohort();
        $inspected = 0;
        $outcomes = [];
        $stop = null;
        while ($inspected < 100 && $journal->attempts() < 5) {
            $journal->guard();
            $cursor = $journal->state['cursor'];
            if (! is_int($cursor) || $cursor < 0 || $cursor >= count($cohort)) {
                throw new \RuntimeException('invalid_cursor');
            }
            $identity = $cohort[$cursor];
            $id = $identity['id'];
            // Advance every inspected identity, including skips. This finite sealed
            // ring cannot be starved by low IDs, cooldowns, or newly imported rows.
            $journal->append(['type' => 'inspect', 'id' => $id, 'cursor' => ($cursor + 1) % count($cohort), 'at' => time()]);
            $inspected++;
            $retry = $journal->state['rows'][$id] ?? [];
            if (($retry['review'] ?? false) || ($retry['next_at'] ?? 0) > time()) {
                continue;
            }
            $fixture = Fixture::find($id);
            $score = $fixture ? FixtureScore::where('fixture_id', $id)->first() : null;
            if (! $fixture) {
                $outcome = 'missing_fixture';
                $snapshot = null;
            } else {
                $snapshot = $this->policy->snapshot($fixture, $score);
                $outcome = $this->policy->eligibility($snapshot, $identity, CarbonImmutable::now('UTC'));
                if ($outcome === null && ! $this->relationships($fixture, $identity)) {
                    $outcome = 'relationship_identity_changed';
                }
            }
            if ($outcome !== null) {
                $journal->append(['type' => 'skip', 'id' => $id, 'reason' => $outcome, 'at' => time()]);
                $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;

                continue;
            }
            $receivedAt = null;
            $fingerprint = null;
            $validObservation = false;
            $requestStarted = hrtime(true) / 1e9;
            try {
                $rows = $this->gateway->get('/fixtures', ['id' => $identity['api_fixture_id']], 'fixture_repair', StaleFixturePolicy::CALLER, fn () => $journal->claim($id));
                $receivedAt = microtime(true);
                $fingerprint = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
                $facts = $this->policy->response($rows, $identity);
                $validObservation = true;
                // A crash between intent/commit/outcome must leave enough evidence
                // to review the row; subsequent runs always re-read current state.
                $journal->append(['type' => 'intent', 'id' => $id, 'provider_id' => $identity['api_fixture_id'], 'before' => $snapshot, 'facts' => $facts, 'response_sha256' => $fingerprint, 'received_at' => $receivedAt]);
                $outcome = $facts['outcome'] === 'validated'
                    ? $this->apply($identity, $snapshot, $facts, $requestStarted, $journal)
                    : $facts['outcome'];
            } catch (ApiFootballLocalAttemptLimitReached) {
                $outcome = $stop = 'local_or_daily_limit';
            } catch (ApiFootballBlocked $e) {
                $outcome = $stop = 'blocked_'.$e->reason->value;
            } catch (ApiFootballRetryableFailure) {
                $outcome = 'transport_exhausted';
            } catch (ApiFootballProviderResponseException $e) {
                $outcome = $stop = 'provider_'.$e->classification;
            } catch (\UnexpectedValueException $e) {
                $outcome = $stop = 'invalid_provider_facts';
            } catch (Throwable $e) {
                // Do not continue after malformed envelope/identity, audit or DB failure.
                // No exception messages/payloads/credentials enter telemetry.
                $outcome = $stop = 'validation_audit_or_persistence_failure';
            }
            $observations = ($retry['observations'] ?? 0) + (int) $validObservation;
            $postponed = ($facts['fixture']['status_short'] ?? $snapshot['fixture']['status_short']) === 'PST';
            $delays = $postponed ? [86400, 259200, 604800] : [7200, 14400, 28800, 57600];
            $review = $observations >= count($delays);
            $journal->append(['type' => 'outcome', 'id' => $id, 'outcome' => $outcome, 'at' => time(), 'response_sha256' => $fingerprint,
                'attempts_run' => $journal->attempts(), 'retry' => ['observations' => $observations, 'next_at' => time() + ($validObservation ? $delays[min(max(0, $observations - 1), count($delays) - 1)] : 7200),
                    'review' => $review, 'disposition' => $review ? ($postponed ? 'postponed_unresolved' : 'needs_review') : 'pending']]);
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
            unset($facts);
            if ($stop !== null) {
                break;
            }
        }
        $summary = ['inspected' => $inspected, 'reserved_attempts' => $journal->attempts(), 'outcomes' => $outcomes, 'stop' => $stop];
        $journal->append(['type' => 'run', 'at' => time(), ...$summary]);

        return $summary;
    }

    /** Provider fetch precedes locks; retries reuse these facts, never re-fetch. */
    public function apply(array $identity, array $snapshot, array $facts, float $requestStarted, StaleFixtureJournal $journal): string
    {
        return DB::transaction(function () use ($identity, $snapshot, $facts, $requestStarted, $journal): string {
            $journal->guard();
            $fixture = Fixture::whereKey($identity['id'])->lockForUpdate()->first();
            $score = FixtureScore::where('fixture_id', $identity['id'])->lockForUpdate()->first();
            if (hrtime(true) / 1e9 - $requestStarted > 120) {
                return 'response_expired';
            }
            if (! $fixture || ! $score || $this->policy->snapshot($fixture, $score) !== $snapshot) {
                return 'concurrent_change';
            }
            if (($reason = $this->policy->eligibility($snapshot, $identity, CarbonImmutable::now('UTC'))) !== null) {
                return $reason;
            }
            if (! $this->relationships($fixture, $identity)) {
                return 'relationship_identity_changed';
            }
            $fixture->fill($facts['fixture']);
            $score->fill($facts['score']);
            if (! $fixture->isDirty() && ! $score->isDirty()) {
                return 'unchanged';
            }
            $journal->guard();
            // Related identity locks can also wait; freshness must hold at the write boundary.
            if (hrtime(true) / 1e9 - $requestStarted > 120) {
                return 'response_expired';
            }
            if ($fixture->isDirty()) {
                $fixture->save();
            }
            if ($score->isDirty()) {
                $score->save();
            }

            return 'updated_'.$facts['fixture']['status_short'];
        }, attempts: 3);
    }

    private function relationships(Fixture $fixture, array $identity): bool
    {
        if (DB::transactionLevel() > 0) {
            $fixture->load([
                'league' => fn ($query) => $query->lockForUpdate(),
                'homeTeam' => fn ($query) => $query->lockForUpdate(),
                'awayTeam' => fn ($query) => $query->lockForUpdate(),
            ]);
        }

        return $fixture->league?->sport === 'football'
            && (int) $fixture->league?->api_league_id === $identity['api_league_id']
            && (int) $fixture->homeTeam?->api_team_id === $identity['home_api_id']
            && (int) $fixture->awayTeam?->api_team_id === $identity['away_api_id'];
    }
}
