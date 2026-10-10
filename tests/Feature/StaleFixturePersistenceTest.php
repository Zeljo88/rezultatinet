<?php

namespace Tests\Feature;

use App\Contracts\ApiFootballQuotaStore;
use App\Jobs\FetchLiveFixtures;
use App\Jobs\FinalizeFinishedFixtures;
use App\Jobs\FixZombieFixtures;
use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Services\ApiFootball\ApiFootballGateway;
use App\Services\ApiFootballService;
use App\Services\StaleFixtureJournal;
use App\Services\StaleFixturePolicy;
use App\Services\StaleFixtureReconciler;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\PendingBroadcast;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\StaleFixtureTestDatabase;
use Tests\TestCase;

/** Prepared only: opt-in synthetic local fixture writes are required. */
class StaleFixturePersistenceTest extends TestCase
{
    protected bool $ownsSchema = false;

    protected array $identity;

    protected string $journalPath;

    protected StaleFixtureJournal $journal;

    protected array $broadcasts = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('STALE_TEST_ALLOW_FIXTURES') !== '1') {
            $this->markTestSkipped('Synthetic DB fixture writes require separate test-runner authority.');
        }
        if (static::class === StaleFixtureMariaDbConcurrencyTest::class) {
            StaleFixtureTestDatabase::configureMariaDb();
        } elseif (! app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            $this->fail('Only isolated in-memory SQLite permitted for persistence tests.');
        }
        foreach (['fixtures', 'fixture_scores', 'teams', 'leagues'] as $name) {
            $this->assertFalse(Schema::hasTable($name), 'Runner schema must be empty; never truncate existing tables.');
        }
        Carbon::setTestNow('2026-10-10 10:00:00 UTC');
        Http::preventStrayRequests();
        $this->schema();
        $this->ownsSchema = true;
        $this->journalPath = sys_get_temp_dir().'/stale-test-audit-'.bin2hex(random_bytes(8));
        $this->journal = new StaleFixtureJournal($this->journalPath, true);
        $this->identity = array_values(array_filter((new StaleFixturePolicy)->cohort(), fn ($i) => $i['api_fixture_id'] === 1640498))[0];
        $this->seedStaleIdentity($this->identity);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('event')->andReturnUsing(function ($event): PendingBroadcast {
            $this->broadcasts[] = $event;
            $dispatcher = Mockery::mock(Dispatcher::class);
            $dispatcher->shouldReceive('dispatch');

            return new PendingBroadcast($dispatcher, $event);
        });
        $this->app->instance(Factory::class, $factory);
    }

    protected function tearDown(): void
    {
        if ($this->ownsSchema) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            FixtureScore::flushEventListeners();
            foreach (['fixture_scores', 'fixtures', 'teams', 'leagues'] as $name) {
                Schema::drop($name);
            }
            unset($this->journal);
            if (isset($this->journalPath) && file_exists($this->journalPath)) {
                unlink($this->journalPath);
            }
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_terminal_reconciliation_and_replay_preserve_exact_facts(): void
    {
        $before = $this->snapshot();
        $row = $this->retained();
        $facts = (new StaleFixturePolicy)->response([$row], $this->identity);
        $this->assertSame('updated_FT', $this->reconciler()->apply($this->identity, $before, $facts, hrtime(true) / 1e9, $this->journal));
        $this->assertSame('FT', Fixture::findOrFail($this->identity['id'])->status_short);
        $this->assertSame('2026-10-02 09:30:00', Fixture::findOrFail($this->identity['id'])->kick_off->format('Y-m-d H:i:s'));
        $this->assertEquals(1, FixtureScore::where('fixture_id', $this->identity['id'])->value('goals_home'));
        $this->assertSame('concurrent_change', $this->reconciler()->apply($this->identity, $before, $facts, hrtime(true) / 1e9, $this->journal));
        Http::assertNothingSent();
    }

    public function test_live_stale_payloads_cannot_regress_finished_or_progressing_fixture_or_score(): void
    {
        $facts = (new StaleFixturePolicy)->response([$this->retained()], $this->identity);
        $this->reconciler()->apply($this->identity, $this->snapshot(), $facts, hrtime(true) / 1e9, $this->journal);
        $finished = $this->snapshot();
        $this->live($this->liveRow());
        $this->assertSame($finished, $this->snapshot());
        $this->assertSame([], $this->broadcasts);
        DB::table('fixtures')->where('id', $this->identity['id'])->update(['status_short' => '2H', 'elapsed_minute' => 80]);
        $progressing = $this->snapshot();
        $ns = $this->liveRow();
        $ns['fixture']['status']['short'] = 'NS';
        $ns['fixture']['status']['elapsed'] = null;
        $this->live($ns);
        $this->assertSame($progressing, $this->snapshot());
        $this->assertSame([], $this->broadcasts);
    }

    public function test_score_failure_rolls_back_fixture_and_after_commit_broadcast_is_deferred(): void
    {
        $before = $this->snapshot();
        FixtureScore::updating(function (): void {
            throw new \RuntimeException('synthetic_score_failure');
        });
        try {
            $this->live($this->liveRow());
            $this->fail('Expected synthetic score failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic_score_failure', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->broadcasts);
        FixtureScore::flushEventListeners();
        DB::beginTransaction();
        $this->live($this->liveRow());
        $this->assertSame([], $this->broadcasts);
        DB::commit();
        $this->assertCount(1, $this->broadcasts);
    }

    public function test_score_only_change_future_reschedule_and_expired_response_win(): void
    {
        $before = $this->snapshot();
        $facts = (new StaleFixturePolicy)->response([$this->retained()], $this->identity);
        $this->assertSame('response_expired', $this->reconciler()->apply($this->identity, $before, $facts, hrtime(true) / 1e9 - 121, $this->journal));
        DB::table('fixture_scores')->where('fixture_id', $this->identity['id'])->update(['goals_home' => 0]);
        $this->assertSame('concurrent_change', $this->reconciler()->apply($this->identity, $before, $facts, hrtime(true) / 1e9, $this->journal));
        DB::table('fixture_scores')->where('fixture_id', $this->identity['id'])->update(['goals_home' => null]);
        DB::table('fixtures')->where('id', $this->identity['id'])->update(['kick_off' => '2026-10-20 12:00:00']);
        $this->assertSame('concurrent_change', $this->reconciler()->apply($this->identity, $before, $facts, hrtime(true) / 1e9, $this->journal));
    }

    public function test_unchanged_postponement_is_timestamp_stable_and_future_reschedule_is_allowed(): void
    {
        DB::table('fixtures')->where('id', $this->identity['id'])->update(['status_short' => 'PST', 'status_long' => 'Match Postponed']);
        $row = $this->retained();
        $row['fixture']['date'] = '2026-10-02T12:00:00+00:00';
        $row['fixture']['timestamp'] = strtotime($row['fixture']['date']);
        $row['fixture']['status'] = ['short' => 'PST', 'long' => 'Match Postponed', 'elapsed' => null, 'extra' => null];
        $row['goals'] = ['home' => null, 'away' => null];
        foreach ($row['score'] as &$pair) {
            $pair = ['home' => null, 'away' => null];
        }
        unset($pair);
        $before = $this->snapshot();
        $facts = (new StaleFixturePolicy)->response([$row], $this->identity);
        $this->assertSame('unchanged', $this->reconciler()->apply($this->identity, $before, $facts, hrtime(true) / 1e9, $this->journal));
        $this->assertSame($before, $this->snapshot());
        $row['fixture']['date'] = '2026-10-20T14:00:00+00:00';
        $row['fixture']['timestamp'] = strtotime($row['fixture']['date']);
        $row['fixture']['status']['short'] = 'NS';
        $row['fixture']['status']['long'] = 'Not Started';
        $facts = (new StaleFixturePolicy)->response([$row], $this->identity);
        $this->assertSame('updated_NS', $this->reconciler()->apply($this->identity, $before, $facts, hrtime(true) / 1e9, $this->journal));
        $this->assertSame('too_recent_or_future', (new StaleFixturePolicy)->eligibility($this->snapshot(), $this->identity, CarbonImmutable::now('UTC')));
        $this->assertNull(FixtureScore::where('fixture_id', $this->identity['id'])->value('goals_home'));
    }

    public function test_earlier_terminal_phase_cannot_erase_penalty_result(): void
    {
        DB::table('fixtures')->where('id', $this->identity['id'])->update(['status_short' => 'PEN', 'elapsed_minute' => 120]);
        DB::table('fixture_scores')->where('fixture_id', $this->identity['id'])->update(['goals_home' => 2, 'goals_away' => 2, 'home_penalties' => 5, 'away_penalties' => 4]);
        $before = $this->snapshot();
        $this->live($this->retained());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->broadcasts);
    }

    public function test_all_selected_repair_writers_preserve_intervening_score_only_changes(): void
    {
        foreach (['finalizer', 'zombie', 'manual'] as $writer) {
            DB::table('fixtures')->where('id', $this->identity['id'])->update(['status_short' => '2H', 'elapsed_minute' => 90, 'kick_off' => '2026-10-10 05:00:00', 'updated_at' => '2026-10-10 06:00:00']);
            DB::table('fixture_scores')->where('fixture_id', $this->identity['id'])->update(['goals_home' => 1, 'goals_away' => 1]);
            $before = $this->snapshot();
            $quota = Mockery::mock(ApiFootballQuotaStore::class);
            $quota->shouldReceive('repairScanState')->andReturn(null);
            $quota->shouldReceive('compareAndSetRepairScanState')->andReturn(true);
            $quota->shouldReceive('acquireRepair')->andReturn(true);
            $quota->shouldReceive('releaseRepair')->once()->with($this->identity['id']);
            $quota->shouldNotReceive('recordRepair');
            $api = Mockery::mock(ApiFootballService::class);
            $api->shouldReceive('getFixtureById')->once()->andReturnUsing(function (): array {
                if (DB::getDriverName() === 'mysql') {
                    $other = new \PDO('mysql:unix_socket='.getenv('STALE_TEST_SOCKET').';dbname='.getenv('STALE_TEST_DATABASE'), posix_getpwuid(posix_geteuid())['name']);
                    $other->exec('UPDATE fixture_scores SET goals_home=3 WHERE fixture_id='.(int) $this->identity['id']);
                } else {
                    DB::table('fixture_scores')->where('fixture_id', $this->identity['id'])->update(['goals_home' => 3]);
                }

                return $this->retained();
            });
            if ($writer === 'finalizer') {
                (new FinalizeFinishedFixtures)->handle($api, $quota);
            } elseif ($writer === 'zombie') {
                (new FixZombieFixtures)->handle($api, $quota);
            } else {
                $this->app->instance(ApiFootballService::class, $api);
                $this->app->instance(ApiFootballQuotaStore::class, $quota);
                $this->artisan('sync:fix-stuck')->assertSuccessful();
            }
            $after = $this->snapshot();
            $this->assertSame($before['fixture'], $after['fixture'], $writer);
            $this->assertSame(3, (int) $after['score']['goals_home'], $writer);
        }
    }

    public function test_cursor_reaches_last_identity_despite_one_hundred_cooldowns(): void
    {
        $cohort = (new StaleFixturePolicy)->cohort();
        $last = $cohort[110];
        $this->seedStaleIdentity($last);
        foreach (array_slice($cohort, 0, 110) as $identity) {
            $this->journal->append(['type' => 'outcome', 'id' => $identity['id'], 'retry' => ['next_at' => time() + 7200]]);
        }
        $gateway = Mockery::mock(ApiFootballGateway::class);
        $gateway->shouldReceive('get')->once()->withArgs(function ($path, $query, $class, $caller, $claim) use ($last): bool {
            return $query === ['id' => $last['api_fixture_id']] && $class === 'fixture_repair' && $claim();
        })->andReturn([]);
        $service = new StaleFixtureReconciler(new StaleFixturePolicy, $gateway);
        $first = $service->run($this->journal);
        $this->assertSame(100, $first['inspected']);
        $this->assertSame(0, $first['reserved_attempts']);
        $this->assertSame(100, $this->journal->state['cursor']);
        unset($this->journal);
        $this->journal = new StaleFixtureJournal($this->journalPath);
        $second = $service->run($this->journal);
        $this->assertSame(1, $second['reserved_attempts']);
        $this->assertSame(1, $second['outcomes']['empty']);
        $this->assertSame(89, $this->journal->state['cursor']);
    }

    public function test_actual_gateway_retries_share_five_run_twenty_daily_and_do_not_churn_rows(): void
    {
        $cohort = (new StaleFixturePolicy)->cohort();
        foreach ($cohort as $identity) {
            $this->seedStaleIdentity($identity);
        }
        config()->set('api_football.retry_base_ms', 0);
        $quota = Mockery::mock(ApiFootballQuotaStore::class);
        $quota->shouldReceive('reserve')->times(20)->with('fixture_repair', StaleFixturePolicy::CALLER)->andReturn(['allowed' => true]);
        $quota->shouldReceive('record')->times(20);
        $calls = 0;
        Http::fake(function ($request) use (&$calls, $cohort) {
            $calls++;
            if ($calls === 1) {
                return Http::response([], 500);
            }
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $i = array_values(array_filter($cohort, fn ($v) => $v['api_fixture_id'] === (int) $query['id']))[0];
            $row = $this->retained();
            $row['fixture']['id'] = $i['api_fixture_id'];
            $row['league'] = ['id' => $i['api_league_id'], 'season' => $i['season']];
            $row['teams'] = ['home' => ['id' => $i['home_api_id']], 'away' => ['id' => $i['away_api_id']]];
            $row['fixture']['date'] = '2026-10-02T12:00:00+00:00';
            $row['fixture']['timestamp'] = strtotime($row['fixture']['date']);
            $row['fixture']['status'] = ['short' => 'NS', 'long' => 'Not Started', 'elapsed' => null, 'extra' => null];
            $row['goals'] = ['home' => null, 'away' => null];
            foreach ($row['score'] as &$pair) {
                $pair = ['home' => null, 'away' => null];
            }

            return Http::response(['errors' => [], 'results' => 1, 'paging' => ['current' => 1, 'total' => 1], 'response' => [$row]]);
        });
        $service = new StaleFixtureReconciler(new StaleFixturePolicy, new ApiFootballGateway($quota));
        for ($run = 0; $run < 4; $run++) {
            $result = $service->run($this->journal);
            $this->assertSame(5, $result['reserved_attempts']);
            $this->assertNull($result['stop']);
            $this->assertSame($run === 0 ? 4 : 5, $result['outcomes']['unchanged']);
            unset($this->journal);
            $this->journal = new StaleFixtureJournal($this->journalPath);
        }
        $this->assertSame('local_or_daily_limit', $service->run($this->journal)['stop']);
        $this->assertSame(20, $calls);
        $this->assertSame(20, array_sum($this->journal->state['days']));
        $this->assertSame(111, DB::table('fixtures')->where('updated_at', '2026-10-01 11:51:53')->count());
        Http::assertSentCount(20);
    }

    public function test_reconciliation_score_failure_rolls_back_and_audit_reopen_keeps_reserved_attempt(): void
    {
        $before = $this->snapshot();
        $this->assertTrue($this->journal->claim($this->identity['id']));
        FixtureScore::updating(function (): void {
            throw new \RuntimeException('synthetic_score_failure');
        });
        try {
            $this->reconciler()->apply($this->identity, $before, (new StaleFixturePolicy)->response([$this->retained()], $this->identity), hrtime(true) / 1e9, $this->journal);
            $this->fail('Expected score failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic_score_failure', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        unset($this->journal);
        $this->journal = new StaleFixtureJournal($this->journalPath);
        $this->assertSame(1, array_sum($this->journal->state['days']));
        $this->assertGreaterThan(time(), $this->journal->state['rows'][$this->identity['id']]['next_at']);
    }

    public function test_dormant_date_writer_preserves_null_scores_and_rejects_terminal_regression(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->string('logo_url')->nullable();
        });
        Schema::table('fixtures', function (Blueprint $table): void {
            foreach (['round', 'venue_name', 'referee'] as $name) {
                $table->string($name)->nullable();
            }
        });
        $row = $this->retained();
        $row['teams']['home']['name'] = 'Synthetic Home';
        $row['teams']['away']['name'] = 'Synthetic Away';
        $row['fixture']['status'] = ['short' => 'NS', 'long' => 'Not Started', 'elapsed' => null, 'extra' => null];
        $row['goals'] = ['home' => null, 'away' => null];
        foreach ($row['score'] as &$pair) {
            $pair = ['home' => null, 'away' => null];
        }
        unset($pair);
        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldReceive('getFixturesByDate')->twice()->andReturn([$row]);
        $this->app->instance(ApiFootballService::class, $api);
        $this->artisan('sync:fixtures')->assertSuccessful();
        foreach (StaleFixturePolicy::SCORE_FIELDS as $field) {
            $this->assertNull($this->snapshot()['score'][$field], $field);
        }
        DB::table('fixtures')->where('id', $this->identity['id'])->update(['status_short' => 'CANC']);
        $before = $this->snapshot();
        $this->artisan('sync:fixtures')->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_ns_and_pst_review_delays_do_not_invent_terminal_status(): void
    {
        foreach (['NS' => [7200, 14400, 28800, 57600], 'PST' => [86400, 259200, 604800]] as $status => $delays) {
            DB::table('fixtures')->where('id', $this->identity['id'])->update(['status_short' => $status, 'status_long' => $status]);
            $row = $this->retained();
            $row['fixture']['date'] = '2026-10-02T12:00:00+00:00';
            $row['fixture']['timestamp'] = strtotime($row['fixture']['date']);
            $row['fixture']['status'] = ['short' => $status, 'long' => $status, 'elapsed' => null, 'extra' => null];
            $row['goals'] = ['home' => null, 'away' => null];
            foreach ($row['score'] as &$pair) {
                $pair = ['home' => null, 'away' => null];
            }
            unset($pair);
            $quota = Mockery::mock(ApiFootballQuotaStore::class);
            $quota->shouldReceive('reserve')->times(count($delays))->andReturn(['allowed' => true]);
            $quota->shouldReceive('record')->times(count($delays));
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response(['errors' => [], 'results' => 1, 'paging' => ['current' => 1, 'total' => 1], 'response' => [$row]])]);
            $gateway = new ApiFootballGateway($quota);
            $service = new StaleFixtureReconciler(new StaleFixturePolicy, $gateway);
            $cursor = array_search($this->identity['id'], array_column((new StaleFixturePolicy)->cohort(), 'id'));
            foreach ($delays as $observation => $delay) {
                unset($this->journal);
                $this->journal = new StaleFixtureJournal($this->journalPath);
                $this->journal->append(['type' => 'inspect', 'cursor' => $cursor]);
                $this->journal->append(['type' => 'outcome', 'id' => $this->identity['id'], 'retry' => ['observations' => $observation, 'next_at' => 0]]);
                $at = time();
                $this->assertSame(1, $service->run($this->journal)['outcomes']['unchanged']);
                $retry = $this->journal->state['rows'][$this->identity['id']];
                $this->assertSame($observation + 1, $retry['observations']);
                $this->assertGreaterThanOrEqual($at + $delay, $retry['next_at']);
                $this->assertLessThanOrEqual(time() + $delay, $retry['next_at']);
                $this->assertSame($observation === count($delays) - 1, $retry['review']);
            }
            $this->assertSame($status === 'PST' ? 'postponed_unresolved' : 'needs_review', $retry['disposition']);
            $this->journal->append(['type' => 'inspect', 'cursor' => $cursor]);
            $this->assertSame(0, $service->run($this->journal)['outcomes']['unchanged'] ?? 0);
            $this->assertSame($status, $this->snapshot()['fixture']['status_short']);
        }
    }

    protected function reconciler(): StaleFixtureReconciler
    {
        $gateway = Mockery::mock(ApiFootballGateway::class);
        $gateway->shouldNotReceive('get');

        return new StaleFixtureReconciler(new StaleFixturePolicy, $gateway);
    }

    protected function snapshot(): array
    {
        return (new StaleFixturePolicy)->snapshot(Fixture::findOrFail($this->identity['id']), FixtureScore::where('fixture_id', $this->identity['id'])->firstOrFail());
    }

    protected function retained(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/stale-five-retained.json')), true)[0];
    }

    protected function liveRow(): array
    {
        $row = $this->retained();
        $row['fixture']['status'] = ['short' => '2H', 'long' => 'Second Half', 'elapsed' => 70, 'extra' => null];
        $row['goals'] = ['home' => 1, 'away' => 0];
        $row['score']['fulltime'] = ['home' => null, 'away' => null];

        return $row;
    }

    protected function live(array $row): void
    {
        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldReceive('getLiveFixtures')->once()->andReturn([$row]);
        (new FetchLiveFixtures)->handle($api);
    }

    private function seedStaleIdentity(array $i): void
    {
        DB::table('leagues')->insertOrIgnore(['id' => $i['league_id'], 'api_league_id' => $i['api_league_id'], 'name' => 'Synthetic', 'sport' => 'football']);
        DB::table('teams')->insertOrIgnore([
            ['id' => $i['home_team_id'], 'api_team_id' => $i['home_api_id'], 'name' => 'Synthetic Home'],
            ['id' => $i['away_team_id'], 'api_team_id' => $i['away_api_id'], 'name' => 'Synthetic Away'],
        ]);
        DB::table('fixtures')->insertOrIgnore(array_intersect_key($i, array_flip(['id', 'api_fixture_id', 'league_id', 'home_team_id', 'away_team_id', 'season'])) + [
            'kick_off' => '2026-10-02 12:00:00', 'status_short' => 'NS', 'status_long' => 'Not Started', 'created_at' => '2026-10-01 11:51:53', 'updated_at' => '2026-10-01 11:51:53',
        ]);
        DB::table('fixture_scores')->insertOrIgnore(['id' => $i['score_id'], 'fixture_id' => $i['id'], 'created_at' => '2026-10-01 11:51:53']);
    }

    private function schema(): void
    {
        Schema::create('leagues', function (Blueprint $t): void {
            $t->id();
            $t->unsignedInteger('api_league_id')->unique();
            $t->string('name');
            $t->string('sport');
            $t->timestamps();
        });
        Schema::create('teams', function (Blueprint $t): void {
            $t->id();
            $t->unsignedInteger('api_team_id')->unique();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('fixtures', function (Blueprint $t): void {
            $t->id();
            $t->unsignedInteger('api_fixture_id')->unique();
            foreach (['league_id', 'home_team_id', 'away_team_id'] as $key) {
                $t->unsignedBigInteger($key);
            }
            $t->unsignedSmallInteger('season');
            $t->dateTime('kick_off');
            $t->string('status_short');
            $t->string('status_long')->nullable();
            $t->unsignedSmallInteger('elapsed_minute')->nullable();
            $t->unsignedSmallInteger('elapsed_extra')->nullable();
            $t->dateTime('lineups_fetched_at')->nullable();
            $t->timestamps();
        });
        Schema::create('fixture_scores', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('fixture_id')->unique();
            foreach (StaleFixturePolicy::SCORE_FIELDS as $key) {
                $t->unsignedTinyInteger($key)->nullable();
            }
            $t->timestamps();
        });
    }
}
