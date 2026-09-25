<?php

use App\Services\FixtureCalendarImporter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$encodedPayload = $argv[1] ?? '';
$startAt = (float) ($argv[2] ?? 0);
$lockWaitSeconds = (int) ($argv[3] ?? 5);
$decoded = base64_decode($encodedPayload, true);
$payload = is_string($decoded) ? json_decode($decoded, true) : null;

if (! is_array($payload) || $startAt <= 0 || $lockWaitSeconds < 1) {
    fwrite(STDERR, "Invalid worker input.\n");
    exit(2);
}

DB::statement('SET SESSION innodb_lock_wait_timeout = '.min($lockWaitSeconds, 10));

while (($remaining = $startAt - microtime(true)) > 0) {
    usleep((int) min(50000, max(1000, $remaining * 1000000)));
}

try {
    fwrite(STDOUT, json_encode((new FixtureCalendarImporter)->import($payload), JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e)."\n");
    exit(1);
}
