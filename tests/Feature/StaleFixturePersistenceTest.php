<?php

namespace Tests\Feature;

use App\Jobs\FetchLiveFixtures;
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
        $this->seed($this->identity);
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

    private function seed(array $i): void
    {
        DB::table('leagues')->insert(['id' => $i['league_id'], 'api_league_id' => $i['api_league_id'], 'name' => 'Synthetic', 'sport' => 'football']);
        DB::table('teams')->insert([
            ['id' => $i['home_team_id'], 'api_team_id' => $i['home_api_id'], 'name' => 'Synthetic Home'],
            ['id' => $i['away_team_id'], 'api_team_id' => $i['away_api_id'], 'name' => 'Synthetic Away'],
        ]);
        DB::table('fixtures')->insert(array_intersect_key($i, array_flip(['id', 'api_fixture_id', 'league_id', 'home_team_id', 'away_team_id', 'season'])) + [
            'kick_off' => '2026-10-02 12:00:00', 'status_short' => 'NS', 'status_long' => 'Not Started', 'created_at' => '2026-10-01 11:51:53', 'updated_at' => '2026-10-01 11:51:53',
        ]);
        DB::table('fixture_scores')->insert(['id' => $i['score_id'], 'fixture_id' => $i['id'], 'created_at' => '2026-10-01 11:51:53']);
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
