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
try {
    if ($input['mode'] === 'live') {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch');
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('event')->andReturnUsing(fn ($event) => new PendingBroadcast($dispatcher, $event));
        $app->instance(Factory::class, $factory);
        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldReceive('getLiveFixtures')->once()->andReturn([$input['row']]);
        (new FetchLiveFixtures)->handle($api);
        $outcome = Fixture::findOrFail($input['identity']['id'])->status_short;
    } else {
        $gateway = Mockery::mock(ApiFootballGateway::class);
        $gateway->shouldNotReceive('get');
        $journal = new StaleFixtureJournal($path.'.audit', true);
        $outcome = (new StaleFixtureReconciler(new StaleFixturePolicy, $gateway))->apply($input['identity'], $input['snapshot'], $input['facts'], hrtime(true) / 1e9, $journal);
    }
    Http::assertNothingSent();
    echo json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e)."\n");
    exit(1);
}
