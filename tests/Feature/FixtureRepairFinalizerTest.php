<?php

namespace Tests\Feature;

use App\Contracts\ApiFootballQuotaStore;
use App\Jobs\FinalizeFinishedFixtures;
use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Services\ApiFootball\ApiFootballGateway;
use App\Services\ApiFootball\RedisApiFootballQuotaStore;
use App\Services\ApiFootballService;
use App\Support\FootballFixtureStatus;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
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
        config()->set('api_football.repair.finalizer_per_run', 99);
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

    public function test_retries_share_an_absolute_five_physical_attempt_budget(): void
    {
        config()->set('api_football.retry_base_ms', 0);
        for ($index = 0; $index < 5; $index++) {
            $this->insertFixture(6500 + $index, now()->subHour());
        }

        $terminal = ['response' => [$this->providerFixture('FT')]];
        Http::fakeSequence()
            ->push([], 500)
            ->push($terminal, 200)
            ->push([], 500)
            ->push($terminal, 200)
            ->push([], 500);

        $quota = new InMemoryRepairQuota;
        $api = new ApiFootballService(new ApiFootballGateway($quota));

        (new FinalizeFinishedFixtures)->handle($api, $quota);

        Http::assertSentCount(5);
        $this->assertSame(5, $quota->reserveCalls);
        $this->assertCount(2, $quota->records);
        $this->assertCount(1, $quota->released);
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
        DB::enableQueryLog();

        (new FinalizeFinishedFixtures)->handle($api, $quota);

        $this->assertCount(100, $quota->acquireAttempts);
        $this->assertLessThan(500, count($quota->acquireAttempts));
        $this->assertSame([], $quota->records);
        $fixtureSelects = array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
                && str_contains(strtolower($query['query']), 'from "fixtures"'),
        );
        $this->assertLessThanOrEqual(3, count($fixtureSelects));
    }

    public function test_exact_201_row_interior_candidate_is_reached_across_invocations(): void
    {
        $target = null;
        for ($index = 1; $index <= 201; $index++) {
            $fixture = $this->insertFixture(16000 + $index, now()->subMinutes(20 + $index));
            if ($index === 101) {
                $target = $fixture;
            }
        }

        $quota = new InMemoryRepairQuota(
            static fn (int $fixtureId): bool => $fixtureId === $target->id,
        );
        $called = [];
        $api = $this->apiMock(function (int $apiId) use (&$called): array {
            $called[] = $apiId;

            return $this->providerFixture('2H');
        });
        $job = new FinalizeFinishedFixtures;

        $job->handle($api, $quota);
        $this->assertSame([], $called);
        $this->assertSame(100, $quota->scanCursor);

        $job->handle($api, $quota);
        $this->assertSame([16101], $called);
        $this->assertGreaterThanOrEqual(101, $quota->scanCursor);
    }

    public function test_durable_cursor_advances_on_consecutive_successful_runs(): void
    {
        for ($index = 0; $index < 250; $index++) {
            $this->insertFixture(17000 + $index, now()->subHour());
        }

        $quota = new InMemoryRepairQuota(static fn (): bool => false);
        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldNotReceive('getFixtureById');
        $job = new FinalizeFinishedFixtures;

        $job->handle($api, $quota);
        $this->assertSame(100, $quota->scanCursor);
        $job->handle($api, $quota);
        $this->assertSame(200, $quota->scanCursor);
        $this->assertSame([[0, 0], [0, 100], [100, 200]], $quota->cursorWrites);
    }

    public function test_backlog_cursor_wraps_once_and_deterministically(): void
    {
        for ($index = 0; $index < 50; $index++) {
            $this->insertFixture(18000 + $index, now()->subHour());
        }

        $quota = new InMemoryRepairQuota(static fn (): bool => false);
        $quota->scanCursor = 50;
        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldNotReceive('getFixtureById');
        DB::enableQueryLog();

        (new FinalizeFinishedFixtures)->handle($api, $quota);

        $this->assertSame(50, $quota->scanCursor);
        $this->assertSame([[50, 0], [0, 50]], $quota->cursorWrites);
        $this->assertSame(2, $quota->scanState['generation']);
        $fixtureSelects = array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
                && str_contains(strtolower($query['query']), 'from "fixtures"'),
        );
        $this->assertLessThanOrEqual(3, count($fixtureSelects));
    }

    public function test_new_ingestion_and_mutable_updated_at_cannot_skip_backlog_progress(): void
    {
        $target = null;
        for ($index = 1; $index <= 201; $index++) {
            $fixture = $this->insertFixture(19000 + $index, now()->subMinutes(20 + $index));
            if ($index === 101) {
                $target = $fixture;
            }
        }

        $quota = new InMemoryRepairQuota(
            static fn (int $fixtureId): bool => $fixtureId === $target->id,
        );
        $called = [];
        $api = $this->apiMock(function (int $apiId) use (&$called): array {
            $called[] = $apiId;

            return $this->providerFixture('2H');
        });
        $job = new FinalizeFinishedFixtures;
        $job->handle($api, $quota);

        DB::table('fixtures')->where('id', $target->id)->update(['updated_at' => now()->subMinutes(16)]);
        for ($index = 0; $index < 25; $index++) {
            $this->insertFixture(19500 + $index, now()->subMinutes(16)->addSeconds($index));
        }
        $job->handle($api, $quota);

        $this->assertContains(19101, $called);
        $this->assertGreaterThanOrEqual(101, $quota->scanCursor);
    }

    public function test_recent_lane_quickly_finds_a_newly_stale_fixture_behind_cursor(): void
    {
        $newlyStale = $this->insertFixture(20001, now(), 'NS', 0);
        for ($index = 0; $index < 120; $index++) {
            $this->insertFixture(20100 + $index, now()->subHours(2));
        }

        $quota = new InMemoryRepairQuota(
            static fn (int $fixtureId): bool => $fixtureId === $newlyStale->id,
        );
        $api = $this->apiMock(fn (): array => $this->providerFixture('2H'));
        $job = new FinalizeFinishedFixtures;
        $job->handle($api, $quota);
        $this->assertSame(101, $quota->scanCursor);

        DB::table('fixtures')->where('id', $newlyStale->id)->update([
            'status_short' => '2H',
            'status_long' => 'Second Half',
            'elapsed_minute' => 90,
            'updated_at' => now()->subMinutes(16),
        ]);
        $job->handle($api, $quota);

        $api->shouldHaveReceived('getFixtureById')->with(
            20001,
            'FinalizeFinishedFixtures',
            'fixture_repair',
            Mockery::type(Closure::class),
        )->once();
    }

    public function test_old_backlog_progresses_under_continuous_recent_arrivals(): void
    {
        $target = null;
        for ($index = 1; $index <= 250; $index++) {
            $fixture = $this->insertFixture(21000 + $index, now()->subHours(6));
            if ($index === 201) {
                $target = $fixture;
            }
        }

        $quota = new InMemoryRepairQuota(
            static fn (int $fixtureId): bool => $fixtureId === $target->id,
        );
        $called = [];
        $api = $this->apiMock(function (int $apiId) use (&$called): array {
            $called[] = $apiId;

            return $this->providerFixture('2H');
        });
        $job = new FinalizeFinishedFixtures;

        for ($run = 0; $run < 3; $run++) {
            for ($index = 0; $index < 110; $index++) {
                $this->insertFixture(22000 + ($run * 110) + $index, now()->subMinutes(16)->addSeconds($index));
            }
            $job->handle($api, $quota);
        }

        $this->assertContains(21201, $called);
        $this->assertLessThanOrEqual(5, count($called));
    }

    public function test_cursor_unavailable_or_reset_fails_safely_and_then_resumes(): void
    {
        for ($index = 0; $index < 150; $index++) {
            $this->insertFixture(23000 + $index, now()->subHour());
        }

        $quota = new InMemoryRepairQuota(static fn (): bool => false);
        $quota->cursorReadable = false;
        $quota->cursorWritable = false;
        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldNotReceive('getFixtureById');
        $job = new FinalizeFinishedFixtures;

        $job->handle($api, $quota);
        $this->assertSame(0, $quota->scanCursor);
        $this->assertCount(150, $quota->acquireAttempts);

        $quota->cursorReadable = true;
        $quota->cursorWritable = true;
        $job->handle($api, $quota);
        $this->assertSame(100, $quota->scanCursor);

        $quota->scanCursor = 0;
        $quota->scanState = null;
        $job->handle($api, $quota);
        $this->assertSame(100, $quota->scanCursor);
    }

    public function test_fixed_generation_defeats_the_exact_moving_max_adversary(): void
    {
        $target = null;
        for ($id = 1; $id <= 250; $id++) {
            $fixture = $this->insertFixture(25000 + $id, now()->subHours(8));
            if ($id === 125) {
                $target = $fixture;
            }
        }

        $quota = new InMemoryRepairQuota(
            static fn (int $fixtureId): bool => $fixtureId === $target->id,
        );
        $quota->scanState = ['schema' => 3, 'generation' => 7, 'cursor' => 250, 'ceiling' => 250];
        $quota->scanCursor = 250;
        $called = [];
        $api = $this->apiMock(function (int $apiId) use (&$called): array {
            $called[] = $apiId;

            return $this->providerFixture('2H');
        });
        $job = new FinalizeFinishedFixtures;

        for ($run = 1; $run <= 2; $run++) {
            for ($arrival = 0; $arrival < 101; $arrival++) {
                $this->insertFixture(
                    26000 + (($run - 1) * 101) + $arrival,
                    now()->subMinutes(16)->addSeconds($arrival),
                );
            }

            $job->handle($api, $quota);
            $this->assertSame(8, $quota->scanState['generation']);
            $this->assertSame(351, $quota->scanState['ceiling']);
        }

        $this->assertContains(25125, $called);
        $this->assertLessThanOrEqual(2, count($called));

        for ($run = 3; $run <= 6 && $quota->scanState['generation'] === 8; $run++) {
            for ($arrival = 0; $arrival < 101; $arrival++) {
                $this->insertFixture(
                    26000 + (($run - 1) * 101) + $arrival,
                    now()->subMinutes(16)->addSeconds($arrival),
                );
            }
            $job->handle($api, $quota);
        }

        $this->assertSame(9, $quota->scanState['generation']);
        $this->assertGreaterThan(351, $quota->scanState['ceiling']);
    }

    public function test_generation_cas_rejects_stale_workers_without_regression(): void
    {
        $quota = new InMemoryRepairQuota;
        $quota->scanState = ['schema' => 3, 'generation' => 4, 'cursor' => 0, 'ceiling' => 250];

        $firstWorker = $quota->repairScanState('finalizer-backlog-id');
        $staleWorker = $quota->repairScanState('finalizer-backlog-id');
        $advanced = $firstWorker;
        $advanced['cursor'] = 100;

        $this->assertTrue($quota->compareAndSetRepairScanState(
            'finalizer-backlog-id',
            $firstWorker,
            $advanced,
        ));

        $staleNext = $staleWorker;
        $staleNext['cursor'] = 50;
        $this->assertFalse($quota->compareAndSetRepairScanState(
            'finalizer-backlog-id',
            $staleWorker,
            $staleNext,
        ));
        $this->assertSame($advanced, $quota->scanState);

        $completed = $advanced;
        $completed['cursor'] = 250;
        $this->assertTrue($quota->compareAndSetRepairScanState(
            'finalizer-backlog-id',
            $advanced,
            $completed,
        ));
        $nextGeneration = ['schema' => 3, 'generation' => 5, 'cursor' => 0, 'ceiling' => 400];
        $this->assertTrue($quota->compareAndSetRepairScanState(
            'finalizer-backlog-id',
            $completed,
            $nextGeneration,
        ));
        $this->assertFalse($quota->compareAndSetRepairScanState(
            'finalizer-backlog-id',
            $completed,
            ['schema' => 3, 'generation' => 5, 'cursor' => 0, 'ceiling' => 500],
        ));
        $this->assertSame($nextGeneration, $quota->scanState);
    }

    public function test_v3_state_key_is_stable_per_environment_distinct_between_environments_and_has_ttl(): void
    {
        $redis = new CapturingRepairScanRedis;
        Redis::shouldReceive('connection')->with('cache')->andReturn($redis);
        config()->set('app.name', 'Rezultati Net');

        config()->set('app.env', 'production');
        $productionStore = new RedisApiFootballQuotaStore;
        $initial = ['schema' => 3, 'generation' => 1, 'cursor' => 0, 'ceiling' => 250];
        $this->assertTrue($productionStore->compareAndSetRepairScanState(
            'finalizer-backlog-id',
            null,
            $initial,
        ));
        $productionKey = $redis->keys[0];

        $sameEnvironmentStore = new RedisApiFootballQuotaStore;
        $this->assertSame($initial, $sameEnvironmentStore->repairScanState('finalizer-backlog-id'));
        $this->assertSame($productionKey, $redis->keys[1]);

        config()->set('app.env', 'staging');
        $stagingStore = new RedisApiFootballQuotaStore;
        $this->assertTrue($stagingStore->compareAndSetRepairScanState(
            'finalizer-backlog-id',
            null,
            $initial,
        ));
        $stagingKey = $redis->keys[2];

        $this->assertNotSame($productionKey, $stagingKey);
        $this->assertStringContainsString('repair-scan:v3:env:', $productionKey);
        $this->assertSame([604800, 604800], $redis->ttls);

        $fresh = $initial;
        $fresh['cursor'] = 100;
        config()->set('app.env', 'production');
        $this->assertTrue($productionStore->compareAndSetRepairScanState(
            'finalizer-backlog-id',
            $initial,
            $fresh,
        ));
        $stale = $initial;
        $stale['cursor'] = 50;
        $this->assertFalse($productionStore->compareAndSetRepairScanState(
            'finalizer-backlog-id',
            $initial,
            $stale,
        ));
        $this->assertSame($fresh, $productionStore->repairScanState('finalizer-backlog-id'));
    }

    public function test_empty_generation_and_expired_state_reset_are_deterministic(): void
    {
        $quota = new InMemoryRepairQuota(static fn (): bool => false);
        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldNotReceive('getFixtureById');
        $job = new FinalizeFinishedFixtures;

        $job->handle($api, $quota);
        $this->assertSame(
            ['schema' => 3, 'generation' => 1, 'cursor' => 0, 'ceiling' => 0],
            $quota->scanState,
        );

        $job->handle($api, $quota);
        $this->assertSame(2, $quota->scanState['generation']);

        $this->insertFixture(27001, now()->subHour());
        $quota->scanState = null;
        $quota->scanCursor = 0;
        $job->handle($api, $quota);

        $this->assertSame(1, $quota->scanState['generation']);
        $this->assertSame(1, $quota->scanState['ceiling']);
        $this->assertSame(1, $quota->scanState['cursor']);
    }

    public function test_sparse_and_deleted_ids_advance_by_bounded_numeric_windows(): void
    {
        $this->insertFixtureAtId(1, 28001);
        $this->insertFixtureAtId(10001, 28002);
        $this->insertFixtureAtId(25000, 28003);
        DB::table('fixtures')->where('id', 10001)->delete();

        $quota = new InMemoryRepairQuota(static fn (): bool => false);
        $quota->scanState = ['schema' => 3, 'generation' => 1, 'cursor' => 0, 'ceiling' => 25000];
        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldNotReceive('getFixtureById');
        $job = new FinalizeFinishedFixtures;

        foreach ([10000, 20000, 25000] as $expectedCursor) {
            $before = count($quota->acquireAttempts);
            $job->handle($api, $quota);
            $quota->attemptsPerInvocation[] = count($quota->acquireAttempts) - $before;
            $this->assertSame($expectedCursor, $quota->scanCursor);
        }

        $this->assertLessThanOrEqual(200, max($quota->attemptsPerInvocation));
    }

    public function test_more_than_ten_thousand_candidate_ids_complete_with_bounded_pages(): void
    {
        $this->insertFixtureRows(10001, 29000);

        $quota = new InMemoryRepairQuota(static fn (): bool => false);
        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldNotReceive('getFixtureById');
        $job = new FinalizeFinishedFixtures;

        for ($run = 0; $run < 101; $run++) {
            $before = count($quota->acquireAttempts);
            $job->handle($api, $quota);
            $quota->attemptsPerInvocation[] = count($quota->acquireAttempts) - $before;
        }

        $this->assertSame(10001, $quota->scanState['ceiling']);
        $this->assertSame(10001, $quota->scanState['cursor']);
        $this->assertLessThanOrEqual(200, max($quota->attemptsPerInvocation));
        $this->assertSame([], $quota->records);
    }

    public function test_tied_recent_ordering_is_deterministic_and_provider_cap_is_preserved(): void
    {
        config()->set('api_football.repair.finalizer_per_run', 2);
        for ($index = 0; $index < 10; $index++) {
            $this->insertFixture(24000 + $index, now()->subHour());
        }

        $quota = new InMemoryRepairQuota;
        $called = [];
        $api = $this->apiMock(function (int $apiId) use (&$called): array {
            $called[] = $apiId;

            return $this->providerFixture('2H');
        });

        (new FinalizeFinishedFixtures)->handle($api, $quota);

        $this->assertCount(2, $called);
        $this->assertContains(24009, $called);
        $this->assertContains(24000, $called);
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

    private function insertFixtureAtId(int $id, int $apiId): void
    {
        DB::table('fixtures')->insert([
            'id' => $id,
            'api_fixture_id' => $apiId,
            'league_id' => $this->leagueId,
            'home_team_id' => $this->homeTeamId,
            'away_team_id' => $this->awayTeamId,
            'season' => 2026,
            'round' => 'Round 1',
            'kick_off' => now()->subHours(3),
            'status_long' => '2H',
            'status_short' => '2H',
            'elapsed_minute' => 90,
            'venue_name' => null,
            'referee' => null,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
    }

    private function insertFixtureRows(int $count, int $apiBase): void
    {
        for ($start = 1; $start <= $count; $start += 500) {
            $rows = [];
            $end = min($count, $start + 499);
            for ($id = $start; $id <= $end; $id++) {
                $rows[] = [
                    'id' => $id,
                    'api_fixture_id' => $apiBase + $id,
                    'league_id' => $this->leagueId,
                    'home_team_id' => $this->homeTeamId,
                    'away_team_id' => $this->awayTeamId,
                    'season' => 2026,
                    'round' => 'Round 1',
                    'kick_off' => now()->subHours(3),
                    'status_long' => '2H',
                    'status_short' => '2H',
                    'elapsed_minute' => 90,
                    'venue_name' => null,
                    'referee' => null,
                    'created_at' => now()->subHour(),
                    'updated_at' => now()->subHour(),
                ];
            }
            DB::table('fixtures')->insert($rows);
        }
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

    /** @var list<int> */
    public array $attemptsPerInvocation = [];

    /** @var list<array{fixture_id: int, outcome: string, terminal: bool}> */
    public array $records = [];

    /** @var array<int, bool> */
    public array $terminalFixtures = [];

    public array $reservation = ['allowed' => true];

    public int $reserveCalls = 0;

    public int $scanCursor = 0;

    /** @var array{schema: int, generation: int, cursor: int, ceiling: int}|null */
    public ?array $scanState = null;

    public bool $cursorReadable = true;

    public bool $cursorWritable = true;

    /** @var list<array{0: int, 1: int}> */
    public array $cursorWrites = [];

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

    public function repairScanState(string $scan): ?array
    {
        if (! $this->cursorReadable) {
            return null;
        }

        if ($this->scanState === null && $this->scanCursor > 0) {
            $this->scanState = [
                'schema' => 3,
                'generation' => 1,
                'cursor' => $this->scanCursor,
                'ceiling' => $this->scanCursor,
            ];
        }

        return $this->scanState;
    }

    public function compareAndSetRepairScanState(string $scan, ?array $expected, array $next): bool
    {
        if (! $this->cursorWritable || $this->scanState !== $expected) {
            return false;
        }

        if ($expected === null) {
            if ($next['generation'] !== 1 || $next['cursor'] !== 0) {
                return false;
            }
        } elseif ($next['generation'] === $expected['generation']) {
            if ($next['ceiling'] !== $expected['ceiling'] || $next['cursor'] < $expected['cursor']) {
                return false;
            }
        } elseif (
            $next['generation'] !== $expected['generation'] + 1
            || $expected['cursor'] < $expected['ceiling']
            || $next['cursor'] !== 0
        ) {
            return false;
        }

        $this->cursorWrites[] = [$expected['cursor'] ?? 0, $next['cursor']];
        $this->scanState = $next;
        $this->scanCursor = $next['cursor'];

        return true;
    }
}

final class CapturingRepairScanRedis
{
    /** @var array<string, string> */
    public array $values = [];

    /** @var list<string> */
    public array $keys = [];

    /** @var list<int> */
    public array $ttls = [];

    public function get(string $key): ?string
    {
        $this->keys[] = $key;

        return $this->values[$key] ?? null;
    }

    public function eval(
        string $script,
        int $numberOfKeys,
        string $key,
        string $expected,
        string $next,
        int $ttl,
    ): int {
        $this->keys[] = $key;
        $current = $this->values[$key] ?? null;
        if (($current ?? '') !== $expected) {
            return 0;
        }

        $nextState = json_decode($next, true, flags: JSON_THROW_ON_ERROR);
        if ($current === null) {
            if ($nextState['generation'] !== 1 || $nextState['cursor'] !== 0) {
                return 0;
            }
        } else {
            $previous = json_decode($current, true, flags: JSON_THROW_ON_ERROR);
            if ($nextState['generation'] === $previous['generation']) {
                if (
                    $nextState['ceiling'] !== $previous['ceiling']
                    || $nextState['cursor'] < $previous['cursor']
                ) {
                    return 0;
                }
            } elseif (
                $nextState['generation'] !== $previous['generation'] + 1
                || $previous['cursor'] < $previous['ceiling']
                || $nextState['cursor'] !== 0
            ) {
                return 0;
            }
        }

        $this->values[$key] = $next;
        $this->ttls[] = $ttl;

        return 1;
    }
}
