<?php

namespace Tests\Feature;

use App\Contracts\ApiFootballQuotaStore;
use App\Jobs\FinalizeFinishedFixtures;
use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Services\ApiFootball\ApiFootballGateway;
use App\Services\ApiFootballService;
use App\Support\FootballFixtureStatus;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FixtureRepairFinalizerTest extends TestCase
{
    private int $leagueId;

    private int $homeTeamId;

    private int $awayTeamId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-21 10:00:00');
        config()->set('app.key', str_repeat('a', 32));
        config()->set('api_football.repair.finalizer_per_run', 5);
        $this->createSchema();

        $this->leagueId = DB::table('leagues')->insertGetId([
            'api_league_id' => 39,
            'name' => 'Premier League',
            'current_season' => 2026,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->homeTeamId = $this->insertTeam(1001, 'Home');
        $this->awayTeamId = $this->insertTeam(1002, 'Away');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        Schema::dropIfExists('fixture_scores');
        Schema::dropIfExists('fixtures');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('leagues');

        parent::tearDown();
    }

    public function test_it_reaches_eligible_fixtures_after_an_ineligible_first_page(): void
    {
        config()->set('api_football.repair.finalizer_per_run', 1);

        for ($index = 0; $index < 25; $index++) {
            $this->insertFixture(
                apiId: 2000 + $index,
                updatedAt: now()->subMinutes(20 + $index),
            );
        }

        $quota = new InMemoryRepairQuota(
            static fn (int $fixtureId, int $attempt): bool => $attempt > 20,
        );
        $called = [];
        $api = $this->apiMock(function (int $apiId) use (&$called): array {
            $called[] = $apiId;

            return $this->providerFixture('FT');
        });

        (new FinalizeFinishedFixtures)->handle($api, $quota);

        $this->assertCount(21, $quota->acquireAttempts);
        $this->assertCount(1, $called);
        $this->assertSame(2020, $called[0]);
    }

    public function test_five_call_cap_and_both_fairness_lanes_make_progress(): void
    {
        $oldestId = null;
        $newestId = null;

        for ($index = 0; $index < 8; $index++) {
            $fixture = $this->insertFixture(
                apiId: 3000 + $index,
                updatedAt: now()->subHours(10)->addMinutes($index),
            );
            $oldestId ??= $fixture->api_fixture_id;
        }
        for ($index = 0; $index < 8; $index++) {
            $fixture = $this->insertFixture(
                apiId: 4000 + $index,
                updatedAt: now()->subMinutes(40)->addMinutes($index),
            );
            $newestId = $fixture->api_fixture_id;
        }

        $quota = new InMemoryRepairQuota;
        $called = [];
        $api = $this->apiMock(function (int $apiId) use (&$called): array {
            $called[] = $apiId;

            return $this->providerFixture('2H', 90, 3, 1, null, null);
        });

        (new FinalizeFinishedFixtures)->handle($api, $quota);

        $this->assertCount(5, $called);
        $this->assertContains($newestId, $called, 'Recent stale lane must not wait behind the old backlog.');
        $this->assertContains($oldestId, $called, 'Oldest stale lane must make deterministic progress.');
    }

    public function test_terminal_repair_state_keeps_duplicate_runs_idempotent(): void
    {
        $this->insertFixture(5001, now()->subHour());

        $quota = new InMemoryRepairQuota;
        $api = $this->apiMock(fn (): array => $this->providerFixture('FT'));
        $job = new FinalizeFinishedFixtures;

        $job->handle($api, $quota);
        $job->handle($api, $quota);

        $api->shouldHaveReceived('getFixtureById')->once();
        $this->assertCount(1, $quota->terminalFixtures);
        $this->assertSame('finalized_FT', $quota->records[0]['outcome']);
    }

    #[DataProvider('providerBlockReasons')]
    public function test_quota_and_circuit_blocks_stop_without_an_extra_provider_attempt(string $reason): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->insertFixture(6000 + $index, now()->subMinutes(30 + $index));
        }

        $quota = new InMemoryRepairQuota;
        $quota->reservation = ['allowed' => false, 'reason' => $reason];
        $api = new ApiFootballService(new ApiFootballGateway($quota));
        Http::fake();

        (new FinalizeFinishedFixtures)->handle($api, $quota);

        $this->assertSame(1, $quota->reserveCalls);
        Http::assertNothingSent();
        $this->assertCount(1, $quota->released);
        $this->assertSame([], $quota->records, 'A denied provider reservation must not consume repair attempts.');
    }

    public static function providerBlockReasons(): array
    {
        return [
            'fixture repair sub-budget' => ['class_hard_stop'],
            'global hard stop' => ['global_hard_stop'],
            'open circuit' => ['circuit'],
        ];
    }

    #[DataProvider('terminalStatuses')]
    public function test_repair_persists_every_authoritative_provider_terminal_status(string $status): void
    {
        $fixture = $this->insertFixture(7001, now()->subHour());
        config()->set('api_football.repair.finalizer_per_run', 1);

        $quota = new InMemoryRepairQuota;
        $api = $this->apiMock(fn (): array => $this->providerFixture($status));

        (new FinalizeFinishedFixtures)->handle($api, $quota);

        $this->assertSame($status, $fixture->fresh()->status_short);
        $this->assertTrue($quota->records[0]['terminal']);
    }

    public static function terminalStatuses(): array
    {
        return array_map(
            static fn (string $status): array => [$status],
            FootballFixtureStatus::TERMINAL,
        );
    }

    public function test_normal_ingestion_persists_the_same_authoritative_terminal_statuses(): void
    {
        $payloads = [];
        foreach (FootballFixtureStatus::TERMINAL as $index => $status) {
            $payloads[] = $this->syncPayload(8000 + $index, $status);
        }

        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldReceive('getFixturesByDate')->once()->andReturn($payloads);
        $this->app->instance(ApiFootballService::class, $api);

        $this->artisan('sync:fixtures', ['--date' => '2026-09-21'])->assertSuccessful();

        $this->assertSame(
            FootballFixtureStatus::TERMINAL,
            Fixture::whereBetween('api_fixture_id', [8000, 8999])
                ->orderBy('api_fixture_id')
                ->pluck('status_short')
                ->all(),
        );
    }

    public function test_normal_ingestion_does_not_regress_terminal_status_or_score(): void
    {
        $fixture = $this->insertFixture(9001, now()->subHour(), 'FT');
        DB::table('fixture_scores')->insert([
            'fixture_id' => $fixture->id,
            'goals_home' => 2,
            'goals_away' => 0,
            'home_fulltime' => 2,
            'away_fulltime' => 0,
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldReceive('getFixturesByDate')->once()->andReturn([
            $this->syncPayload(9001, '2H', 90, 3, 2, null, null),
        ]);
        $this->app->instance(ApiFootballService::class, $api);

        $this->artisan('sync:fixtures', ['--date' => '2026-09-21'])->assertSuccessful();

        $this->assertSame('FT', $fixture->fresh()->status_short);
        $score = FixtureScore::where('fixture_id', $fixture->id)->firstOrFail();
        $this->assertSame(2, $score->goals_home);
        $this->assertSame(0, $score->goals_away);
        $this->assertSame(2, $score->home_fulltime);
        $this->assertSame(0, $score->away_fulltime);
    }

    public function test_completed_looking_2h_90_fixture_is_queried_but_never_force_finished(): void
    {
        $fixture = $this->insertFixture(10001, now()->subHour(), '2H', 90);
        DB::table('fixture_scores')->insert([
            'fixture_id' => $fixture->id,
            'goals_home' => 3,
            'goals_away' => 1,
            'home_fulltime' => null,
            'away_fulltime' => null,
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        config()->set('api_football.repair.finalizer_per_run', 1);
        $quota = new InMemoryRepairQuota;
        $called = [];
        $api = $this->apiMock(function (int $apiId) use (&$called): array {
            $called[] = $apiId;

            return $this->providerFixture('2H', 90, 3, 1, null, null);
        });

        (new FinalizeFinishedFixtures)->handle($api, $quota);

        $this->assertSame([10001], $called);
        $this->assertSame('2H', $fixture->fresh()->status_short);
        $score = FixtureScore::where('fixture_id', $fixture->id)->firstOrFail();
        $this->assertNull($score->home_fulltime);
        $this->assertNull($score->away_fulltime);
        $this->assertSame('still_2H', $quota->records[0]['outcome']);
    }

    public function test_scan_terminates_at_the_explicit_bound_when_every_candidate_is_ineligible(): void
    {
        for ($index = 0; $index < 500; $index++) {
            $this->insertFixture(11000 + $index, now()->subMinutes(20 + $index));
        }

        $quota = new InMemoryRepairQuota(static fn (): bool => false);
        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldNotReceive('getFixtureById');

        (new FinalizeFinishedFixtures)->handle($api, $quota);

        $this->assertCount(200, $quota->acquireAttempts);
        $this->assertLessThan(500, count($quota->acquireAttempts));
        $this->assertSame([], $quota->records);
    }

    private function apiMock(Closure $response): ApiFootballService
    {
        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldReceive('getFixtureById')
            ->andReturnUsing(fn (int $apiId): array => $response($apiId));

        return $api;
    }

    private function insertFixture(
        int $apiId,
        Carbon $updatedAt,
        string $status = '2H',
        int $elapsed = 90,
    ): Fixture {
        $id = DB::table('fixtures')->insertGetId([
            'api_fixture_id' => $apiId,
            'league_id' => $this->leagueId,
            'home_team_id' => $this->homeTeamId,
            'away_team_id' => $this->awayTeamId,
            'season' => 2026,
            'round' => 'Round 1',
            'kick_off' => now()->subHours(3),
            'status_long' => $status,
            'status_short' => $status,
            'elapsed_minute' => $elapsed,
            'venue_name' => null,
            'referee' => null,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);

        return Fixture::findOrFail($id);
    }

    private function insertTeam(int $apiId, string $name): int
    {
        return DB::table('teams')->insertGetId([
            'api_team_id' => $apiId,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function providerFixture(
        string $status,
        int $elapsed = 90,
        int $home = 2,
        int $away = 0,
        ?int $fulltimeHome = 2,
        ?int $fulltimeAway = 0,
    ): array {
        return [
            'fixture' => [
                'status' => [
                    'short' => $status,
                    'long' => $status,
                    'elapsed' => $elapsed,
                ],
            ],
            'goals' => ['home' => $home, 'away' => $away],
            'score' => [
                'halftime' => ['home' => 1, 'away' => 0],
                'fulltime' => ['home' => $fulltimeHome, 'away' => $fulltimeAway],
                'extratime' => ['home' => null, 'away' => null],
                'penalty' => ['home' => null, 'away' => null],
            ],
        ];
    }

    private function syncPayload(
        int $apiId,
        string $status,
        int $elapsed = 90,
        int $home = 2,
        int $away = 0,
        ?int $fulltimeHome = 2,
        ?int $fulltimeAway = 0,
    ): array {
        return [
            'fixture' => [
                'id' => $apiId,
                'timestamp' => now()->timestamp,
                'status' => ['short' => $status, 'long' => $status, 'elapsed' => $elapsed],
                'venue' => ['name' => null],
                'referee' => null,
            ],
            'league' => ['id' => 39, 'season' => 2026, 'round' => 'Round 1'],
            'teams' => [
                'home' => ['id' => 1001, 'name' => 'Home', 'logo' => null],
                'away' => ['id' => 1002, 'name' => 'Away', 'logo' => null],
            ],
            'goals' => ['home' => $home, 'away' => $away],
            'score' => [
                'halftime' => ['home' => 1, 'away' => 0],
                'fulltime' => ['home' => $fulltimeHome, 'away' => $fulltimeAway],
            ],
        ];
    }

    private function createSchema(): void
    {
        Schema::create('leagues', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('api_league_id')->unique();
            $table->string('name');
            $table->unsignedSmallInteger('current_season')->nullable();
            $table->timestamps();
        });
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('api_team_id')->unique();
            $table->string('name');
            $table->string('logo_url')->nullable();
            $table->timestamps();
        });
        Schema::create('fixtures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('api_fixture_id')->unique();
            $table->unsignedBigInteger('league_id');
            $table->unsignedBigInteger('home_team_id');
            $table->unsignedBigInteger('away_team_id');
            $table->unsignedSmallInteger('season');
            $table->string('round')->nullable();
            $table->dateTime('kick_off');
            $table->string('status_long')->nullable();
            $table->string('status_short')->nullable();
            $table->unsignedSmallInteger('elapsed_minute')->nullable();
            $table->string('venue_name')->nullable();
            $table->string('referee')->nullable();
            $table->timestamps();
            $table->index(['status_short', 'updated_at']);
        });
        Schema::create('fixture_scores', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fixture_id')->unique();
            $table->unsignedTinyInteger('goals_home')->nullable();
            $table->unsignedTinyInteger('goals_away')->nullable();
            $table->unsignedTinyInteger('home_halftime')->nullable();
            $table->unsignedTinyInteger('away_halftime')->nullable();
            $table->unsignedTinyInteger('home_fulltime')->nullable();
            $table->unsignedTinyInteger('away_fulltime')->nullable();
            $table->unsignedTinyInteger('home_extratime')->nullable();
            $table->unsignedTinyInteger('away_extratime')->nullable();
            $table->unsignedTinyInteger('home_penalties')->nullable();
            $table->unsignedTinyInteger('away_penalties')->nullable();
            $table->timestamps();
        });
    }
}

final class InMemoryRepairQuota implements ApiFootballQuotaStore
{
    /** @var list<int> */
    public array $acquireAttempts = [];

    /** @var list<int> */
    public array $released = [];

    /** @var list<array{fixture_id: int, outcome: string, terminal: bool}> */
    public array $records = [];

    /** @var array<int, bool> */
    public array $terminalFixtures = [];

    public array $reservation = ['allowed' => true];

    public int $reserveCalls = 0;

    /** @var array<int, bool> */
    private array $locked = [];

    public function __construct(private readonly ?Closure $eligibility = null) {}

    public function reserve(string $endpointClass, string $caller): array
    {
        $this->reserveCalls++;

        return $this->reservation;
    }

    public function record(string $endpointClass, string $caller, int|string $status, string $outcome): void {}

    public function openCircuit(int $until, string $reason): void {}

    public function status(): array
    {
        return [];
    }

    public function acquireRepair(int $fixtureId, string $caller): bool
    {
        $this->acquireAttempts[] = $fixtureId;
        if (isset($this->locked[$fixtureId]) || isset($this->terminalFixtures[$fixtureId])) {
            return false;
        }
        if ($this->eligibility && ! ($this->eligibility)($fixtureId, count($this->acquireAttempts))) {
            return false;
        }

        $this->locked[$fixtureId] = true;

        return true;
    }

    public function releaseRepair(int $fixtureId): void
    {
        unset($this->locked[$fixtureId]);
        $this->released[] = $fixtureId;
    }

    public function recordRepair(int $fixtureId, string $outcome, bool $terminal = false): void
    {
        unset($this->locked[$fixtureId]);
        $this->records[] = ['fixture_id' => $fixtureId, 'outcome' => $outcome, 'terminal' => $terminal];
        if ($terminal) {
            $this->terminalFixtures[$fixtureId] = true;
        }
    }
}
