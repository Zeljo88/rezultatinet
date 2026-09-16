<?php

namespace Tests\Feature;

use App\Contracts\ApiFootballQuotaStore;
use App\Exceptions\ApiFootballBlocked;
use App\Jobs\FetchLiveFixtures;
use App\Services\ApiBasketballService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiFootballSafetyTest extends TestCase
{
    public function test_manual_basketball_and_tennis_commands_make_no_provider_calls(): void
    {
        config()->set('api_football.enabled_sports', ['football']);
        Http::fake();
        $this->artisan('sync:basketball')->assertFailed()->expectsOutputToContain('disabled');
        $this->artisan('sync:tennis')->assertFailed()->expectsOutputToContain('disabled');
        Http::assertNothingSent();
    }

    public function test_nonfootball_services_fail_closed(): void
    {
        $this->expectException(ApiFootballBlocked::class);
        (new ApiBasketballService)->getGamesByDate('2026-09-16');
    }

    public function test_live_job_has_queue_single_flight_and_no_job_retry_amplification(): void
    {
        $job = new FetchLiveFixtures;
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(1, $job->tries);
        $this->assertSame('api-football-live-poll', $job->uniqueId());
        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
    }

    public function test_quota_status_command_is_read_only_and_displays_circuit_and_breakdown(): void
    {
        $fake = new class implements ApiFootballQuotaStore
        {
            public function reserve(string $endpointClass, string $caller): array
            {
                throw new \LogicException;
            }

            public function record(string $endpointClass, string $caller, int|string $status, string $outcome): void
            {
                throw new \LogicException;
            }

            public function openCircuit(int $until, string $reason): void
            {
                throw new \LogicException;
            }

            public function acquireRepair(int $fixtureId, string $caller): bool
            {
                throw new \LogicException;
            }

            public function recordRepair(int $fixtureId, string $outcome, bool $terminal = false): void
            {
                throw new \LogicException;
            }

            public function status(): array
            {
                return ['day' => '2026-09-16', 'global' => 12, 'threshold_state' => 'normal', 'classes' => ['live' => 10], 'callers' => ['live|FetchLiveFixtures' => 10], 'outcomes' => ['live|FetchLiveFixtures|200|success' => 10], 'circuit_until' => 0, 'circuit_reason' => null, 'reset_at' => '2026-09-17T00:00:00+00:00'];
            }
        };
        $this->app->instance(ApiFootballQuotaStore::class, $fake);
        $this->artisan('api-football:quota-status')->assertSuccessful()->expectsOutputToContain('observed physical')->expectsOutputToContain('FetchLiveFixtures');
    }

    public function test_repair_configuration_has_combined_budget_cooldown_attempt_cap_and_per_run_caps(): void
    {
        $this->assertSame(500, config('api_football.budgets.fixture_repair'));
        $this->assertGreaterThanOrEqual(7200, config('api_football.repair.base_cooldown_seconds'));
        $this->assertLessThanOrEqual(4, config('api_football.repair.max_attempts_per_fixture'));
        $this->assertLessThanOrEqual(5, config('api_football.repair.finalizer_per_run'));
        $this->assertLessThanOrEqual(5, config('api_football.repair.zombie_per_run'));
    }
}
