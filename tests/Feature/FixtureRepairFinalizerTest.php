<?php

namespace Tests\Feature;

use App\Contracts\ApiFootballQuotaStore;
use App\Exceptions\ApiFootballBlocked;
use App\Jobs\FinalizeFinishedFixtures;
use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Services\ApiFootball\ApiFootballGateway;
use App\Services\ApiFootball\RedisApiFootballQuotaStore;
use App\Services\ApiFootballService;
use App\Support\ApiFootballBlockReason;
use App\Support\FootballFixtureStatus;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
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

    public function test_retrying_recent_arrivals_cannot_starve_target_125_or_create_a_sixth_request(): void
    {
        config()->set('api_football.retry_base_ms', 0);
        for ($id = 1; $id <= 250; $id++) {
            $this->insertFixture(25000 + $id, now()->subHours(8));
        }

        $quota = new InMemoryRepairQuota;
        $quota->scanState = ['schema' => 3, 'generation' => 1, 'cursor' => 0, 'ceiling' => 250];
        $requested = [];
        Http::fake(function ($request) use (&$requested) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $requested[] = (int) ($query['id'] ?? 0);

            return Http::response([], 500);
        });
        $api = new ApiFootballService(new ApiFootballGateway($quota));
        $job = new FinalizeFinishedFixtures;
        $targetRun = null;

        for ($run = 1; $run <= 63; $run++) {
            for ($arrival = 0; $arrival < 101; $arrival++) {
                $this->insertFixture(
                    40000 + (($run - 1) * 101) + $arrival,
                    now()->subMinutes(20)->addSeconds($arrival),
                );
            }

            $before = count($requested);
            $job->handle($api, $quota);
            $this->assertSame(5, count($requested) - $before, "run {$run} physical attempt count");
            if (in_array(25125, $requested, true)) {
                $targetRun = $run;
                break;
            }
        }

        $this->assertNotNull($targetRun);
        $this->assertLessThanOrEqual(63, $targetRun);
        $this->assertGreaterThanOrEqual(125, $quota->scanState['cursor']);
        $this->assertNotEmpty(array_filter($requested, static fn (int $id): bool => $id >= 40000));
        $this->assertSame([], $quota->records, 'Retryable failures must not exhaust fixture repair eligibility.');
    }

    #[DataProvider('typedExternalDenials')]
    public function test_external_denials_are_typed_and_never_send_http(
        string $reservationReason,
        ApiFootballBlockReason $expectedReason,
    ): void {
        $quota = new InMemoryRepairQuota;
        $quota->reservation = ['allowed' => false, 'reason' => $reservationReason];
        Http::fake();

        try {
            (new ApiFootballGateway($quota))->get('/fixtures', ['id' => 1], 'fixture_repair', 'test', static fn (): bool => true);
            $this->fail('Expected an external denial.');
        } catch (ApiFootballBlocked $blocked) {
            $this->assertSame($expectedReason, $blocked->reason);
        }

        Http::assertNothingSent();
        $this->assertSame(1, $quota->reserveCalls);
    }

    public static function typedExternalDenials(): array
    {
        return [
            'fixture repair sub-budget' => ['class_hard_stop', ApiFootballBlockReason::FixtureRepairBudget],
            'global quota' => ['global_hard_stop', ApiFootballBlockReason::GlobalQuota],
            'circuit breaker' => ['circuit', ApiFootballBlockReason::Circuit],
        ];
    }

    public function test_accounting_unavailability_is_typed_and_fails_closed(): void
    {
        $quota = new InMemoryRepairQuota;
        $quota->reserveThrows = true;
        Http::fake();

        try {
            (new ApiFootballGateway($quota))->get('/fixtures', ['id' => 1], 'fixture_repair', 'test', static fn (): bool => true);
            $this->fail('Expected an accounting denial.');
        } catch (ApiFootballBlocked $blocked) {
            $this->assertSame(ApiFootballBlockReason::AccountingUnavailable, $blocked->reason);
        }

        Http::assertNothingSent();
    }

    public function test_provider_429_is_typed_external_block_and_does_not_advance_cursor(): void
    {
        $fixture = $this->insertFixture(51001, now()->subHour());
        $quota = new InMemoryRepairQuota;
        $quota->scanState = ['schema' => 3, 'generation' => 1, 'cursor' => 0, 'ceiling' => $fixture->id];
        Http::fakeSequence()->push([], 429, ['Retry-After' => '60']);

        (new FinalizeFinishedFixtures)->handle(
            new ApiFootballService(new ApiFootballGateway($quota)),
            $quota,
        );

        Http::assertSentCount(1);
        $this->assertSame(0, $quota->scanState['cursor']);
        $this->assertSame([], $quota->records);
        $this->assertSame([$fixture->id], $quota->released);
    }

    public function test_connection_failures_obey_cap_advance_and_remain_retryable(): void
    {
        config()->set('api_football.retry_base_ms', 0);
        for ($id = 1; $id <= 4; $id++) {
            $this->insertFixture(52000 + $id, now()->subHour());
        }
        $quota = new InMemoryRepairQuota;
        $attempts = 0;
        Http::fake(static function () use (&$attempts): never {
            $attempts++;

            throw new ConnectionException('transport down');
        });

        (new FinalizeFinishedFixtures)->handle(
            new ApiFootballService(new ApiFootballGateway($quota)),
            $quota,
        );

        $this->assertSame(5, $attempts);
        $this->assertSame(5, $quota->reserveCalls);
        $this->assertGreaterThan(0, $quota->scanState['cursor']);
        $this->assertSame([], $quota->records);
    }

    public function test_cas_contention_during_local_exhaustion_cannot_regress_state(): void
    {
        config()->set('api_football.retry_base_ms', 0);
        for ($id = 1; $id <= 4; $id++) {
            $this->insertFixture(53000 + $id, now()->subHour());
        }
        $quota = new InMemoryRepairQuota;
        $quota->scanState = ['schema' => 3, 'generation' => 1, 'cursor' => 0, 'ceiling' => 4];
        $quota->rejectNextCursorWrite = true;
        Http::fake(static fn () => Http::response([], 500));
        $job = new FinalizeFinishedFixtures;
        $api = new ApiFootballService(new ApiFootballGateway($quota));

        $job->handle($api, $quota);
        $this->assertSame(0, $quota->scanState['cursor']);
        Http::assertSentCount(5);

        $job->handle($api, $quota);
        $this->assertGreaterThan(0, $quota->scanState['cursor']);
        Http::assertSentCount(10);
    }

    public function test_malformed_scan_state_self_heals_atomically_with_ttl(): void
    {
        $redis = new CapturingRepairScanRedis;
        Redis::shouldReceive('connection')->with('cache')->andReturn($redis);
        $store = new RedisApiFootballQuotaStore;
        $initial = ['schema' => 3, 'generation' => 1, 'cursor' => 0, 'ceiling' => 10];

        $this->assertTrue($store->compareAndSetRepairScanState('finalizer-backlog-id', null, $initial));
        $key = $redis->keys[0];
        $redis->values[$key] = '{malformed';
        $this->assertNull($store->repairScanState('finalizer-backlog-id'));
        $this->assertTrue($store->compareAndSetRepairScanState('finalizer-backlog-id', null, $initial));
        $this->assertSame($initial, $store->repairScanState('finalizer-backlog-id'));
        $this->assertSame(604800, end($redis->ttls));
    }

    #[DataProvider('repairScanStateRepresentations')]
    public function test_php_and_lua_state_representation_matrix(
        string $raw,
        ?array $expected,
    ): void {
        $redis = new CapturingRepairScanRedis;
        Redis::shouldReceive('connection')->with('cache')->andReturn($redis);
        $store = new RedisApiFootballQuotaStore;
        $initial = ['schema' => 3, 'generation' => 1, 'cursor' => 0, 'ceiling' => 10];

        $this->assertTrue($store->compareAndSetRepairScanState('finalizer-backlog-id', null, $initial));
        $key = $redis->keys[0];
        $redis->values[$key] = $raw;
        $redis->ttlByKey[$key] = 37;

        if ($expected === null) {
            $this->assertNull($store->repairScanState('finalizer-backlog-id'));
            $this->assertTrue($store->compareAndSetRepairScanState(
                'finalizer-backlog-id',
                null,
                $initial,
            ));
            $this->assertSame(json_encode($initial, JSON_THROW_ON_ERROR), $redis->values[$key]);
            $this->assertSame(604800, $redis->ttlByKey[$key]);

            return;
        }

        $this->assertSame($expected, $store->repairScanState('finalizer-backlog-id'));
        $next = $expected;
        $next['cursor']++;
        $this->assertTrue($store->compareAndSetRepairScanState(
            'finalizer-backlog-id',
            $expected,
            $next,
        ));
        $this->assertSame(json_encode($next, JSON_THROW_ON_ERROR), $redis->values[$key]);
        $this->assertSame(604800, $redis->ttlByKey[$key]);

        $ttlWrites = count($redis->ttls);
        $this->assertFalse($store->compareAndSetRepairScanState(
            'finalizer-backlog-id',
            $expected,
            $next,
        ));
        $this->assertCount($ttlWrites, $redis->ttls);
        $this->assertSame($next, $store->repairScanState('finalizer-backlog-id'));
    }

    public static function repairScanStateRepresentations(): array
    {
        $valid = ['schema' => 3, 'generation' => 1, 'cursor' => 0, 'ceiling' => 10];
        $maximum = ApiFootballQuotaStore::REPAIR_SCAN_STATE_MAX_INTEGER;

        return [
            'canonical' => ['{"schema":3,"generation":1,"cursor":0,"ceiling":10}', $valid],
            'reordered keys' => ['{"ceiling":10,"cursor":0,"generation":1,"schema":3}', $valid],
            'whitespace' => [" { \n \t\"schema\" : 3, \"generation\" : 1, \"cursor\" : 0, \"ceiling\" : 10 } ", $valid],
            'integral decimal JSON numbers' => ['{"schema":3.0,"generation":1.0,"cursor":0.0,"ceiling":10.0}', $valid],
            'integral exponent JSON numbers' => ['{"schema":3e0,"generation":1e0,"cursor":0e0,"ceiling":1e1}', $valid],
            'maximum safe integers' => [
                '{"schema":3,"generation":'.($maximum - 1).',"cursor":'.($maximum - 1).',"ceiling":'.$maximum.'}',
                ['schema' => 3, 'generation' => $maximum - 1, 'cursor' => $maximum - 1, 'ceiling' => $maximum],
            ],
            'numeric strings' => ['{"schema":3,"generation":"1","cursor":"0","ceiling":"10"}', null],
            'fractional schema' => ['{"schema":3.5,"generation":1,"cursor":0,"ceiling":10}', null],
            'fractional generation' => ['{"schema":3,"generation":1.5,"cursor":0,"ceiling":10}', null],
            'fractional cursor' => ['{"schema":3,"generation":1,"cursor":0.5,"ceiling":10}', null],
            'fractional ceiling' => ['{"schema":3,"generation":1,"cursor":0,"ceiling":10.5}', null],
            'negative generation' => ['{"schema":3,"generation":-1,"cursor":0,"ceiling":10}', null],
            'negative cursor' => ['{"schema":3,"generation":1,"cursor":-1,"ceiling":10}', null],
            'negative ceiling' => ['{"schema":3,"generation":1,"cursor":0,"ceiling":-1}', null],
            'overflow generation' => ['{"schema":3,"generation":9007199254740992,"cursor":0,"ceiling":10}', null],
            'overflow cursor' => ['{"schema":3,"generation":1,"cursor":9007199254740992,"ceiling":9007199254740992}', null],
            'overflow exponent' => ['{"schema":3,"generation":1e309,"cursor":0,"ceiling":10}', null],
            'missing schema' => ['{"generation":1,"cursor":0,"ceiling":10}', null],
            'missing generation' => ['{"schema":3,"cursor":0,"ceiling":10}', null],
            'missing cursor' => ['{"schema":3,"generation":1,"ceiling":10}', null],
            'missing ceiling' => ['{"schema":3,"generation":1,"cursor":0}', null],
            'unknown field' => ['{"schema":3,"generation":1,"cursor":0,"ceiling":10,"other":0}', null],
            'null field' => ['{"schema":3,"generation":null,"cursor":0,"ceiling":10}', null],
            'boolean field' => ['{"schema":3,"generation":true,"cursor":0,"ceiling":10}', null],
            'array field' => ['{"schema":3,"generation":[],"cursor":0,"ceiling":10}', null],
            'object field' => ['{"schema":3,"generation":{},"cursor":0,"ceiling":10}', null],
            'top-level array' => ['[3,1,0,10]', null],
            'top-level null' => ['null', null],
            'top-level boolean' => ['true', null],
            'invalid JSON' => ['{"schema":3', null],
            'invalid UTF-8' => ['{"schema":3,"generation":1,"cursor":0,"ceiling":"'.chr(0xB1).'"}', null],
            'cursor above ceiling' => ['{"schema":3,"generation":1,"cursor":11,"ceiling":10}', null],
            'wrong version' => ['{"schema":2,"generation":1,"cursor":0,"ceiling":10}', null],
            'zero generation' => ['{"schema":3,"generation":0,"cursor":0,"ceiling":10}', null],
        ];
    }

    #[DataProvider('invalidRepairScanStateArrays')]
    public function test_php_rejects_every_invalid_next_state_without_calling_lua(array $state): void
    {
        $redis = new CapturingRepairScanRedis;
        Redis::shouldReceive('connection')->with('cache')->andReturn($redis);
        $store = new RedisApiFootballQuotaStore;

        try {
            $store->compareAndSetRepairScanState('finalizer-backlog-id', null, $state);
            $this->fail('Invalid PHP state reached the Lua CAS.');
        } catch (\UnexpectedValueException) {
            // Expected: invalid caller state is rejected before Redis access.
        }

        $this->assertSame([], $redis->keys);
    }

    public static function invalidRepairScanStateArrays(): array
    {
        $valid = ['schema' => 3, 'generation' => 1, 'cursor' => 0, 'ceiling' => 10];

        return [
            'numeric string' => [array_replace($valid, ['generation' => '1'])],
            'fraction' => [array_replace($valid, ['cursor' => 0.5])],
            'negative' => [array_replace($valid, ['cursor' => -1])],
            'overflow' => [array_replace($valid, ['ceiling' => 9007199254740992])],
            'not finite' => [array_replace($valid, ['ceiling' => INF])],
            'null' => [array_replace($valid, ['generation' => null])],
            'boolean' => [array_replace($valid, ['generation' => true])],
            'array' => [array_replace($valid, ['generation' => []])],
            'object' => [array_replace($valid, ['generation' => new \stdClass])],
            'missing' => [array_diff_key($valid, ['cursor' => true])],
            'extra' => [$valid + ['other' => 0]],
            'cursor above ceiling' => [array_replace($valid, ['cursor' => 11])],
            'wrong version' => [array_replace($valid, ['schema' => 2])],
        ];
    }

    public function test_invalid_state_heal_cannot_overwrite_a_concurrent_valid_writer(): void
    {
        $redis = new CapturingRepairScanRedis;
        Redis::shouldReceive('connection')->with('cache')->andReturn($redis);
        $store = new RedisApiFootballQuotaStore;
        $initial = ['schema' => 3, 'generation' => 1, 'cursor' => 0, 'ceiling' => 10];

        $this->assertTrue($store->compareAndSetRepairScanState('finalizer-backlog-id', null, $initial));
        $key = $redis->keys[0];
        $redis->values[$key] = '{invalid';
        $redis->ttlByKey[$key] = 19;
        $concurrent = ['schema' => 3, 'generation' => 7, 'cursor' => 40, 'ceiling' => 100];
        $redis->beforeEval = static function (CapturingRepairScanRedis $redis, string $key) use ($concurrent): void {
            $redis->values[$key] = json_encode($concurrent, JSON_THROW_ON_ERROR);
            $redis->ttlByKey[$key] = 321;
        };

        $this->assertNull($store->repairScanState('finalizer-backlog-id'));
        $ttlWrites = count($redis->ttls);
        $this->assertFalse($store->compareAndSetRepairScanState(
            'finalizer-backlog-id',
            null,
            $initial,
        ));
        $this->assertSame(json_encode($concurrent, JSON_THROW_ON_ERROR), $redis->values[$key]);
        $this->assertSame(321, $redis->ttlByKey[$key]);
        $this->assertCount($ttlWrites, $redis->ttls);
    }

    public function test_generation_change_prevents_stale_expected_state_aba(): void
    {
        $redis = new CapturingRepairScanRedis;
        Redis::shouldReceive('connection')->with('cache')->andReturn($redis);
        $store = new RedisApiFootballQuotaStore;
        $first = ['schema' => 3, 'generation' => 1, 'cursor' => 0, 'ceiling' => 10];
        $completed = ['schema' => 3, 'generation' => 1, 'cursor' => 10, 'ceiling' => 10];
        $second = ['schema' => 3, 'generation' => 2, 'cursor' => 0, 'ceiling' => 10];

        $this->assertTrue($store->compareAndSetRepairScanState('finalizer-backlog-id', null, $first));
        $this->assertTrue($store->compareAndSetRepairScanState('finalizer-backlog-id', $first, $completed));
        $this->assertTrue($store->compareAndSetRepairScanState('finalizer-backlog-id', $completed, $second));
        $staleNext = $completed;
        $staleNext['cursor'] = 10;
        $this->assertFalse($store->compareAndSetRepairScanState(
            'finalizer-backlog-id',
            $completed,
            $staleNext,
        ));
        $this->assertSame($second, $store->repairScanState('finalizer-backlog-id'));
    }

    public function test_max_generation_is_a_deterministic_terminal_state_without_overflow(): void
    {
        $quota = new InMemoryRepairQuota(static fn (): bool => false);
        $quota->scanState = [
            'schema' => 3,
            'generation' => ApiFootballQuotaStore::REPAIR_SCAN_STATE_MAX_INTEGER,
            'cursor' => 0,
            'ceiling' => 0,
        ];
        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldNotReceive('getFixtureById');

        (new FinalizeFinishedFixtures)->handle($api, $quota);

        $this->assertSame(ApiFootballQuotaStore::REPAIR_SCAN_STATE_MAX_INTEGER, $quota->scanState['generation']);
        $this->assertSame([], $quota->cursorWrites);
    }

    public function test_recent_only_work_reclaims_all_five_physical_attempts(): void
    {
        for ($id = 1; $id <= 5; $id++) {
            $this->insertFixture(54000 + $id, now()->subMinutes(20 + $id));
        }
        $quota = new InMemoryRepairQuota;
        $quota->scanState = [
            'schema' => 3,
            'generation' => ApiFootballQuotaStore::REPAIR_SCAN_STATE_MAX_INTEGER,
            'cursor' => 0,
            'ceiling' => 0,
        ];
        Http::fake(static fn () => Http::response(['response' => [[
            'fixture' => ['status' => ['short' => '2H', 'long' => '2H', 'elapsed' => 90]],
            'goals' => ['home' => 1, 'away' => 1],
            'score' => [],
        ]]], 200));

        (new FinalizeFinishedFixtures)->handle(
            new ApiFootballService(new ApiFootballGateway($quota)),
            $quota,
        );

        Http::assertSentCount(5);
        $this->assertCount(5, $quota->records);
    }

    public function test_all_backlog_work_can_use_the_full_run_capacity(): void
    {
        for ($id = 1; $id <= 5; $id++) {
            $this->insertFixture(55000 + $id, now()->subHours(8));
        }
        $quota = new InMemoryRepairQuota;
        $quota->scanState = ['schema' => 3, 'generation' => 1, 'cursor' => 0, 'ceiling' => 5];
        $requested = [];
        Http::fake(function ($request) use (&$requested) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $requested[] = (int) ($query['id'] ?? 0);

            return Http::response(['response' => [[
                'fixture' => ['status' => ['short' => '2H', 'long' => '2H', 'elapsed' => 90]],
                'goals' => ['home' => 1, 'away' => 1],
                'score' => [],
            ]]], 200);
        });

        (new FinalizeFinishedFixtures)->handle(
            new ApiFootballService(new ApiFootballGateway($quota)),
            $quota,
        );

        $this->assertCount(5, $requested);
        $this->assertSame([55001, 55005, 55002, 55004, 55003], $requested);
        $this->assertSame(3, $quota->scanState['cursor']);
    }

    public function test_retryable_failure_returns_in_a_later_fixed_generation(): void
    {
        config()->set('api_football.retry_base_ms', 0);
        $this->insertFixture(56001, now()->subHours(8));
        $this->insertFixture(56002, now()->subHours(8));
        $quota = new InMemoryRepairQuota;
        $quota->scanState = ['schema' => 3, 'generation' => 1, 'cursor' => 0, 'ceiling' => 2];
        $requested = [];
        Http::fake(function ($request) use (&$requested) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $requested[] = (int) ($query['id'] ?? 0);

            return Http::response([], 500);
        });
        $job = new FinalizeFinishedFixtures;
        $api = new ApiFootballService(new ApiFootballGateway($quota));

        $job->handle($api, $quota);
        $this->assertSame(2, $quota->scanState['cursor']);
        $job->handle($api, $quota);

        $this->assertSame(2, $quota->scanState['generation']);
        $this->assertGreaterThanOrEqual(2, count(array_filter(
            $requested,
            static fn (int $id): bool => $id === 56001,
        )));
        $this->assertSame([], $quota->records);
    }

    public function test_first_claim_exhaustion_never_advances_past_an_unrequested_old_candidate(): void
    {
        config()->set('api_football.retry_base_ms', 0);
        for ($id = 1; $id <= 200; $id++) {
            $this->insertFixture(57000 + $id, now()->subHours(8));
        }
        $quota = new InMemoryRepairQuota(
            static fn (int $fixtureId): bool => $fixtureId <= 100 || $fixtureId === 200,
        );
        $quota->scanState = ['schema' => 3, 'generation' => 1, 'cursor' => 0, 'ceiling' => 200];
        $payload = ['response' => [[
            'fixture' => ['status' => ['short' => '2H', 'long' => '2H', 'elapsed' => 90]],
            'goals' => ['home' => 1, 'away' => 1],
            'score' => [],
        ]]];
        Http::fakeSequence()
            ->push([], 500)
            ->push($payload, 200)
            ->push([], 500)
            ->push($payload, 200)
            ->push($payload, 200);

        (new FinalizeFinishedFixtures)->handle(
            new ApiFootballService(new ApiFootballGateway($quota)),
            $quota,
        );

        Http::assertSentCount(5);
        $this->assertSame(2, $quota->scanState['cursor']);
        $this->assertContains(3, $quota->released);
        $this->assertNotContains(57003, array_map(
            static function ($request): int {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return (int) ($query['id'] ?? 0);
            },
            Http::recorded()->pluck(0)->all(),
        ));
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

    public bool $reserveThrows = false;

    public int $scanCursor = 0;

    /** @var array{schema: int, generation: int, cursor: int, ceiling: int}|null */
    public ?array $scanState = null;

    public bool $cursorReadable = true;

    public bool $cursorWritable = true;

    public bool $rejectNextCursorWrite = false;

    /** @var list<array{0: int, 1: int}> */
    public array $cursorWrites = [];

    /** @var array<int, bool> */
    private array $locked = [];

    public function __construct(private readonly ?Closure $eligibility = null) {}

    public function reserve(string $endpointClass, string $caller): array
    {
        if ($this->reserveThrows) {
            throw new \RuntimeException('quota unavailable');
        }
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
        if ($this->rejectNextCursorWrite) {
            $this->rejectNextCursorWrite = false;

            return false;
        }
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

    /** @var array<string, int> */
    public array $ttlByKey = [];

    public ?Closure $beforeEval = null;

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
        if ($this->beforeEval !== null) {
            $callback = $this->beforeEval;
            $this->beforeEval = null;
            $callback($this, $key);
        }

        $current = $this->values[$key] ?? null;
        $previousState = $current === null ? null : $this->decodeState($current);
        $expectedState = $expected === '' ? null : $this->decodeState($expected);
        if ($previousState !== null) {
            if ($expectedState === null || $previousState != $expectedState) {
                return 0;
            }
        } elseif ($expectedState !== null) {
            return 0;
        }

        $nextState = $this->decodeState($next);
        if ($nextState === null) {
            return 0;
        }

        if ($previousState === null) {
            if ($nextState['generation'] !== 1 || $nextState['cursor'] !== 0) {
                return 0;
            }
        } else {
            if ($nextState['generation'] === $previousState['generation']) {
                if (
                    $nextState['ceiling'] !== $previousState['ceiling']
                    || $nextState['cursor'] < $previousState['cursor']
                ) {
                    return 0;
                }
            } elseif (
                $nextState['generation'] !== $previousState['generation'] + 1
                || $previousState['cursor'] < $previousState['ceiling']
                || $nextState['cursor'] !== 0
            ) {
                return 0;
            }
        }

        $this->values[$key] = $next;
        $this->ttls[] = $ttl;
        $this->ttlByKey[$key] = $ttl;

        return 1;
    }

    /**
     * Mirrors the Lua state validator so the matrix exercises both the PHP
     * reader and the exact semantic decisions made by the atomic script.
     *
     * @return array{schema: int, generation: int, cursor: int, ceiling: int}|null
     */
    private function decodeState(string $json): ?array
    {
        try {
            $state = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        $fields = ['schema', 'generation', 'cursor', 'ceiling'];
        if (! is_array($state) || count($state) !== count($fields)) {
            return null;
        }
        foreach ($fields as $field) {
            $value = $state[$field] ?? null;
            if (
                ! array_key_exists($field, $state)
                || (! is_int($value) && ! is_float($value))
                || ! is_finite((float) $value)
                || $value < 0
                || $value > ApiFootballQuotaStore::REPAIR_SCAN_STATE_MAX_INTEGER
                || floor((float) $value) !== (float) $value
            ) {
                return null;
            }
            $state[$field] = (int) $value;
        }

        if (
            $state['schema'] !== ApiFootballQuotaStore::REPAIR_SCAN_STATE_SCHEMA
            || $state['generation'] < 1
            || $state['cursor'] > $state['ceiling']
        ) {
            return null;
        }

        return $state;
    }
}
