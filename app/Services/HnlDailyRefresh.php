<?php

namespace App\Services;

use App\Exceptions\ApiFootballBlocked;
use App\Services\ApiFootball\ApiFootballGateway;
use App\Support\ApiFootballBlockReason;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Cooperating-writer contract only; never a fence against arbitrary SQL writers. */
final class HnlDailyRefresh
{
    public function __construct(private readonly ApiFootballGateway $gateway) {}

    public function run(): array
    {
        HnlDailyPolicy::admission(now('UTC')->timestamp);
        $lock = Cache::store('redis')->lock('standings:writer:league:835', 120);
        if (! $lock->get()) {
            return ['outcome' => 'writer_busy'];
        }
        $start = hrtime(true);
        try {
            return $this->execute(function () use ($lock, $start): void {
                self::check(hrtime(true) - $start < 60_000_000_000 && $lock->isOwnedByCurrentProcess(), 'lease_or_deadline');
            });
        } finally {
            $lock->release();
        }
    }

    /** Internal core; tests invoke reflectively, never a CLI bypass for policy admission. */
    private function execute(callable $guard): array
    {
        self::check(DB::transactionLevel() === 0, 'outer_transaction');
        $day = now('UTC')->format('Y-m-d');
        $id = (string) Str::uuid();
        // Autocommit durable unique claim BEFORE any provider reservation. Never reset/delete it.
        try {
            DB::table('hnl_standings_runs')->insert(['id' => $id, 'league_id' => 835, 'utc_day' => $day,
                'season' => 2026, 'policy' => HnlDailyPolicy::VERSION, 'outcome' => 'claimed',
                'attempted_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'reserved_ceiling' => 1]);
        } catch (Throwable $e) {
            if (DB::table('hnl_standings_runs')->where('league_id', 835)->where('utc_day', $day)->exists()) {
                return ['outcome' => 'slot_consumed'];
            }
            throw $e; // Unknown claim acknowledgement: no network, no retry.
        }
        $commitStarted = false;
        $physical = 0;
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
            }
            DB::beginTransaction();
            $league = DB::table('leagues')->where('id', 835)->lockForUpdate()->first();
            self::check($league && (int) $league->api_league_id === 210 && $league->sport === 'football' && $league->country === 'Croatia', 'league_identity');
            $indexes = DB::connection()->getSchemaBuilder()->getIndexes('standings');
            self::check(count(array_filter($indexes, fn ($index) => $index['unique'] && $index['columns'] === ['league_id', 'team_id', 'season'])) === 1, 'unique_identity_index');
            $before = $this->standingImage();
            $teams = DB::table('teams')->whereIn('id', array_values(HnlDailyValidator::TEAMS))->orderBy('id')->lockForUpdate()->get();
            self::check(count($teams) === 10, 'team_membership');
            foreach ($teams as $team) {
                self::check((HnlDailyValidator::TEAMS[(int) $team->api_team_id] ?? null) === (int) $team->id, 'team_identity');
            }
            $identities = json_encode([$league, $teams], JSON_THROW_ON_ERROR);
            // A previously ambiguous attempt pauses all later days. Ordinary failed slots remain spent.
            self::check(! DB::table('hnl_standings_runs')->where('league_id', 835)->where('id', '!=', $id)->whereIn('outcome', ['claimed', 'commit_unknown'])->exists(), 'unresolved_run');
            $recent = DB::table('hnl_standings_runs')->where('league_id', 835)->where('id', '!=', $id)->orderByDesc('utc_day')->limit(2)->pluck('outcome')->all();
            self::check($recent !== ['failed', 'failed'], 'two_failed_slots_pause');
            $last = DB::table('hnl_standings_runs')->where('league_id', 835)->where('outcome', 'committed')->orderByDesc('utc_day')->first();
            $baseline = json_decode(file_get_contents(HnlDailyPolicy::BASELINE), true, 512, JSON_THROW_ON_ERROR);
            $expected = $last ? json_decode($last->postimage, true, 512, JSON_THROW_ON_ERROR) : array_merge($baseline['current'], $baseline['archive']);
            self::check(self::canonical($before) === self::canonical($expected), 'full_postimage_cas');
            $current = array_values(array_filter($before, fn ($r) => (int) $r['season'] === 2026));
            $archive = array_values(array_filter($before, fn ($r) => (int) $r['season'] !== 2026));
            self::check(count($current) === 10 && count(array_unique(array_column($current, 'team_id'))) === 10, 'current_membership');
            self::check(self::canonical($archive) === self::canonical($baseline['archive']), 'archive_baseline');
            $season = DB::table('fixtures')->where('league_id', 835)->where('kick_off', '<=', now('UTC'))->where('kick_off', '>=', now('UTC')->subDays(45))->max('season');
            self::check((int) $season === 2026, 'recent_season');
            $guard();
            self::check(now('UTC')->format('Y-m-d') === $day, 'utc_slot_changed');
            $validated = null;
            $envelope = null;
            $attempts = 0;
            $this->gateway->get('/standings', ['league' => 210, 'season' => 2026], 'standings', 'HnlStandingsRefresh',
                function () use (&$attempts, $guard, $day, &$physical): bool {
                    $guard();
                    self::check(now('UTC')->format('Y-m-d') === $day, 'utc_slot_changed');
                    $physical = null;

                    return ++$attempts === 1;
                }, function ($full) use (&$validated, &$envelope): void {
                    $validated = (new HnlDailyValidator)->validate($full);
                    $envelope = $full;
                }, true);
            $physical = 1;
            self::check($validated !== null, 'no_valid_response');
            $vector = [];
            foreach ($envelope['response'][0]['league']['standings'][0] as $r) {
                $vector[$r['team']['id']] = $r['update'];
            }
            $oldVector = $last ? json_decode($last->provider_vector, true, 512, JSON_THROW_ON_ERROR) : $baseline['vector'];
            $changed = 0;
            $changedAt = now('UTC')->format('Y-m-d H:i:s');
            foreach ($current as $row) {
                $team = (int) $row['team_id'];
                $api = array_search($team, HnlDailyValidator::TEAMS, true);
                self::check(isset($vector[$api],$oldVector[$api]) && strtotime($vector[$api]) >= strtotime($oldVector[$api]), 'provider_time_regression');
                $values = $validated['rows'][$team] ?? null;
                self::check(is_array($values), 'missing_team');
                $dirty = false;
                foreach ($values as $key => $value) {
                    if ($row[$key] !== $value) {
                        $dirty = true;
                    }
                }
                if (! $dirty) {
                    continue;
                }
                self::check(strtotime($vector[$api]) > strtotime($oldVector[$api]), 'changed_same_provider_time');
                $guard();
                $updated = DB::table('standings')->where('id', $row['id'])->where('league_id', 835)->where('team_id', $team)->where('season', 2026)
                    ->update($values + ['updated_at' => $changedAt]);
                self::check($updated === 1, 'update_count');
                $changed++;
            }
            $post = $this->standingImage();
            self::check(self::canonical(array_values(array_filter($post, fn ($r) => (int) $r['season'] !== 2026))) === self::canonical($archive), 'archive_changed');
            // Exact expected full postimage, preserving PK, created_at and every unmodified column.
            foreach ($before as &$row) {
                if ((int) $row['season'] !== 2026) {
                    continue;
                }
                $values = $validated['rows'][(int) $row['team_id']];
                $dirty = false;
                foreach ($values as $k => $v) {
                    if ($row[$k] !== $v) {
                        $dirty = true;
                    }
                }
                if ($dirty) {
                    $row = array_replace($row, $values, ['updated_at' => $changedAt]);
                }
            }
            unset($row);
            self::check(self::canonical($post) === self::canonical($before), 'postimage_mismatch');
            self::check($identities === json_encode([DB::table('leagues')->where('id', 835)->lockForUpdate()->first(), DB::table('teams')->whereIn('id', array_values(HnlDailyValidator::TEAMS))->orderBy('id')->lockForUpdate()->get()], JSON_THROW_ON_ERROR), 'identity_changed');
            $guard();
            $marked = DB::table('hnl_standings_runs')->where('id', $id)->where('outcome', 'claimed')->update([
                'outcome' => 'committed', 'validated_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'physical_observed' => 1,
                'predecessor_id' => $last?->id, 'payload_sha256' => $validated['payload_sha256'], 'changed_rows' => $changed,
                'before_image' => json_encode($expected, JSON_THROW_ON_ERROR), 'postimage' => json_encode($post, JSON_THROW_ON_ERROR),
                'provider_vector' => json_encode($vector, JSON_THROW_ON_ERROR)]);
            self::check($marked === 1, 'success_marker_count');
            $guard();
            $commitStarted = true;
            DB::commit();
        } catch (Throwable $e) {
            if (! $commitStarted) {
                try {
                    DB::rollBack();
                } catch (Throwable) {
                    return ['outcome' => 'commit_unknown', 'run_id' => $id];
                }
                // Only explicit pre-network denials prove zero. Other failures remain unknown.
                if ($e instanceof ApiFootballBlocked && $e->reason !== ApiFootballBlockReason::ProviderRateLimited) {
                    $physical = 0;
                }
                try {
                    DB::table('hnl_standings_runs')->where('id', $id)->where('outcome', 'claimed')->update(['outcome' => 'failed', 'physical_observed' => $physical, 'reason' => str_starts_with($e->getMessage(), 'hnl_') ? substr($e->getMessage(), 0, 96) : substr(get_class($e), 0, 96)]);
                } catch (Throwable) {
                }

                return ['outcome' => 'failed', 'run_id' => $id, 'reason' => str_starts_with($e->getMessage(), 'hnl_') ? $e->getMessage() : get_class($e)];
            }
            // Commit may have happened. Close the uncertain session, then independently read back.
            // Never issue a compensating write or retry the provider.
            DB::disconnect();
        }

        return $this->reconcile($id);
    }

    public function reconcile(string $id): array
    {
        $name = 'hnl_daily_readback_'.str_replace('-', '', $id);
        $proven = false;
        try {
            $connection = DB::connectUsing($name, DB::connection()->getConfig(), true);
            $row = $connection->transaction(function () use ($connection, $id) {
                $row = $connection->table('hnl_standings_runs')->where('id', $id)->first();
                if (! $row || $row->outcome !== 'committed') {
                    return null;
                }
                $latest = $connection->table('hnl_standings_runs')->where('league_id', 835)->where('outcome', 'committed')->orderByDesc('utc_day')->first();
                if ($latest->id === $id) {
                    $actual = $connection->table('standings')->where('league_id', 835)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
                    self::check(self::canonical($actual) === self::canonical(json_decode($row->postimage, true, 512, JSON_THROW_ON_ERROR)), 'readback_postimage');
                }

                return $row;
            }, 1);
            if (! $row) {
                return ['outcome' => 'commit_unknown', 'run_id' => $id];
            }
            $proven = true;
            $connection->table('hnl_standings_runs')->where('id', $id)->update(['commit_observed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);

            return ['outcome' => 'committed', 'run_id' => $id, 'changed_rows' => $row->changed_rows];
        } catch (Throwable) {
            return ['outcome' => $proven ? 'committed_receipt_unavailable' : 'commit_unknown', 'run_id' => $id];
        } finally {
            DB::purge($name);
        }
    }

    public function status(): array
    {
        $latest = DB::table('hnl_standings_runs')->where('league_id', 835)->orderByDesc('utc_day')->first();
        $success = DB::table('hnl_standings_runs')->where('league_id', 835)->where('outcome', 'committed')->orderByDesc('utc_day')->first();

        return ['enabled' => HnlDailyPolicy::ENABLED, 'expiry' => HnlDailyPolicy::EXPIRES_AT,
            'last_attempted_utc' => $latest?->attempted_at, 'latest_outcome' => $latest?->outcome, 'latest_reason' => $latest?->reason,
            'reserved_ceiling' => $latest?->reserved_ceiling, 'physical_observed' => $latest?->physical_observed,
            'last_validated_committed_at' => $success?->validated_at, 'commit_observed_at' => $success?->commit_observed_at,
            'stale_over_30h' => ! $success || now('UTC')->timestamp - strtotime($success->validated_at.' UTC') > 30 * 3600,
            'provider_vector' => $success ? json_decode($success->provider_vector, true) : null,
            'provider_content_age_seconds' => $success ? now('UTC')->timestamp - min(array_map('strtotime', json_decode($success->provider_vector, true))) : null,
            'baseline' => '2026-10-22 12:50:29 UTC snapshot; not a daily check'];
    }

    private function standingImage(): array
    {
        return DB::table('standings')->where('league_id', 835)->orderBy('team_id')->orderBy('season')->orderBy('id')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
    }

    public static function canonical(array $rows): string
    {
        usort($rows,fn ($a,$b) => $a['id'] <=> $b['id']);
        foreach ($rows as &$row) {
            ksort($row);
        } unset($row);

        return json_encode($rows,JSON_THROW_ON_ERROR);
    }

    private static function check(bool $ok,string $reason): void
    {
        if (! $ok) {
            throw new RuntimeException('hnl_daily_'.$reason);
        }
    }
}
