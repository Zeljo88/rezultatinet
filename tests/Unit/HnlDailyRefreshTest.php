<?php

namespace Tests\Unit;

use App\Contracts\ApiFootballQuotaStore;
use App\Services\ApiFootball\ApiFootballGateway;
use App\Services\HnlDailyPolicy;
use App\Services\HnlDailyRefresh;
use App\Services\HnlDailyValidator;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connectors\SQLiteConnector;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HnlDailyRefreshTest extends TestCase
{
    private Manager $db;

    private array $envelope;

    private array $baseline;

    private object $quota;

    private HnlDailyRefresh $service;

    private int $calls = 0;

    private array $initial;

    protected function setUp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-23 04:45:00', 'UTC'));
        $c = new class extends Container
        {
            public function runningUnitTests(): bool
            {
                return true;
            }
        };
        Container::setInstance($c);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($c);
        $c->instance('config', new Repository(['api_football' => ['enabled_sports' => ['football'], 'base_url' => 'https://provider.invalid', 'max_attempts' => 2, 'retry_base_ms' => 0], 'services' => ['api_football' => ['key' => '']]]));
        $file = getenv('HNL_EVIDENCE').'/daily-'.bin2hex(random_bytes(6)).'.sqlite';
        touch($file);
        $this->db = new Manager($c);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => $file, 'foreign_key_constraints' => true]);
        $this->db->setEventDispatcher(new Dispatcher($c));
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        $c->instance('db', $this->db->getDatabaseManager());
        $c->bind('db.schema', fn () => DB::connection()->getSchemaBuilder());
        $this->baseline = json_decode(file_get_contents(HnlDailyPolicy::BASELINE), true);
        $schema = $this->db->schema();
        $schema->create('standings', function (Blueprint $t) {
            foreach ($this->baseline['current'][0] as $k => $v) {
                if ($k === 'id') {
                    $t->integer($k)->primary();
                } elseif (is_int($v)) {
                    $t->integer($k);
                } else {
                    $t->string($k)->nullable();
                }
            }$t->unique(['league_id', 'team_id', 'season']);
        });
        $schema->create('leagues', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->integer('api_league_id');
            $t->string('sport');
            $t->string('country');
        });
        $schema->create('teams', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->integer('api_team_id');
        });
        $schema->create('fixtures', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->integer('league_id');
            $t->integer('season');
            $t->dateTime('kick_off');
        });
        $schema->create('fixture_scores', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->integer('score');
        });
        (require getcwd().'/deployment/hnl-daily/2026_10_22_000001_create_hnl_standings_runs.php')->up();
        DB::table('standings')->insert(array_merge($this->baseline['current'], $this->baseline['archive']));
        $other = $this->baseline['current'][0];
        $other['id'] = 999;
        $other['league_id'] = 999;
        DB::table('standings')->insert($other);
        DB::table('leagues')->insert(['id' => 835, 'api_league_id' => 210, 'sport' => 'football', 'country' => 'Croatia']);
        foreach (HnlDailyValidator::TEAMS as $api => $local) {
            DB::table('teams')->insert(['id' => $local, 'api_team_id' => $api]);
        }
        DB::table('fixtures')->insert(['id' => 1, 'league_id' => 835, 'season' => 2026, 'kick_off' => '2026-10-18 15:00:00']);
        DB::table('fixture_scores')->insert(['id' => 1, 'score' => 3]);
        $log = Mockery::mock();
        $log->shouldReceive('channel')->andReturnSelf();
        $log->shouldReceive('info', 'log')->andReturnNull();
        Log::swap($log);
        $cache = Mockery::mock();
        $cache->shouldReceive('store')->andReturnSelf();
        $cache->shouldReceive('add')->andReturnTrue();
        Cache::swap($cache);
        $this->quota = Mockery::mock(ApiFootballQuotaStore::class);
        $this->quota->shouldReceive('record', 'openCircuit')->andReturnNull();
        $this->service = new HnlDailyRefresh(new ApiFootballGateway($this->quota));
        $this->envelope = json_decode(file_get_contents(getcwd().'/tests/Fixtures/hnl-oct22-envelope.json'), true);
        $this->initial = $this->snapshot();
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        Carbon::setTestNow();
        Mockery::close();
        Facade::clearResolvedInstances();
    }

    private function snapshot(): array
    {
        $v = [];
        foreach (['standings', 'leagues', 'teams', 'fixtures', 'fixture_scores'] as $t) {
            $v[$t] = DB::table($t)->orderBy('id')->get()->toJson();
        }

return $v;
    }

    private function http(int $status = 200, mixed $body = null, bool $deny = false): void
    {
        $this->quota->shouldReceive('reserve')->with('standings', 'HnlStandingsRefresh')->andReturn(['allowed' => ! $deny, 'reason' => 'circuit']);
        $factory = new Factory;
        $factory->preventStrayRequests();
        $factory->fake(function ($request, $options) use ($status, $body) {
            $this->calls++;
            self::assertFalse($options['allow_redirects']);
            self::assertSame(15, $options['timeout']);
            if ($status === 0) {
                throw new ConnectionException('test');
            }

return Factory::response($body ?? $this->envelope, $status, ['Location' => 'https://no-follow.invalid']);
        });
        Http::swap($factory);
    }

    private function execute(?callable $guard = null): array
    {
        return (new \ReflectionMethod($this->service, 'execute'))->invoke($this->service, $guard ?? static function () {});
    }

    public function test_default_policy_refuses_without_claim(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->run();
    }

    public function test_unchanged_commits_marker_without_touching_rows_and_duplicate_restart_never_fetches(): void
    {
        $this->http();
        $r = $this->execute();
        self::assertSame('committed', $r['outcome'], json_encode($r));
        self::assertSame(0, $r['changed_rows']);
        self::assertSame($this->initial, $this->snapshot());
        $this->service = new HnlDailyRefresh(new ApiFootballGateway($this->quota));
        self::assertSame('slot_consumed', $this->execute()['outcome']);
        self::assertSame(1, $this->calls);
        $s = $this->service->status();
        self::assertFalse($s['stale_over_30h']);
        self::assertNotNull($s['last_validated_committed_at']);
        self::assertSame(1, $s['physical_observed']);
    }

    public function test_changed_then_next_day_no_change_preserves_ids_created_at_archive_and_references(): void
    {
        foreach ($this->envelope['response'][0]['league']['standings'][0] as &$r) {
            $r['update'] = '2026-10-22T04:00:00+00:00';
        }unset($r);
        $this->envelope['response'][0]['league']['standings'][0][0]['form'] = 'DDDDD';
        $this->http();
        $r = $this->execute();
        self::assertSame('committed', $r['outcome'], json_encode($r));
        self::assertSame(1, $r['changed_rows']);
        $rows = DB::table('standings')->where('season', 2026)->where('league_id', 835)->orderBy('id')->get();
        self::assertSame(range(538, 547), $rows->pluck('id')->all());
        foreach ($rows as $row) {
            self::assertSame('2026-10-22 12:50:29', $row->created_at);
        }
        foreach (['leagues', 'teams', 'fixtures', 'fixture_scores'] as $t) {
            self::assertSame($this->initial[$t], $this->snapshot()[$t]);
        }
        $post = $this->snapshot();
        Carbon::setTestNow(Carbon::parse('2026-10-24 04:45:00', 'UTC'));
        $r = $this->execute();
        self::assertSame('committed', $r['outcome'], json_encode($r));
        self::assertSame(0, $r['changed_rows']);
        self::assertSame($post, $this->snapshot());
        self::assertSame(2, $this->calls);
    }

    #[DataProvider('badResponses')]
    public function test_failed_response_consumes_daily_slot_preserves_all_data(int $status, string $mutation): void
    {
        $body = $this->envelope;
        switch ($mutation) {
            case 'missing':array_pop($body['response'][0]['league']['standings'][0]);
                break;
            case 'league':$body['response'][0]['league']['id'] = 211;
                break;
            case 'season':$body['response'][0]['league']['season'] = 2025;
                break;
            case 'partial':$body['paging']['total'] = 2;
                break;
            case 'venue':$body['response'][0]['league']['standings'][0][0]['home']['win']++;
                break;
            case 'regress':$body['response'][0]['league']['standings'][0][0]['update'] = '2026-01-01T00:00:00Z';
                break;
            case 'same-time-change':$body['response'][0]['league']['standings'][0][0]['form'] = 'DDDDD';
                break;
            case 'malformed':$body = 'not json';
                break;
            case 'oversize':$body = str_repeat('x', 131073);
                break;
        }
        $this->http($status, $body);
        self::assertSame('failed', $this->execute()['outcome']);
        self::assertSame($this->initial, $this->snapshot());
        self::assertSame('slot_consumed', $this->execute()['outcome']);
        self::assertSame(1, $this->calls);
        self::assertNull($this->service->status()['last_validated_committed_at']);
    }

    public static function badResponses(): array
    {
        return ['missing' => [200, 'missing'], 'league' => [200, 'league'], 'season' => [200, 'season'], 'partial' => [200, 'partial'], 'venue' => [200, 'venue'], 'regression' => [200, 'regress'], 'same-vector' => [200, 'same-time-change'], 'malformed' => [200, 'malformed'], 'oversize' => [200, 'oversize'], 'redirect' => [302, ''], 'server' => [500, ''], 'rate' => [429, ''], 'network' => [0, '']];
    }

    #[DataProvider('drifts')]
    public function test_preflight_drift_refuses_before_network(string $drift): void
    {
        if ($drift === 'missing') {
            DB::table('standings')->where('id', 538)->delete();
        }
        if ($drift === 'archive') {
            DB::table('standings')->where('season', 2025)->update(['points' => 99]);
        }
        if ($drift === 'current') {
            DB::table('standings')->where('id', 538)->update(['updated_at' => '2026-10-22 15:00:00']);
        }
        if ($drift === 'team') {
            DB::table('teams')->where('id', 1507)->update(['api_team_id' => 999]);
        }
        if ($drift === 'league') {
            DB::table('leagues')->update(['country' => 'Other']);
        }
        if ($drift === 'season') {
            DB::table('fixtures')->update(['season' => 2027]);
        }
        $before = $this->snapshot();
        $this->http();
        self::assertSame('failed', $this->execute()['outcome']);
        self::assertSame(0, $this->calls);
        self::assertSame($before, $this->snapshot());
    }

    public static function drifts(): array
    {
        return array_map(fn ($x) => [$x], ['missing', 'archive', 'current', 'team', 'league', 'season']);
    }

    public function test_quota_denial_zero_observed_but_claim_spent(): void
    {
        $this->http(200, null, true);
        self::assertSame('failed', $this->execute()['outcome']);
        self::assertSame(0, $this->calls);
        self::assertSame(0, $this->service->status()['physical_observed']);
        self::assertSame(1, $this->service->status()['reserved_ceiling']);
    }

    public function test_midwrite_failure_rolls_back_but_not_claim(): void
    {
        foreach ($this->envelope['response'][0]['league']['standings'][0] as &$r) {
            $r['update'] = '2026-10-22T04:00:00Z';
            $r['form'] = 'DDDDD';
        }unset($r);
        DB::unprepared("CREATE TRIGGER fail_second BEFORE UPDATE ON standings WHEN NEW.id=539 BEGIN SELECT RAISE(ABORT,'test'); END");
        $this->http();
        self::assertSame('failed', $this->execute()['outcome']);
        self::assertSame($this->initial, $this->snapshot());
        self::assertSame(1, $this->calls);
    }

    public function test_lease_loss_after_response_rolls_back_without_refetch(): void
    {
        $this->http();
        $calls = 0;
        $r = $this->execute(function () use (&$calls) {
            if (++$calls >= 3) {
                throw new \RuntimeException('lost');
            }
        });
        self::assertSame('failed', $r['outcome']);
        self::assertSame($this->initial, $this->snapshot());
        self::assertSame(1, $this->calls);
    }

    public function test_unresolved_claim_pauses_next_day(): void
    {
        $this->http();
        DB::table('hnl_standings_runs')->insert(['id' => 'old', 'league_id' => 835, 'season' => 2026, 'policy' => 'v1', 'utc_day' => '2026-10-22', 'outcome' => 'claimed', 'attempted_at' => '2026-10-22 04:45:00']);
        self::assertSame('failed', $this->execute()['outcome']);
        self::assertSame(0, $this->calls);
    }

    public function test_utc_day_boundary_refuses_old_slot_before_network(): void
    {
        $this->http();
        $r = $this->execute(function () {
            Carbon::setTestNow(Carbon::parse('2026-10-24 00:00:00', 'UTC'));
        });
        self::assertSame('failed', $r['outcome']);
        self::assertSame(0, $this->calls);
    }

    public function test_receipt_reconciliation_and_staleness_do_not_replay(): void
    {
        $this->http();
        $r = $this->execute();
        DB::table('hnl_standings_runs')->where('id', $r['run_id'])->update(['commit_observed_at' => null]);
        self::assertSame('committed', $this->service->reconcile($r['run_id'])['outcome']);
        self::assertSame(1, $this->calls);
        Carbon::setTestNow(Carbon::parse('2026-10-24 10:45:01', 'UTC'));
        self::assertTrue($this->service->status()['stale_over_30h']);
    }

    public function test_unknown_reconciliation_never_claims_rollback(): void
    {
        self::assertSame('commit_unknown', $this->service->reconcile('unknown')['outcome']);
        self::assertSame(0, $this->calls);
    }

    public function test_commit_acknowledgement_loss_reconciles_committed_marker_on_new_connection(): void
    {
        $config = DB::connection()->getConfig();
        DB::purge();
        DB::extend('sqlite', static function ($config, $name) {
            $pdo = (new SQLiteConnector)->connect($config);

            return new class($pdo, $config['database'], '', $config) extends SQLiteConnection
            {
                public static bool $lost = false;

                public function commit()
                {
                    parent::commit();
                    if (! self::$lost) {
                        self::$lost = true;
                        throw new \RuntimeException('ack_lost');
                    }
                }
            };
        });
        $this->http();
        $r = $this->execute();
        self::assertSame('committed', $r['outcome'], json_encode($r));
        self::assertSame(1, $this->calls);
        self::assertSame($this->initial, $this->snapshot());
    }

    public function test_after_commit_receipt_write_failure_does_not_lie_about_rollback(): void
    {
        DB::unprepared("CREATE TRIGGER fail_receipt BEFORE UPDATE ON hnl_standings_runs WHEN NEW.commit_observed_at IS NOT NULL BEGIN SELECT RAISE(ABORT,'receipt'); END");
        $this->http();
        $r = $this->execute();
        self::assertSame('committed_receipt_unavailable', $r['outcome']);
        self::assertNotNull($this->service->status()['last_validated_committed_at']);
        self::assertSame(1, $this->calls);
    }

    public function test_success_marker_failure_rolls_back_standings(): void
    {
        foreach ($this->envelope['response'][0]['league']['standings'][0] as &$r) {
            $r['update'] = '2026-10-22T04:00:00Z';
            $r['form'] = 'DDDDD';
        }unset($r);
        DB::unprepared("CREATE TRIGGER fail_marker BEFORE UPDATE ON hnl_standings_runs WHEN NEW.outcome='committed' BEGIN SELECT RAISE(ABORT,'marker'); END");
        $this->http();
        self::assertSame('failed', $this->execute()['outcome']);
        self::assertSame($this->initial, $this->snapshot());
        self::assertSame(1, $this->calls);
    }

    public function test_readback_drift_is_unknown_not_automatic_compensation(): void
    {
        $this->http();
        $r = $this->execute();
        DB::table('standings')->where('id', 538)->update(['points' => 99]);
        self::assertSame('commit_unknown', $this->service->reconcile($r['run_id'])['outcome']);
        self::assertSame(99, DB::table('standings')->where('id', 538)->value('points'));
        self::assertSame(1, $this->calls);
    }

    public function test_calendar_2027_never_selects_provider_2027(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-04 04:45:00', 'UTC'));
        DB::table('fixtures')->update(['kick_off' => '2027-01-03 15:00:00']);
        $this->http();
        self::assertSame('committed', $this->execute()['outcome']);
        self::assertSame(2026, DB::table('hnl_standings_runs')->value('season'));
        self::assertSame(1, $this->calls);
    }

    public function test_dst_display_does_not_change_utc_slot(): void
    {
        self::assertSame('06:45', Carbon::parse('2026-10-24 04:45:00', 'UTC')->setTimezone('Europe/Zagreb')->format('H:i'));
        self::assertSame('05:45', Carbon::parse('2026-10-26 04:45:00', 'UTC')->setTimezone('Europe/Zagreb')->format('H:i'));
        self::assertSame('2026-10-23', Carbon::now('UTC')->format('Y-m-d'));
    }

    public function test_real_sqlite_second_connection_cannot_duplicate_durable_slot(): void
    {
        $this->http();
        $r = $this->execute();
        $connection = DB::connectUsing('second_slot', DB::connection()->getConfig(), true);
        $record = (array) $connection->table('hnl_standings_runs')->first();
        $record['id'] = 'second';
        try {
            $connection->table('hnl_standings_runs')->insert($record);
            self::fail('duplicate accepted');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, $connection->table('hnl_standings_runs')->count());
        } finally {
            DB::purge('second_slot');
        }
        self::assertSame(1, $this->calls);
    }

    #[DataProvider('malformedContracts')]
    public function test_strict_full_envelope_variants(string $case): void
    {
        $p = $this->envelope;
        $r = &$p['response'][0]['league']['standings'][0][0];
        switch ($case) {
            case 'null':$p = null;
                break;
            case 'empty':$p = [];
                break;
            case 'errors':$p['errors'] = ['x' => 'bad'];
                break;
            case 'results':$p['results'] = 10;
                break;
            case 'get':$p['get'] = 'fixtures';
                break;
            case 'parameters':$p['parameters']['season'] = '2027';
                break;
            case 'group':$r['group'] = 'playoff';
                break;
            case 'rank':$r['rank'] = 2;
                break;
            case 'foreign':$r['team']['id'] = 999;
                break;
            case 'duplicate':$r['team']['id'] = 620;
                break;
            case 'integer':$r['points'] = '22';
                break;
            case 'negative':$r['points'] = -1;
                break;
            case 'deduction':$r['points'] = 21;
                break;
            case 'played':$r['all']['played']++;
                break;
            case 'goaldiff':$r['goalsDiff']++;
                break;
            case 'form':$r['form'] = 'XYZ';
                break;
            case 'timestamp':$r['update'] = 'yesterday';
                break;
            case 'future':$r['update'] = '2099-01-01T00:00:00Z';
                break;
            case 'extra-group':$p['response'][0]['league']['standings'][] = [];
                break;
            case 'missing-away':unset($r['away']);
                break;
        }
        $this->http(200, $p === null ? 'null' : $p);
        self::assertSame('failed', $this->execute()['outcome']);
        self::assertSame($this->initial, $this->snapshot());
        self::assertSame(1, $this->calls);
    }

    public static function malformedContracts(): array
    {
        return array_map(fn ($v) => [$v], ['null', 'empty', 'errors', 'results', 'get', 'parameters', 'group', 'rank', 'foreign', 'duplicate', 'integer', 'negative', 'deduction', 'played', 'goaldiff', 'form', 'timestamp', 'future', 'extra-group', 'missing-away']);
    }

    public function test_two_failed_slots_pause_before_fetch(): void
    {
        foreach (['2026-10-21', '2026-10-22'] as $day) {
            DB::table('hnl_standings_runs')->insert(['id' => $day, 'league_id' => 835, 'utc_day' => $day, 'season' => 2026, 'policy' => 'v1', 'outcome' => 'failed', 'attempted_at' => $day.' 04:45:00']);
        }
        $this->http();
        $r = $this->execute();
        self::assertSame('failed', $r['outcome']);
        self::assertSame('hnl_daily_two_failed_slots_pause', $r['reason']);
        self::assertSame(0, $this->calls);
    }

    public function test_durable_claim_insert_failure_never_fetches(): void
    {
        DB::unprepared("CREATE TRIGGER fail_claim BEFORE INSERT ON hnl_standings_runs BEGIN SELECT RAISE(ABORT,'claim'); END");
        $this->http();
        try {
            $this->execute();
            self::fail('claim succeeded');
        } catch (QueryException) {
            self::assertSame(0, $this->calls);
            self::assertSame($this->initial, $this->snapshot());
        }
    }

    public function test_midnight_after_quota_reservation_is_blocked_before_physical_request(): void
    {
        $this->quota->shouldReceive('reserve')->andReturnUsing(function () {
            Carbon::setTestNow(Carbon::parse('2026-10-24 00:00:00','UTC'));

            return ['allowed' => true];
        });
        $this->http();
        self::assertSame('failed',$this->execute()['outcome']);
        self::assertSame(0,$this->calls);
        self::assertSame($this->initial,$this->snapshot());
    }
}
