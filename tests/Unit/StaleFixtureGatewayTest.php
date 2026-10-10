<?php

namespace Tests\Unit;

use App\Contracts\ApiFootballQuotaStore;
use App\Exceptions\ApiFootballBlocked;
use App\Exceptions\ApiFootballLocalAttemptLimitReached;
use App\Exceptions\ApiFootballProviderResponseException;
use App\Services\ApiFootball\ApiFootballGateway;
use App\Services\ApiFootball\RedisApiFootballQuotaStore;
use App\Services\StaleFixturePolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class StaleFixtureGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('api_football.enabled_sports', ['football']);
        config()->set('api_football.retry_base_ms', 0);
        config()->set('api_football.base_url', 'https://provider.invalid');
        Http::preventStrayRequests();
    }

    public function test_command_is_default_off_without_audit_provider_or_database_access(): void
    {
        $quota = Mockery::mock(ApiFootballQuotaStore::class);
        $quota->shouldNotReceive('reserve');
        $this->app->instance(ApiFootballQuotaStore::class, $quota);
        $this->assertSame(1, Artisan::call('fixtures:reconcile-stale'));
        $this->assertStringContainsString('Disabled by default', Artisan::output());
        Http::assertNothingSent();
    }

    private function quota(int $attempts): ApiFootballQuotaStore
    {
        $quota = Mockery::mock(ApiFootballQuotaStore::class);
        $quota->shouldReceive('reserve')->times($attempts)->with('fixture_repair', StaleFixturePolicy::CALLER)->andReturn(['allowed' => true]);
        $quota->shouldReceive('record')->times($attempts);

        return $quota;
    }

    public function test_retries_share_exactly_five_tokens_for_entire_run(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 500)->push(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []])->push([], 500)->push(['errors' => [], 'results' => 0, 'paging' => ['current' => 1, 'total' => 1], 'response' => []])->push([], 500)]);
        $gateway = new ApiFootballGateway($this->quota(5));
        $tokens = 5;
        $claim = function () use (&$tokens): bool {
            if ($tokens === 0) {
                return false;
            }
            $tokens--;

            return true;
        };
        $gateway->get('/fixtures', ['id' => 1], 'fixture_repair', StaleFixturePolicy::CALLER, $claim);
        $gateway->get('/fixtures', ['id' => 2], 'fixture_repair', StaleFixturePolicy::CALLER, $claim);
        try {
            $gateway->get('/fixtures', ['id' => 3], 'fixture_repair', StaleFixturePolicy::CALLER, $claim);
            $this->fail('Sixth attempt admitted');
        } catch (ApiFootballLocalAttemptLimitReached) {
            $this->assertSame(0, $tokens);
        }
        Http::assertSentCount(5);
    }

    public function test_429_is_single_attempt_and_opens_existing_circuit(): void
    {
        Http::fake(['*' => Http::response([], 429)]);
        $quota = $this->quota(1);
        $quota->shouldReceive('openCircuit')->once();
        try {
            (new ApiFootballGateway($quota))->get('/fixtures', ['id' => 1], 'fixture_repair', StaleFixturePolicy::CALLER, fn () => true);
            $this->fail('429 did not stop');
        } catch (ApiFootballBlocked) {
            Http::assertSentCount(1);
        }
    }

    public function test_malformed_result_count_and_redirects_are_not_empty_verdicts(): void
    {
        foreach ([Http::response(['errors' => [], 'results' => 2, 'paging' => ['current' => 1, 'total' => 1], 'response' => []]), Http::response([], 302, ['Location' => 'https://must-not-be-called.invalid'])] as $response) {
            Http::fake(['*' => $response]);
            try {
                (new ApiFootballGateway($this->quota(1)))->get('/fixtures', ['id' => 1], 'fixture_repair', StaleFixturePolicy::CALLER, fn () => true);
                $this->fail('Bad envelope admitted');
            } catch (ApiFootballProviderResponseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_extra_headroom_is_inside_existing_atomic_quota_reservation_not_new_class(): void
    {
        $redis = Mockery::mock();
        Redis::shouldReceive('connection')->once()->with('cache')->andReturn($redis);
        $redis->shouldReceive('eval')->once()->withArgs(function ($script, $keys, ...$args): bool {
            $this->assertSame(4, $keys);
            $this->assertStringContainsString("ARGV[5] == 'fixture_repair|StaleFixtureReconciliation'", $script);
            $this->assertStringContainsString('if stale >= 20', $script);
            $this->assertStringContainsString('if class >= 400', $script);
            $this->assertLessThan(strpos($script, "global = redis.call('INCR'"), strpos($script, 'if stale >= 20'));
            $this->assertContains('fixture_repair', $args);

            return true;
        })->andReturn([0, 'stale_daily_limit', 20]);
        $this->assertSame(['allowed' => false, 'reason' => 'stale_daily_limit', 'value' => 20], (new RedisApiFootballQuotaStore)->reserve('fixture_repair', StaleFixturePolicy::CALLER));
    }
}
