<?php

namespace Tests\Unit;

use App\Contracts\ApiFootballQuotaStore;
use App\Exceptions\ApiFootballBlocked;
use App\Services\ApiFootball\ApiFootballGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiFootballGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('api_football.enabled_sports', ['football']);
        config()->set('api_football.retry_base_ms', 0);
        config()->set('api_football.max_attempts', 2);
        config()->set('services.api_football.key', 'test-key');
    }

    public function test_atomic_hard_stop_prevents_physical_request(): void
    {
        $store = new GatewayFakeQuotaStore(globalLimit: 0);
        Http::fake();
        $this->expectException(ApiFootballBlocked::class);
        (new ApiFootballGateway($store))->get('/fixtures', ['live' => 'all'], 'live', 'test');
        Http::assertNothingSent();
    }

    public function test_endpoint_sub_budget_prevents_physical_request(): void
    {
        $store = new GatewayFakeQuotaStore(classLimits: ['standings' => 0]);
        Http::fake();
        try {
            (new ApiFootballGateway($store))->get('/standings', [], 'standings', 'test');
        } catch (ApiFootballBlocked) {
        }
        Http::assertNothingSent();
        $this->assertSame(0, $store->physicalReservations);
    }

    public function test_every_transient_retry_is_reserved_and_total_attempts_are_capped_at_two(): void
    {
        $store = new GatewayFakeQuotaStore;
        Http::fakeSequence()->pushStatus(503)->push(['response' => [['ok' => true]]], 200);
        $result = (new ApiFootballGateway($store))->get('/fixtures', ['live' => 'all'], 'live', 'test');
        $this->assertCount(1, $result);
        $this->assertSame(2, $store->physicalReservations);
        Http::assertSentCount(2);
    }

    public function test_429_retry_after_opens_circuit_without_retry(): void
    {
        $store = new GatewayFakeQuotaStore;
        Http::fakeSequence()->push([], 429, ['Retry-After' => '120']);
        (new ApiFootballGateway($store))->get('/fixtures', [], 'live', 'test');
        Http::assertSentCount(1);
        $this->assertGreaterThanOrEqual(time() + 115, $store->circuitUntil);
        $this->assertSame(1, $store->physicalReservations);
    }

    public function test_non_429_4xx_is_never_retried(): void
    {
        $store = new GatewayFakeQuotaStore;
        Http::fakeSequence()->pushStatus(422)->pushStatus(200);
        (new ApiFootballGateway($store))->get('/fixtures', [], 'live', 'test');
        Http::assertSentCount(1);
        $this->assertSame(1, $store->physicalReservations);
    }

    public function test_two_transient_failures_never_make_a_third_attempt(): void
    {
        $store = new GatewayFakeQuotaStore;
        Http::fakeSequence()->pushStatus(500)->pushStatus(502)->pushStatus(200);
        (new ApiFootballGateway($store))->get('/fixtures', [], 'live', 'test');
        Http::assertSentCount(2);
        $this->assertSame(2, $store->physicalReservations);
    }

    public function test_sport_allowlist_fails_closed(): void
    {
        config()->set('api_football.enabled_sports', []);
        Http::fake();
        $this->expectException(ApiFootballBlocked::class);
        (new ApiFootballGateway(new GatewayFakeQuotaStore))->get('/fixtures', [], 'live', 'test');
        Http::assertNothingSent();
    }
}

class GatewayFakeQuotaStore implements ApiFootballQuotaStore
{
    public int $physicalReservations = 0;

    public int $circuitUntil = 0;

    private array $classes = [];

    public function __construct(private int $globalLimit = 7125, private array $classLimits = []) {}

    public function reserve(string $endpointClass, string $caller): array
    {
        $limit = $this->classLimits[$endpointClass] ?? 9999;
        $used = $this->classes[$endpointClass] ?? 0;
        if ($this->physicalReservations >= $this->globalLimit) {
            return ['allowed' => false, 'reason' => 'global_hard_stop'];
        }
        if ($used >= $limit) {
            return ['allowed' => false, 'reason' => 'class_hard_stop'];
        }
        $this->physicalReservations++;
        $this->classes[$endpointClass] = $used + 1;

        return ['allowed' => true, 'global' => $this->physicalReservations, 'class' => $used + 1, 'state' => 'normal'];
    }

    public function record(string $endpointClass, string $caller, int|string $status, string $outcome): void {}

    public function openCircuit(int $until, string $reason): void
    {
        $this->circuitUntil = $until;
    }

    public function status(): array
    {
        return ['day' => '2026-09-16', 'global' => $this->physicalReservations, 'threshold_state' => 'normal', 'classes' => $this->classes, 'callers' => [], 'outcomes' => [], 'circuit_until' => $this->circuitUntil, 'circuit_reason' => null, 'reset_at' => '2026-09-17T00:00:00+00:00'];
    }

    public function acquireRepair(int $fixtureId, string $caller): bool
    {
        return true;
    }

    public function recordRepair(int $fixtureId, string $outcome, bool $terminal = false): void {}
}
