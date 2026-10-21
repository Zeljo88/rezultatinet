<?php

namespace Tests\Unit;

use App\Console\Commands\SyncStandings;
use App\Contracts\ApiFootballQuotaStore;
use App\Services\ApiFootball\ApiFootballGateway;
use App\Services\HnlStandingsSync;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class HnlStandingsSyncTest extends TestCase
{
    private Manager $db;

    private array $receipts = [];

    private array $initial;

    private $quota;

    protected function setUp(): void
    {
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
        $c->instance('config', new Repository(['api_football' => ['enabled_sports' => ['football'],
            'base_url' => 'https://provider.invalid', 'max_attempts' => 2, 'retry_base_ms' => 0],
            'services' => ['api_football' => ['key' => '']]]));
        $this->db = new Manager($c);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
        $this->db->setEventDispatcher(new Dispatcher($c));
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        $c->instance('db', $this->db->getDatabaseManager());
        $schema = $this->db->schema();
        $schema->create('leagues', function (Blueprint $t) {
            $t->id();
            $t->integer('api_league_id')->unique();
            $t->string('sport');
            $t->string('country');
            $t->timestamps();
        });
        $schema->create('teams', function (Blueprint $t) {
            $t->id();
            $t->integer('api_team_id')->unique();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('logo_url')->nullable();
            $t->timestamps();
        });
        $schema->create('fixtures', function (Blueprint $t) {
            $t->id();
            $t->integer('league_id');
            $t->integer('season');
            $t->dateTime('kick_off');
        });
        $schema->create('standings', function (Blueprint $t) {
            $t->id();
            $t->integer('league_id');
            $t->integer('team_id');
            $t->integer('season');
            foreach (['rank', 'played', 'win', 'draw', 'lose', 'goals_for', 'goals_against', 'goal_diff', 'points'] as $field) {
                $t->integer($field)->default(0);
            }
            foreach (['home', 'away'] as $scope) {
                foreach (['played', 'win', 'draw', 'lose', 'goals_for', 'goals_against'] as $field) {
                    $t->integer($scope.'_'.$field)->default(0);
                }
            }
            $t->string('form')->nullable();
            $t->string('description')->nullable();
            $t->timestamps();
            $t->unique(['league_id', 'team_id', 'season']);
        });
        DB::table('leagues')->insert(['id' => 835, 'api_league_id' => 210, 'sport' => 'football', 'country' => 'Croatia']);
        DB::table('fixtures')->insert(['id' => 1, 'league_id' => 835, 'season' => 2026, 'kick_off' => now('UTC')->subDay()]);
        foreach (HnlStandingsSync::TEAMS as $api => $local) {
            DB::table('teams')->insert(['id' => $local, 'api_team_id' => $api, 'name' => 'Existing name '.$api, 'slug' => null]);
            DB::table('standings')->insert(['league_id' => 835, 'team_id' => $local, 'season' => 2025, 'points' => 77]);
        }
        DB::table('standings')->insert(['league_id' => 999, 'team_id' => 1447, 'season' => 2026, 'points' => 88]);
        $this->initial = $this->snapshot();
        $log = Mockery::mock();
        $log->shouldReceive('channel')->andReturnSelf();
        $log->shouldReceive('info')->andReturnUsing(function ($event, $data) {
            if ($event === 'hnl_standings_committed') {
                self::assertSame(0, DB::transactionLevel());
                self::assertSame(10, DB::table('standings')->where('league_id', 835)->where('season', 2026)->count());
                $this->receipts[] = $data;
            }
        });
        Log::swap($log);
        $this->quota = Mockery::mock(ApiFootballQuotaStore::class);
        $this->quota->shouldReceive('reserve')->andReturn(['allowed' => true]);
        $this->quota->shouldReceive('record')->andReturnNull();
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->db->getDatabaseManager()->disconnect();
        Mockery::close();
        Facade::clearResolvedInstances();
    }

    private function payload(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/hnl-standings-2026.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function sync(array $envelope): array
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request, $options) use ($envelope) {
            self::assertSame('https://provider.invalid/standings?league=210&season=2026', $request->url());
            self::assertFalse($options['allow_redirects']);

            return Http::response($envelope, 200);
        });

        return (new HnlStandingsSync(new ApiFootballGateway($this->quota)))->sync();
    }

    private function snapshot(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toArray(), ['leagues', 'teams', 'fixtures', 'standings']);
    }

    public function test_complete_retained_payload_history_idempotency_and_committed_receipt(): void
    {
        $r = $this->sync($this->payload());
        self::assertSame('committed', $r['outcome']);
        self::assertSame(10, $r['changed_rows']);
        self::assertSame([608, 620, 1483, 616, 561, 1016, 1017, 1018, 1019, 1068], $r['ordered_provider_team_ids']);
        $after = $this->snapshot();
        self::assertEquals(array_slice($this->initial, 0, 3), array_slice($after, 0, 3));
        self::assertEquals($this->initial[3], array_slice($after[3], 0, 11));
        $r = $this->sync($this->payload());
        self::assertSame(0, $r['changed_rows']);
        self::assertEquals($after, $this->snapshot());
        self::assertCount(2, $this->receipts);
    }

    #[DataProvider('invalidCases')]
    public function test_invalid_envelope_never_erases_current_or_history(string $case): void
    {
        $this->sync($this->payload());
        $before = $this->snapshot();
        $this->receipts = [];
        $e = $this->payload();
        $rows = &$e['response'][0]['league']['standings'][0];
        switch ($case) {
            case 'empty':$e['results'] = 0;
                $e['response'] = [];
                break;
            case 'error':$e['errors'] = ['x' => 'error'];
                break;
            case 'missing errors':unset($e['errors']);
                break;
            case 'bad results':$e['results'] = '1';
                break;
            case 'partial page':$e['paging']['total'] = 2;
                break;
            case 'wrong season':$e['response'][0]['league']['season'] = 2025;
                break;
            case 'wrong league':$e['response'][0]['league']['id'] = 39;
                break;
            case 'wrong parameters':$e['parameters']['season'] = '2025';
                break;
            case 'duplicate team':$rows[1]['team'] = $rows[0]['team'];
                break;
            case 'missing team':array_pop($rows);
                break;
            case 'foreign team':$rows[9]['team']['id'] = 99999;
                break;
            case 'archived roster':$rows[8]['team']['id'] = 1033;
                break;
            case 'rank gap':$rows[9]['rank'] = 11;
                break;
            case 'unordered identity':[$rows[0],$rows[1]] = [$rows[1], $rows[0]];
                break;
            case 'multiple groups':$e['response'][0]['league']['standings'][] = $rows;
                break;
            case 'group mismatch':$rows[0]['group'] = 'Other';
                break;
            case 'missing stats':unset($rows[0]['home']);
                break;
            case 'numeric string':$rows[0]['all']['played'] = '10';
                break;
            case 'negative goals':$rows[0]['all']['goals']['for'] = -1;
                break;
            case 'incoherent points':$rows[0]['points'] = 21;
                break;
            case 'incoherent played':$rows[0]['all']['played'] = 11;
                break;
            case 'incoherent breakdown':$rows[0]['home']['win'] = 4;
                break;
            case 'incoherent difference':$rows[0]['goalsDiff'] = 11;
                break;
            case 'missing timestamp':unset($rows[0]['update']);
                break;
            case 'missing optional field':unset($rows[0]['form']);
                break;
            case 'invalid form':$rows[0]['form'] = 'XXXXX';
                break;
            default:throw new RuntimeException('unknown case');
        }
        $rejected = false;
        try {
            $this->sync($e);
        } catch (RuntimeException $ex) {
            $rejected = true;
        }self::assertTrue($rejected, 'must reject');
        self::assertEquals($before, $this->snapshot());
        self::assertSame([], $this->receipts);
    }

    public static function invalidCases(): array
    {
        return array_map(fn ($v) => [$v], ['empty', 'error', 'missing errors', 'bad results', 'partial page', 'wrong season', 'wrong league', 'wrong parameters', 'duplicate team', 'missing team', 'foreign team', 'archived roster', 'rank gap', 'unordered identity', 'multiple groups', 'group mismatch', 'missing stats', 'numeric string', 'negative goals', 'incoherent points', 'incoherent played', 'incoherent breakdown', 'incoherent difference', 'missing timestamp', 'missing optional field', 'invalid form']);
    }

    public function test_mid_table_failure_rolls_back_all_writes_and_no_receipt(): void
    {
        DB::unprepared("CREATE TRIGGER fail_hnl BEFORE INSERT ON standings WHEN NEW.season=2026 AND NEW.team_id=1511 BEGIN SELECT RAISE(ABORT,'injected'); END");
        try {
            $this->sync($this->payload());
            self::fail('must rollback');
        } catch (QueryException) {
        }
        self::assertEquals($this->initial, $this->snapshot());
        self::assertSame([], $this->receipts);
    }

    public function test_missing_local_mapping_stops_before_http(): void
    {
        DB::table('teams')->where('id', 2743)->delete();
        $before = $this->snapshot();
        try {
            $this->sync($this->payload());
            self::fail('missing team');
        } catch (RuntimeException $e) {
            self::assertSame('hnl_standings_missing_local_team', $e->getMessage());
        }
        Http::assertNothingSent();
        self::assertEquals($before, $this->snapshot());
    }

    public function test_season_rollover_stops_before_http(): void
    {
        DB::table('fixtures')->where('id', 1)->update(['season' => 2027]);
        $before = $this->snapshot();
        try {
            $this->sync($this->payload());
            self::fail('season rollover');
        } catch (RuntimeException $e) {
            self::assertSame('hnl_standings_recorded_season_guard', $e->getMessage());
        }
        Http::assertNothingSent();
        self::assertEquals($before, $this->snapshot());
    }

    public function test_receipt_failure_is_committed_not_rollback(): void
    {
        $log = Mockery::mock();
        $log->shouldReceive('channel')->andReturnSelf();
        $log->shouldReceive('info')->andReturnUsing(function ($event) {
            if ($event === 'hnl_standings_committed') {
                throw new RuntimeException('disk unavailable');
            }
        });
        Log::swap($log);
        $r = $this->sync($this->payload());
        self::assertSame('committed_receipt_unavailable', $r['outcome']);
        self::assertSame(10, DB::table('standings')->where('league_id', 835)->where('season', 2026)->count());
        Http::assertSentCount(1);
    }

    public function test_outer_transaction_cannot_emit_premature_receipt(): void
    {
        DB::beginTransaction();
        try {
            $this->sync($this->payload());
            self::fail('outer transaction');
        } catch (RuntimeException $e) {
            self::assertSame('hnl_standings_outer_transaction_not_supported', $e->getMessage());
        } finally {
            DB::rollBack();
        }
        Http::assertNothingSent();
        self::assertSame([], $this->receipts);
    }

    public function test_real_two_connection_stale_snapshot_rolls_back_and_preserves_competing_update(): void
    {
        $this->sync($this->payload());
        $this->receipts = [];
        $file = tempnam(sys_get_temp_dir(), 'hnl-disposable-');
        // SQLite WAL permits a competing committed update while this reader holds a snapshot.
        // This is an actual DB conflict, not a claim that SQLite implements FOR UPDATE.
        DB::statement('VACUUM INTO '.DB::getPdo()->quote($file));
        $this->db->getDatabaseManager()->purge('default');
        $this->db->addConnection(['driver' => 'sqlite', 'database' => $file, 'foreign_key_constraints' => true]);
        DB::statement('PRAGMA journal_mode=WAL');
        DB::statement('PRAGMA busy_timeout=50');
        $e = $this->payload();
        $e['response'][0]['league']['standings'][0][0]['form'] = 'DDDDD';
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $physical = 0;
        Http::fake(function () use ($file, $e, &$physical) {
            $physical++;
            $other = new \PDO('sqlite:'.$file);
            $other->exec("UPDATE standings SET form='LLLLL' WHERE league_id=835 AND season=2026 AND team_id=1507");

            return Http::response($e, 200);
        });
        $failed = false;
        try {
            (new HnlStandingsSync(new ApiFootballGateway($this->quota)))->sync();
        } catch (QueryException $ex) {
            $failed = true;
            self::assertStringContainsString('locked', $ex->getMessage());
        }
        self::assertTrue($failed);
        self::assertSame(1, $physical);
        self::assertSame([], $this->receipts);
        self::assertSame('LLLLL', DB::table('standings')->where('league_id', 835)->where('season', 2026)->where('team_id', 1507)->value('form'));
        self::assertSame(10, DB::table('standings')->where('league_id', 835)->where('season', 2025)->count());
        // Keep disposable DB for evidence until process exit; no production connection exists.
    }

    public function test_hnl_command_uses_only_current_season_and_preserves_history(): void
    {
        $c = Container::getInstance();
        $c->instance(ApiFootballGateway::class, new ApiFootballGateway($this->quota));
        $e = $this->payload();
        $requests = [];
        Http::fake(function ($request) use ($e, &$requests) {
            $requests[] = $request->url();
            if (str_contains($request->url(), 'season=2025')) {
                $e['parameters']['season'] = '2025';
                $e['response'][0]['league']['season'] = 2025;
            }

            return Http::response($e, 200);
        });
        $cmd = new SyncStandings;
        $cmd->setLaravel($c);
        $exit = $cmd->run(new ArrayInput(['--league' => '210']), new BufferedOutput);
        self::assertSame(0, $exit);
        self::assertSame(['https://provider.invalid/standings?league=210&season=2026'], $requests);
        Http::assertSentCount(1);
        self::assertSame(10, DB::table('standings')->where('league_id', 835)->where('season', 2025)->count());
        self::assertSame(10, DB::table('standings')->where('league_id', 835)->where('season', 2026)->count());
    }

    public function test_unopted_gateway_retains_legacy_response_semantics(): void
    {
        $e = ['response' => [['legacy' => 'unchanged']]];
        Http::fake(fn () => Http::response($e, 200));
        $r = (new ApiFootballGateway($this->quota))->get('/standings', ['league' => 39, 'season' => 2025], 'standings', 'SyncStandings');
        self::assertSame($e['response'], $r);
        Http::assertSentCount(1);
        self::assertEquals($this->initial, $this->snapshot());
    }
}
