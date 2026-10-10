<?php

use App\Jobs\FetchLiveFixtures;
use App\Models\Fixture;
use App\Services\ApiFootball\ApiFootballGateway;
use App\Services\ApiFootballService;
use App\Services\StaleFixtureJournal;
use App\Services\StaleFixturePolicy;
use App\Services\StaleFixtureReconciler;
use Carbon\Carbon;
use Illuminate\Broadcasting\PendingBroadcast;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\StaleFixtureTestDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (getenv('STALE_TEST_ALLOW_FIXTURES') !== '1' || getenv('APP_ENV') !== 'testing') {
    exit(2);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
StaleFixtureTestDatabase::configureMariaDb();
Carbon::setTestNow('2026-10-10 10:00:00 UTC');
Http::preventStrayRequests();
$path = $argv[1] ?? '';
if (! str_starts_with($path, sys_get_temp_dir().'/stale-concurrency-')) {
    exit(2);
}
$input = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$connectionId = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($path.'.ready', (string) $connectionId);
DB::statement('SET SESSION innodb_lock_wait_timeout = '.(int) ($input['lock_timeout'] ?? 3));
$attempts = 0;
$calls = 0;
$effects = 0;
$pause = function (string $marker): void {
    file_put_contents($marker, 'ready');
    $deadline = microtime(true) + 15;
    while (! file_exists($marker.'.go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('test_barrier_timeout');
        }
        usleep(10000);
    }
};
DB::connection()->beforeExecuting(function ($query) use (&$attempts, $input, $path, $pause): void {
    if (str_contains($query, '`fixtures`') && str_contains(strtolower($query), 'for update')) {
        $attempts++;
        if ($input['deadlock'] ?? false) {
            $pause($path.'.attempt'.$attempts);
        }
    }
});
DB::listen(function ($query) use ($input, $path, $pause): void {
    if (str_starts_with(strtolower($query->sql), 'update `fixture_scores`') && isset($input['crash'])) {
        if ($input['crash'] === 'before_commit') {
            $pause($path.'.crashready');
        } else {
            DB::afterCommit(fn () => $pause($path.'.crashready'));
        }
    }
});
try {
    if ($input['mode'] === 'live') {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch');
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('event')->andReturnUsing(function ($event) use ($dispatcher, &$effects) {
            $effects++;

            return new PendingBroadcast($dispatcher, $event);
        });
        $app->instance(Factory::class, $factory);
        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldReceive('getLiveFixtures')->once()->andReturnUsing(function () use ($input, &$calls) {
            $calls++;

            return [$input['row']];
        });
        (new FetchLiveFixtures)->handle($api);
        $outcome = Fixture::findOrFail($input['identity']['id'])->status_short;
    } elseif ($input['mode'] === 'run') {
        $gateway = Mockery::mock(ApiFootballGateway::class);
        $gateway->shouldReceive('get')->once()->andReturnUsing(function ($endpoint, $query, $class, $caller, $claim) use ($input, &$calls) {
            if (! $claim()) {
                throw new RuntimeException('claim_failed');
            }
            $calls++;

            return [$input['row']];
        });
        $journal = new StaleFixtureJournal($path.'.audit', true);
        $cursor = array_search($input['identity']['id'], array_column((new StaleFixturePolicy)->cohort(), 'id'));
        $journal->append(['type' => 'inspect', 'cursor' => $cursor]);
        $outcome = (new StaleFixtureReconciler(new StaleFixturePolicy, $gateway))->run($journal);
    } else {
        $gateway = Mockery::mock(ApiFootballGateway::class);
        $gateway->shouldNotReceive('get');
        $journal = new StaleFixtureJournal($path.'.audit', true);
        $outcome = (new StaleFixtureReconciler(new StaleFixturePolicy, $gateway))->apply($input['identity'], $input['snapshot'], $input['facts'], hrtime(true) / 1e9 - ($input['age'] ?? 0), $journal);
    }
    Http::assertNothingSent();
    echo json_encode(['outcome' => $outcome, 'attempts' => $attempts, 'provider_fake_calls' => $calls, 'effects' => $effects], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    echo json_encode(['exception' => get_class($e), 'driver_code' => $e instanceof QueryException ? ($e->errorInfo[1] ?? null) : null, 'attempts' => $attempts, 'provider_fake_calls' => $calls, 'effects' => $effects])."\n";
    exit(1);
}
