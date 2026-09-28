<?php

declare(strict_types=1);

use App\Contracts\ApiFootballQuotaStore;
use App\Services\ApiFootball\ApiFootballGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const PHASE1_LIBRARY_ONLY = true;
const PHASE1_INTEGRATION_MODE = true;
$fault = getenv('PHASE1_TEST_FAULT');
if (is_string($fault) && $fault !== '') {
    define('PHASE1_INTEGRATION_FAULT', $fault);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__, 2).'/tools/calendar-phase1/phase1_harness.php';

final class Phase1FakeQuota implements ApiFootballQuotaStore
{
    public int $attempts = 0;

    public function reserve(string $endpointClass, string $caller): array
    {
        $this->attempts++;

        return ['allowed' => true, 'global' => $this->attempts, 'class' => $this->attempts, 'state' => 'normal'];
    }

    public function record(string $endpointClass, string $caller, int|string $status, string $outcome): void {}

    public function openCircuit(int $until, string $reason): void {}

    public function status(): array
    {
        return ['day' => gmdate('Y-m-d'), 'global' => $this->attempts, 'observed_physical' => $this->attempts,
            'bootstrap' => 0, 'threshold_state' => 'normal', 'classes' => [], 'callers' => [], 'outcomes' => [],
            'circuit_until' => 0, 'circuit_reason' => null, 'reset_at' => gmdate(DATE_ATOM, time() + 86400)];
    }

    public function acquireRepair(int $fixtureId, string $caller): bool
    {
        return false;
    }

    public function releaseRepair(int $fixtureId): void {}

    public function recordRepair(int $fixtureId, string $outcome, bool $terminal = false): void {}

    public function repairScanState(string $scan): ?array
    {
        return null;
    }

    public function compareAndSetRepairScanState(string $scan, ?array $expected, array $next): bool
    {
        return false;
    }
}

function integration_exit(array $result, int $status = 0): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $frame = 'PHASE1_RESULT:'.base64_encode(canonical_json($result))."\n";
    if (fwrite(STDOUT, $frame) !== strlen($frame)) {
        exit(2);
    }
    fflush(STDOUT);
    exit($status);
}

function integration_write(string $path, mixed $value): string
{
    $bytes = is_string($value) ? $value : canonical_json($value)."\n";
    $handle = fopen($path, 'x+b');
    if ($handle === false || ! chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes)
        || ! fflush($handle) || ! fsync($handle)) {
        throw new RuntimeException('integration_write_failed');
    }
    fclose($handle);
    $directory = fopen(dirname($path), 'r');
    if ($directory === false || ! fsync($directory)) {
        throw new RuntimeException('integration_directory_fsync_failed');
    }
    fclose($directory);

    return hash('sha256', $bytes);
}

function integration_identity(string $path): array
{
    $stat = lstat($path);
    if ($stat === false) {
        throw new RuntimeException('integration_identity_failed');
    }

    return ['sha256' => hash_file('sha256', $path), 'uid' => $stat['uid'], 'gid' => $stat['gid'],
        'mode' => $stat['mode'] & 07777, 'ino' => $stat['ino'], 'size' => $stat['size']];
}

function integration_ancestry(array $paths): array
{
    $directories = [];
    foreach ($paths as $path) {
        $directory = is_dir($path) ? $path : dirname($path);
        while ($directory !== '/') {
            $directories[$directory] = true;
            $directory = dirname($directory);
        }
    }
    $paths = array_keys($directories);
    sort($paths, SORT_STRING);

    return array_map(static function (string $path): array {
        $stat = lstat($path);

        return ['path' => $path, 'uid' => $stat['uid'], 'gid' => $stat['gid'],
            'mode' => $stat['mode'] & 07777, 'ino' => $stat['ino']];
    }, $paths);
}

function integration_payload(string $date, int $fixtureId): array
{
    return [[
        'fixture' => ['id' => $fixtureId, 'timestamp' => strtotime($date.' 12:00:00 UTC'),
            'status' => ['short' => 'NS', 'long' => 'Not Started', 'elapsed' => null],
            'venue' => ['name' => 'Harness'], 'referee' => null],
        'league' => ['id' => 39, 'season' => (int) gmdate('Y'), 'round' => 'Harness'],
        'teams' => [
            'home' => ['id' => $fixtureId + 100, 'name' => 'Home '.$fixtureId, 'logo' => null],
            'away' => ['id' => $fixtureId + 200, 'name' => 'Away '.$fixtureId, 'logo' => null],
        ],
        'goals' => ['home' => null, 'away' => null],
        'score' => [
            'halftime' => ['home' => null, 'away' => null], 'fulltime' => ['home' => null, 'away' => null],
            'extratime' => ['home' => null, 'away' => null], 'penalty' => ['home' => null, 'away' => null],
        ],
    ]];
}

if (posix_geteuid() !== 0) {
    throw new RuntimeException('integration_worker_requires_root');
}
$scenario = $argv[1] ?? 'normal';
$appRoot = realpath(dirname(__DIR__, 2));
if ($appRoot === false) {
    throw new RuntimeException('integration_app_root_missing');
}
$integrationRoot = getenv('PHASE1_INTEGRATION_ROOT') ?: '/run/calendar-phase1-harness';
if (! is_dir($integrationRoot) || is_link($integrationRoot)) {
    throw new RuntimeException('integration_root_missing');
}
$base = $integrationRoot.'/calendar-phase1-'.getmypid().'-'.bin2hex(random_bytes(4));
foreach ([$base, $base.'/control', $base.'/evidence', $base.'/locks'] as $directory) {
    if (! mkdir($directory, 0700) || ! chmod($directory, 0700)) {
        throw new RuntimeException('integration_directory_create_failed');
    }
}
$harness = $appRoot.'/tools/calendar-phase1/phase1_harness.php';
$importer = $appRoot.'/app/Services/FixtureCalendarImporter.php';
$importerPackage = $base.'/control/importer-package.bin';
$harnessPackage = $base.'/control/harness-package.bin';
$authenticationKey = $base.'/control/authentication.key';
integration_write($importerPackage, (string) file_get_contents($importer));
integration_write($harnessPackage, (string) file_get_contents($harness));
integration_write($authenticationKey, random_bytes(32));
$authenticationKeyBytes = read_regular_file($authenticationKey, 'integration_authentication_key', 32)['bytes'];
$health = $base.'/control/health.json';
$healthHash = integration_write($health, [['host' => 'rezultati.test', 'url' => 'http://127.0.0.1/']]);
$proof = $base.'/control/writer-proof.json';
$now = time();
$proofValue = ['schema' => 1, 'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $now),
    'scheduler_paused' => true, 'workers_paused' => true,
    'writer_samples' => [
        ['captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 2), 'processes' => []],
        ['captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $now), 'processes' => []],
    ],
    'cron_preimage_sha256' => str_repeat('1', 64), 'cron_paused_sha256' => str_repeat('2', 64)];
$proofHash = integration_write($proof, $proofValue);
$receipt = $base.'/control/deployment-receipt.json';
$receiptValue = ['schema' => PHASE1_SECURITY_SCHEMA, 'activation_id' => bin2hex(random_bytes(16)),
    'importer_package_sha256' => hash_file('sha256', $importerPackage),
    'preimage' => ['sha256' => '34856210860cfc33a5cea93d7b3aede59aa494b2df8f414edb49e52d533438a2',
        'uid' => 10004, 'gid' => 1003, 'mode' => 0644, 'size' => 13839],
    'postimage' => integration_identity($importer), 'applied_at_utc' => gmdate('Y-m-d\TH:i:s\Z')];
$receiptValue = authenticate_record($receiptValue, $authenticationKeyBytes);
$receiptHash = integration_write($receipt, $receiptValue);
$anchor = $base.'/control/trust-anchor.json';
$anchorValue = ['schema' => PHASE1_SECURITY_SCHEMA, 'activation_id' => $receiptValue['activation_id'],
    'app_root' => $appRoot,
    'ancestry' => integration_ancestry([$appRoot, $importer, $harness, $importerPackage, $harnessPackage,
        $authenticationKey, $receipt, $anchor, $base.'/locks']),
    'importer' => integration_identity($importer), 'harness' => integration_identity($harness),
    'importer_package' => integration_identity($importerPackage), 'harness_package' => integration_identity($harnessPackage),
    'authentication_key' => integration_identity($authenticationKey),
    'deployment_receipt_sha256' => $receiptHash,
    'locks' => ['phase' => $base.'/locks/phase.lock',
        'maintenance' => $base.'/locks/maintenance.lock',
        'calendar_cache_name' => 'api-football:calendar-sync:'.hash('sha256', strtolower('Laravel'."\0".'testing'))]];
$anchorValue = authenticate_record($anchorValue, $authenticationKeyBytes);
$anchorHash = integration_write($anchor, $anchorValue);
$options = ['mode' => 'activate', 'app-root' => $appRoot, 'evidence-dir' => $base.'/evidence',
    'writer-proof' => $proof, 'writer-proof-sha256' => $proofHash,
    'trust-anchor' => $anchor, 'trust-anchor-sha256' => $anchorHash,
    'deployment-receipt' => $receipt, 'importer-package' => $importerPackage, 'harness-package' => $harnessPackage,
    'authentication-key' => $authenticationKey,
    'health-urls' => $health, 'health-urls-sha256' => $healthHash];
$seeded = $base.'/control/seeded';
$quota = new Phase1FakeQuota;
$GLOBALS['phase1_integration_bootstrap'] = static function ($app) use ($scenario, $seeded, $quota): void {
    config()->set('app.name', 'Laravel');
    config()->set('app.env', 'testing');
    config()->set('api_football.calendar.enabled', false);
    config()->set('api_football.calendar.lock_store', 'array');
    config()->set('api_football.enabled_sports', ['football']);
    config()->set('api_football.max_attempts', $scenario === 'provider-cap' ? 2 : 1);
    config()->set('api_football.base_url', 'https://provider.invalid');
    config()->set('services.api_football.key', 'integration-only');
    $app->instance(ApiFootballQuotaStore::class, $quota);
    $app->forgetInstance(ApiFootballGateway::class);
    if ($scenario === 'reconnect-drift') {
        DB::disconnect();
        $reconnectedTimezone = (string) (DB::selectOne('SELECT @@session.time_zone AS tz')->tz ?? '');
        if ($reconnectedTimezone !== '+00:00') {
            throw new RuntimeException('reconnect_session_utc_missing');
        }
    }
    if (! is_file($seeded)) {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['fixture_scores', 'fixtures', 'teams', 'leagues'] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::table('leagues')->insert(['api_league_id' => 39, 'name' => 'Harness League', 'country' => 'Test',
            'current_season' => (int) gmdate('Y'), 'created_at' => now(), 'updated_at' => now()]);
        integration_write($seeded, 'seeded');
    }
    $responses = 0;
    Http::preventStrayRequests();
    Http::fake(static function (Request $request) use ($scenario, &$responses) {
        if (str_contains($request->url(), 'provider.invalid')) {
            $providerTimezone = (string) (DB::selectOne('SELECT @@session.time_zone AS tz')->tz ?? '');
            if ($providerTimezone !== '+00:00') {
                throw new RuntimeException('provider_ran_without_exact_session_utc');
            }
            $responses++;
            if ($scenario === 'provider-cap' && $responses % 2 === 1) {
                return Http::response([], 500);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $date = (string) ($query['date'] ?? gmdate('Y-m-d'));
            $id = $date === gmdate('Y-m-d') ? 910001 : 910002;

            return Http::response(['errors' => [], 'response' => integration_payload($date, $id)], 200);
        }

        return Http::response('ok', 200);
    });
};

if ($scenario === 'anchor-tamper') {
    file_put_contents($anchor, "{}\n");
} elseif ($scenario === 'authentication-key-mode') {
    chmod($authenticationKey, 0644);
} elseif ($scenario === 'package-identity') {
    file_put_contents($harnessPackage, "tampered\n");
} elseif ($scenario === 'unknown-option') {
    $options['lock-file'] = $base.'/locks/arbitrary.lock';
} elseif ($scenario === 'lock-symlink') {
    $lockTarget = $base.'/control/lock-target';
    integration_write($lockTarget, 'lock');
    symlink($lockTarget, $anchorValue['locks']['phase']);
} elseif ($scenario === 'evidence-not-fresh') {
    integration_write($base.'/evidence/preexisting', 'occupied');
} elseif ($scenario === 'symlink-swap') {
    rename($proof, $proof.'.real');
    symlink($proof.'.real', $proof);
}
if (in_array($scenario, ['initial-system', 'already-plus00', 'failed-set', 'recovery-session'], true)) {
    $GLOBALS['phase1_integration_before_utc_set'] = static function ($connection) use ($scenario): void {
        $GLOBALS['phase1_utc_set_calls'] = ($GLOBALS['phase1_utc_set_calls'] ?? 0) + 1;
        $pdo = $connection->getPdo();
        if ($scenario === 'failed-set') {
            throw new RuntimeException('forced_session_timezone_set_failure');
        }
        if ($scenario === 'recovery-session' || ($GLOBALS['phase1_utc_set_calls'] === 1 && $scenario === 'initial-system')) {
            $pdo->exec("SET SESSION time_zone = 'SYSTEM'");
        } elseif ($GLOBALS['phase1_utc_set_calls'] === 1 && $scenario === 'already-plus00') {
            $pdo->exec("SET SESSION time_zone = '+00:00'");
        }
        if ($GLOBALS['phase1_utc_set_calls'] === 1) {
            $GLOBALS['phase1_initial_session_timezone'] = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
        }
    };
}

$contention = null;
if ($scenario === 'lock-contention') {
    $contention = acquire_flock($anchorValue['locks']['phase'], 'test_contention');
}
$activationError = null;
$killed = is_string($fault) && str_starts_with($fault, 'kill:');
if ($killed) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('integration_fork_failed');
    }
    if ($pid === 0) {
        run_activation($options);
        exit(0);
    }
    pcntl_waitpid($pid, $status);
    if (! pcntl_wifsignaled($status)) {
        throw new RuntimeException('fault_did_not_interrupt_process');
    }
    $activationError = 'process_interrupted';
} else {
    try {
        run_activation($options);
    } catch (Throwable $failure) {
        $activationError = $failure instanceof Phase1Abort ? $failure->getMessage() : get_class($failure);
    }
}
if (is_resource($contention)) {
    flock($contention, LOCK_UN);
    fclose($contention);
}
if ($scenario === 'failed-set') {
    if ($activationError !== 'database_session_utc_establishment_failed' || $quota->attempts !== 0) {
        throw new RuntimeException('failed_set_did_not_block_before_provider');
    }
    integration_exit(['scenario' => $scenario, 'passed' => true, 'attempts' => $quota->attempts]);
}
if (in_array($scenario, ['symlink-swap', 'lock-contention', 'provider-cap', 'anchor-tamper',
    'authentication-key-mode', 'package-identity', 'unknown-option', 'lock-symlink', 'evidence-not-fresh'], true)) {
    $expected = [
        'symlink-swap' => 'writer_proof', 'lock-contention' => 'phase_lock',
        'provider-cap' => 'insufficient_budget_for_t2', 'anchor-tamper' => 'trust_anchor_identity_failed',
        'authentication-key-mode' => 'authentication_key_identity_failed',
        'package-identity' => 'harness_package_identity_failed', 'unknown-option' => 'unknown_option',
        'lock-symlink' => 'phase_lock_file_invalid', 'evidence-not-fresh' => 'evidence_directory_not_fresh',
    ];
    if ($activationError === null || ! str_contains($activationError, $expected[$scenario])) {
        throw new RuntimeException('expected_activation_block_missing:'.(string) $activationError);
    }
    if ($scenario !== 'provider-cap' && $quota->attempts !== 0) {
        throw new RuntimeException('identity_or_lock_gate_ran_provider');
    }
    integration_exit(['scenario' => $scenario, 'passed' => true, 'attempts' => $quota->attempts]);
}
if ($scenario === 'initial-system' && ($GLOBALS['phase1_initial_session_timezone'] ?? null) !== 'SYSTEM') {
    throw new RuntimeException('initial_system_timezone_not_observed');
}
if ($scenario === 'already-plus00' && ($GLOBALS['phase1_initial_session_timezone'] ?? null) !== '+00:00') {
    throw new RuntimeException('initial_plus00_timezone_not_observed');
}
if ($activationError !== null && ! $killed) {
    $isFault = $fault !== false && $fault !== '';
    integration_exit(
        ['scenario' => $scenario, 'fault' => $fault, 'passed' => $isFault, 'activation' => $activationError],
        $isFault ? 0 : 1,
    );
}
if ($scenario === 'tampered-intent') {
    file_put_contents($base.'/evidence/commit-intent.json', "{}\n");
} elseif ($scenario === 'tampered-seal') {
    chmod($base.'/evidence/precommit.sha256.json', 0600);
    file_put_contents($base.'/evidence/precommit.sha256.json', "{}\n");
} elseif ($scenario === 'tampered-marker') {
    file_put_contents($base.'/evidence/COMMITTED.json', "{}\n");
}
$concurrentPid = null;
if ($scenario === 'concurrent-advancement') {
    $concurrentPid = pcntl_fork();
    if ($concurrentPid === -1) {
        throw new RuntimeException('concurrent_writer_fork_failed');
    }
    if ($concurrentPid === 0) {
        DB::disconnect();
        $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST'), getenv('DB_PORT') ?: '3306', getenv('DB_DATABASE')),
            (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->beginTransaction();
        $statement = $pdo->prepare('UPDATE fixtures SET status_short = ?, round = ? WHERE api_fixture_id = ?');
        $statement->execute(['LIVE', 'concurrently advanced', 910001]);
        integration_write($base.'/control/concurrent-ready', 'ready');
        usleep(750000);
        $pdo->commit();
        exit(0);
    }
    $ready = $base.'/control/concurrent-ready';
    $deadline = microtime(true) + 5;
    while (! is_file($ready) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($ready)) {
        throw new RuntimeException('concurrent_writer_not_ready');
    }
}
$recover = $options;
$recover['mode'] = 'recover';
unset($recover['health-urls'], $recover['health-urls-sha256']);
$recover['decision'] = 'rollback';
$recoveryError = null;
try {
    run_recovery($recover);
    if ($scenario === 'idempotency') {
        run_recovery($recover);
    }
} catch (Throwable $failure) {
    $recoveryError = $failure instanceof Phase1Abort ? $failure->getMessage() : get_class($failure);
}
if ($concurrentPid !== null) {
    pcntl_waitpid($concurrentPid, $concurrentStatus);
    if (! pcntl_wifexited($concurrentStatus) || pcntl_wexitstatus($concurrentStatus) !== 0) {
        throw new RuntimeException('concurrent_writer_failed');
    }
}
$blockedScenario = in_array($scenario, ['tampered-intent', 'tampered-seal', 'tampered-marker', 'concurrent-advancement'], true)
    || ($killed && ! (str_contains((string) $fault, 'after:file-publish:COMMITTED.json')
        || str_contains((string) $fault, 'after:directory-fsync:COMMITTED.json')));
if ($blockedScenario !== ($recoveryError !== null)) {
    throw new RuntimeException('unexpected_recovery_result:'.(string) $recoveryError);
}
if (! $blockedScenario && DB::table('fixtures')->count() !== 0) {
    throw new RuntimeException('recovery_did_not_restore_baseline');
}
integration_exit(['scenario' => $scenario, 'passed' => true, 'attempts' => $quota->attempts,
    'recovery' => $recoveryError ?? 'restored']);
