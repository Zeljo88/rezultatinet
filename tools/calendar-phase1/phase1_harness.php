#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Contracts\ApiFootballQuotaStore;
use App\Services\ApiFootball\ApiFootballGateway;
use App\Services\FixtureCalendarImporter;
use App\Support\FixtureCalendarWindow;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Review-pinned Phase 1 calendar activation harness.
 *
 * Production mode deliberately requires a separately captured writer-exclusion
 * proof. Rehearsal mode is self-contained and uses only embedded, sanitized
 * captured HTTP fakes. The persistent calendar gate is never changed.
 */
const PHASE1_SECURITY_SCHEMA = 2;
const PHASE1_DEPLOYED_PREIMAGE_SHA256 = '34856210860cfc33a5cea93d7b3aede59aa494b2df8f414edb49e52d533438a2';
const PHASE1_IMPORTER_SHA256 = '9f53bfffaf6472f0b9c3c7210d2b6c5820161def203b464fff587e0a431657ea';
const PHASE1_MAX_ATTEMPTS = 4;
const PHASE1_MAX_ROWS_PER_DATE = 2000;
const PHASE1_IMPORTABLE = ['NS', 'TBD', 'PST', 'CANC'];
const PHASE1_PROTECTED = [
    '1H', '2H', 'HT', 'ET', 'BT', 'P', 'SUSP', 'INT', 'LIVE',
    'FT', 'AET', 'PEN', 'AWD', 'WO', 'CANC', 'ABD',
];

final class Phase1Abort extends RuntimeException {}

function establish_database_session_utc(Connection $connection): void
{
    try {
        if (defined('PHASE1_INTEGRATION_MODE') && PHASE1_INTEGRATION_MODE === true
            && isset($GLOBALS['phase1_integration_before_utc_set'])
            && is_callable($GLOBALS['phase1_integration_before_utc_set'])) {
            ($GLOBALS['phase1_integration_before_utc_set'])($connection);
        }
        $connection->statement("SET SESSION time_zone = '+00:00'");
        $timezone = (string) ($connection->selectOne('SELECT @@session.time_zone AS tz')->tz ?? '');
    } catch (Throwable $failure) {
        throw new Phase1Abort('database_session_utc_establishment_failed', 0, $failure);
    }
    if ($timezone !== '+00:00') {
        throw new Phase1Abort('database_session_utc_establishment_failed');
    }
}

function initialize_database_session_utc(object $app): Connection
{
    $connection = DB::connection();
    establish_database_session_utc($connection);
    $app['events']->listen(ConnectionEstablished::class, static function (ConnectionEstablished $event): void {
        establish_database_session_utc($event->connection);
    });

    return $connection;
}

function phase1_utc_date_scope(?DateTimeImmutable $instant = null): array
{
    $utc = ($instant ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('UTC'));

    return [$utc->format('Y-m-d'), $utc->modify('+1 day')->format('Y-m-d')];
}

function fault_boundary(string $name): void
{
    if (! defined('PHASE1_INTEGRATION_FAULT')) {
        return;
    }
    $selected = (string) PHASE1_INTEGRATION_FAULT;
    $kill = str_starts_with($selected, 'kill:');
    $target = $kill ? substr($selected, 5) : $selected;
    if ($target !== $name) {
        return;
    }
    if (! defined('PHASE1_INTEGRATION_MODE') || PHASE1_INTEGRATION_MODE !== true) {
        throw new Phase1Abort('fault_injection_outside_integration_mode');
    }
    if ($kill) {
        posix_kill(getmypid(), SIGKILL);
        usleep(100000);
        throw new Phase1Abort('process_interrupt_failed');
    }
    throw new Phase1Abort('injected_fault_'.$name);
}

function exact_keys(array $value, array $keys, string $classification): void
{
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($keys, SORT_STRING);
    if ($actual !== $keys) {
        throw new Phase1Abort($classification);
    }
}

function sha256_value(mixed $value): string
{
    return hash('sha256', canonical_json($value)."\n");
}

function canonical_identity_matches(mixed $actual, mixed $expected): bool
{
    return hash_equals(sha256_value($expected), sha256_value($actual));
}

function assert_canonical_identity(mixed $actual, mixed $expected, string $classification): void
{
    if (! canonical_identity_matches($actual, $expected)) {
        throw new Phase1Abort($classification);
    }
}

function assert_hex_hash(mixed $value, string $classification): string
{
    if (! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
        throw new Phase1Abort($classification);
    }

    return $value;
}

/** Resolve every existing path component without accepting links or traversal. */
function confined_path(string $path, string $classification, bool $directory = false): string
{
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || preg_match('~(?:\A|/)\.\.?(/|\z)~', $path) === 1) {
        throw new Phase1Abort($classification);
    }
    $normalized = preg_replace('~/+~', '/', $path);
    if (! is_string($normalized) || $normalized !== $path) {
        throw new Phase1Abort($classification);
    }
    $cursor = '';
    foreach (explode('/', ltrim($path, '/')) as $component) {
        $cursor .= '/'.$component;
        $stat = @lstat($cursor);
        if ($stat === false || (($stat['mode'] & 0170000) === 0120000)) {
            throw new Phase1Abort($classification);
        }
    }
    $real = realpath($path);
    if ($real === false || $real !== $path || ($directory ? ! is_dir($real) : ! is_file($real))) {
        throw new Phase1Abort($classification);
    }

    return $real;
}

function assert_root_private(string $path, bool $directory, string $classification): array
{
    $path = confined_path($path, $classification, $directory);
    $stat = lstat($path);
    $expectedMode = $directory ? 0700 : 0600;
    if ($stat === false || $stat['uid'] !== 0 || ($stat['mode'] & 07777) !== $expectedMode
        || ($directory ? (($stat['mode'] & 0170000) !== 0040000) : (($stat['mode'] & 0170000) !== 0100000))) {
        throw new Phase1Abort($classification);
    }

    return $stat;
}

/** Read one already-existing regular file without following a path swap. */
function read_regular_file(string $path, string $classification, int $maximumBytes = 16777216): array
{
    confined_path($path, $classification);
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new Phase1Abort($classification.'_open_failed');
    }
    try {
        $descriptor = fstat($handle);
        $pathStat = lstat($path);
        if ($descriptor === false || $pathStat === false
            || ($descriptor['mode'] & 0170000) !== 0100000 || $descriptor['nlink'] !== 1
            || $descriptor['dev'] !== $pathStat['dev'] || $descriptor['ino'] !== $pathStat['ino']
            || $descriptor['size'] < 0 || $descriptor['size'] > $maximumBytes) {
            throw new Phase1Abort($classification.'_identity_failed');
        }
        $bytes = stream_get_contents($handle, $maximumBytes + 1);
        if (! is_string($bytes) || strlen($bytes) !== $descriptor['size']) {
            throw new Phase1Abort($classification.'_read_failed');
        }

        return ['bytes' => $bytes, 'stat' => $descriptor];
    } finally {
        fclose($handle);
    }
}

function assert_exact_file(string $path, array $expected, string $classification): array
{
    exact_keys($expected, ['sha256', 'uid', 'gid', 'mode', 'ino', 'size'], $classification.'_metadata_shape');
    foreach (['uid', 'gid', 'mode', 'ino', 'size'] as $field) {
        if (! is_int($expected[$field]) || $expected[$field] < 0) {
            throw new Phase1Abort($classification.'_metadata_shape');
        }
    }
    $file = read_regular_file($path, $classification);
    $stat = $file['stat'];
    if (hash('sha256', $file['bytes']) !== assert_hex_hash($expected['sha256'], $classification.'_hash_shape')
        || (int) $stat['uid'] !== $expected['uid'] || (int) $stat['gid'] !== $expected['gid']
        || (int) ($stat['mode'] & 07777) !== $expected['mode'] || (int) $stat['ino'] !== $expected['ino']
        || (int) $stat['size'] !== $expected['size']) {
        throw new Phase1Abort($classification);
    }

    return $stat;
}

function read_exact_json_file(string $path, string $expectedHash, bool $rootPrivate, string $classification): array
{
    if ($rootPrivate) {
        assert_root_private(dirname($path), true, $classification.'_directory_invalid');
        assert_root_private($path, false, $classification);
    }
    $file = read_regular_file($path, $classification);
    $bytes = $file['bytes'];
    if (! hash_equals(assert_hex_hash($expectedHash, $classification.'_hash_shape'), hash('sha256', $bytes))) {
        throw new Phase1Abort($classification.'_identity_failed');
    }
    $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded) || canonical_json($decoded)."\n" !== $bytes) {
        throw new Phase1Abort($classification.'_not_canonical');
    }

    return $decoded;
}

function authenticate_record(array $record, string $key): array
{
    if (isset($record['authentication']) || strlen($key) !== 32) {
        throw new Phase1Abort('record_authentication_input_invalid');
    }
    $record['authentication'] = [
        'algorithm' => 'HMAC-SHA-256',
        'tag' => hash_hmac('sha256', canonical_json($record)."\n", $key),
    ];

    return $record;
}

function verify_authenticated_record(array $record, string $key, string $classification): array
{
    $authentication = $record['authentication'] ?? null;
    if (! is_array($authentication)) {
        throw new Phase1Abort($classification.'_authentication_missing');
    }
    exact_keys($authentication, ['algorithm', 'tag'], $classification.'_authentication_shape');
    if ($authentication['algorithm'] !== 'HMAC-SHA-256') {
        throw new Phase1Abort($classification.'_authentication_algorithm');
    }
    $tag = assert_hex_hash($authentication['tag'] ?? null, $classification.'_authentication_tag');
    unset($record['authentication']);
    $expected = hash_hmac('sha256', canonical_json($record)."\n", $key);
    if (! hash_equals($expected, $tag)) {
        throw new Phase1Abort($classification.'_authentication_failed');
    }

    return $record;
}

final class Phase1Evidence
{
    public function __construct(private readonly string $directory, bool $fresh)
    {
        assert_root_private($directory, true, 'evidence_directory_invalid');
        $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        if ($fresh && $entries !== []) {
            throw new Phase1Abort('evidence_directory_not_fresh');
        }
    }

    public function write(string $name, mixed $value): string
    {
        if (! preg_match('/\A[A-Za-z0-9._-]+\z/', $name)) {
            throw new Phase1Abort('invalid_evidence_name');
        }
        $json = canonical_json($value)."\n";
        $temporary = $this->directory.'/.'.$name.'.'.bin2hex(random_bytes(8));
        $target = $this->directory.'/'.$name;
        if (file_exists($target) || is_link($target)) {
            throw new Phase1Abort('evidence_replay_or_replacement');
        }
        $handle = fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new Phase1Abort('evidence_open_failed');
        }
        try {
            $stat = fstat($handle);
            if ($stat === false || $stat['uid'] !== 0 || ! chmod($temporary, 0600)
                || fwrite($handle, $json) !== strlen($json) || ! fflush($handle) || ! fsync($handle)) {
                throw new Phase1Abort('evidence_fsync_failed');
            }
            fault_boundary('after:file-fsync:'.$name);
        } finally {
            fclose($handle);
        }
        if (! link($temporary, $target)) {
            throw new Phase1Abort('evidence_publish_failed');
        }
        unlink($temporary);
        fault_boundary('after:file-publish:'.$name);
        $directoryHandle = fopen($this->directory, 'r');
        if ($directoryHandle === false || ! fsync($directoryHandle)) {
            if (is_resource($directoryHandle)) {
                fclose($directoryHandle);
            }
            throw new Phase1Abort('evidence_directory_fsync_failed');
        }
        fclose($directoryHandle);
        fault_boundary('after:directory-fsync:'.$name);

        return hash('sha256', $json);
    }

    public function read(string $name): array
    {
        $path = $this->directory.'/'.$name;
        assert_root_private($path, false, 'evidence_file_invalid');
        $bytes = read_regular_file($path, 'evidence_file_invalid')['bytes'];
        $decoded = json_decode((string) $bytes, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || canonical_json($decoded)."\n" !== $bytes) {
            throw new Phase1Abort('evidence_json_noncanonical');
        }

        return $decoded;
    }

    public function hash(string $name): string
    {
        $path = $this->directory.'/'.$name;
        assert_root_private($path, false, 'evidence_file_invalid');

        return hash('sha256', read_regular_file($path, 'evidence_file_invalid')['bytes']);
    }

    public function writeAuthenticated(string $name, array $value, string $key): string
    {
        return $this->write($name, authenticate_record($value, $key));
    }

    public function readAuthenticated(string $name, string $key, string $classification): array
    {
        return verify_authenticated_record($this->read($name), $key, $classification);
    }

    public function seal(string $phase, array $files, array $chain, string $key): string
    {
        ksort($files, SORT_STRING);

        return $this->writeAuthenticated($phase.'.sha256.json', [
            'schema' => PHASE1_SECURITY_SCHEMA,
            'phase' => $phase,
            'chain' => $chain,
            'files' => $files,
        ], $key);
    }

    public function directory(): string
    {
        return $this->directory;
    }
}

final class Phase1AttemptBudget
{
    private int $attempts = 0;

    public function claim(): bool
    {
        if ($this->attempts >= PHASE1_MAX_ATTEMPTS) {
            return false;
        }
        $this->attempts++;

        return true;
    }

    public function used(): int
    {
        return $this->attempts;
    }

    public function remaining(): int
    {
        return PHASE1_MAX_ATTEMPTS - $this->attempts;
    }
}

function canonicalize(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $child) {
        $value[$key] = canonicalize($child);
    }

    return $value;
}

function canonical_json(mixed $value): string
{
    return json_encode(
        canonicalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
    );
}

function row_hashes(array $rows, string $primary = 'id'): array
{
    usort($rows, static fn (array $a, array $b): int => ($a[$primary] ?? 0) <=> ($b[$primary] ?? 0));
    $result = [];
    foreach ($rows as $row) {
        $key = (string) ($row[$primary] ?? throw new Phase1Abort('row_primary_key_missing'));
        $result[$key] = hash('sha256', canonical_json($row));
    }

    return $result;
}

function normalized_status(mixed $status): ?string
{
    if (! is_string($status)) {
        return null;
    }
    $status = strtoupper(trim($status));

    return preg_match('/\A[A-Z0-9_-]{1,16}\z/', $status) === 1 ? $status : null;
}

function positive_id(mixed $value): ?int
{
    return is_int($value) && $value > 0 && $value <= 4294967295 ? $value : null;
}

function sanitize_response_row(mixed $row, string $date): array
{
    $fixture = is_array($row) && is_array($row['fixture'] ?? null) ? $row['fixture'] : [];
    $league = is_array($row) && is_array($row['league'] ?? null) ? $row['league'] : [];
    $teams = is_array($row) && is_array($row['teams'] ?? null) ? $row['teams'] : [];
    $home = is_array($teams['home'] ?? null) ? $teams['home'] : [];
    $away = is_array($teams['away'] ?? null) ? $teams['away'] : [];
    $status = normalized_status(is_array($fixture['status'] ?? null) ? ($fixture['status']['short'] ?? null) : null);
    $timestamp = $fixture['timestamp'] ?? null;
    $bucket = is_int($timestamp) && $timestamp > 0 ? gmdate('Y-m-d', $timestamp) : null;
    $ids = [
        'fixture_id' => positive_id($fixture['id'] ?? null),
        'league_id' => positive_id($league['id'] ?? null),
        'home_team_id' => positive_id($home['id'] ?? null),
        'away_team_id' => positive_id($away['id'] ?? null),
    ];
    $classification = in_array(null, $ids, true) || $bucket === null
        ? 'malformed_row'
        : ($status === null ? 'unknown_status' : ($bucket === $date ? 'validated' : 'wrong_utc_date'));

    return [
        ...$ids,
        'status' => $status,
        'date_bucket' => $bucket,
        'classification' => $classification,
    ];
}

function sanitized_response(array $payload, string $date): array
{
    if (count($payload) > PHASE1_MAX_ROWS_PER_DATE) {
        throw new Phase1Abort('provider_row_limit_exceeded');
    }
    $rows = array_map(static fn (mixed $row): array => sanitize_response_row($row, $date), $payload);
    foreach ($rows as $row) {
        if ($row['classification'] !== 'validated') {
            throw new Phase1Abort('response_'.$row['classification']);
        }
    }

    return $rows;
}

function ids_from_manifests(array $manifests): array
{
    $fixtureIds = [];
    $teamIds = [];
    $leagueIds = [];
    foreach ($manifests as $rows) {
        foreach ($rows as $row) {
            $fixtureIds[$row['fixture_id']] = true;
            $teamIds[$row['home_team_id']] = true;
            $teamIds[$row['away_team_id']] = true;
            $leagueIds[$row['league_id']] = true;
        }
    }
    $sort = static function (array $set): array {
        $ids = array_map('intval', array_keys($set));
        sort($ids, SORT_NUMERIC);

        return $ids;
    };

    return [
        'fixture_ids' => $sort($fixtureIds),
        'team_ids' => $sort($teamIds),
        'league_ids' => $sort($leagueIds),
    ];
}

function parse_options(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (! str_starts_with($argument, '--') || ! str_contains($argument, '=')) {
            throw new Phase1Abort('arguments_must_use_--name=value');
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if (isset($options[$name]) || ! preg_match('/\A[a-z][a-z0-9-]*\z/', $name)) {
            throw new Phase1Abort('invalid_or_duplicate_option');
        }
        $options[$name] = $value;
    }

    return $options;
}

function assert_option_contract(array $options): string
{
    $mode = $options['mode'] ?? '';
    $common = ['mode', 'app-root', 'evidence-dir', 'writer-proof', 'writer-proof-sha256',
        'trust-anchor', 'trust-anchor-sha256', 'deployment-receipt', 'importer-package', 'harness-package',
        'authentication-key'];
    $allowed = match ($mode) {
        'activate' => [...$common, 'health-urls', 'health-urls-sha256'],
        'recover' => [...$common, 'decision'],
        default => throw new Phase1Abort('unknown_mode'),
    };
    foreach (array_keys($options) as $name) {
        if (! in_array($name, $allowed, true)) {
            throw new Phase1Abort('unknown_option');
        }
    }
    foreach ($common as $required) {
        if (! isset($options[$required]) || $options[$required] === '') {
            throw new Phase1Abort('missing_'.$required);
        }
    }
    if ($mode === 'activate') {
        foreach (['health-urls', 'health-urls-sha256'] as $required) {
            if (! isset($options[$required]) || $options[$required] === '') {
                throw new Phase1Abort('missing_'.$required);
            }
        }
    } elseif (! isset($options['decision']) || ! in_array($options['decision'], ['keep', 'rollback'], true)) {
        throw new Phase1Abort('invalid_recovery_decision');
    }

    return $mode;
}

function assert_safe_environment(): void
{
    if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0 || umask(0077) !== 0077) {
        throw new Phase1Abort('unsafe_execution_context');
    }
    foreach (['LD_PRELOAD', 'LD_LIBRARY_PATH', 'PHPRC', 'PHP_INI_SCAN_DIR', 'BASH_ENV', 'ENV', 'CDPATH'] as $name) {
        if (($value = getenv($name)) !== false && $value !== '') {
            throw new Phase1Abort('unsafe_command_environment');
        }
    }
}

function ancestry_paths(array $paths): array
{
    $directories = [];
    foreach ($paths as $path) {
        $directory = is_dir($path) ? $path : dirname($path);
        while ($directory !== '/') {
            $directories[$directory] = true;
            $directory = dirname($directory);
        }
    }
    $directories = array_keys($directories);
    sort($directories, SORT_STRING);

    return $directories;
}

function assert_ancestry(array $entries, array $requiredPaths): void
{
    if (! array_is_list($entries) || $entries === []) {
        throw new Phase1Abort('anchor_ancestry_invalid');
    }
    $actualPaths = [];
    foreach ($entries as $entry) {
        if (! is_array($entry)) {
            throw new Phase1Abort('anchor_ancestry_invalid');
        }
        exact_keys($entry, ['path', 'uid', 'gid', 'mode', 'ino'], 'anchor_ancestry_shape');
        $path = confined_path((string) $entry['path'], 'anchor_ancestry_path', true);
        $stat = lstat($path);
        if (! is_int($entry['uid']) || ! is_int($entry['gid']) || ! is_int($entry['mode']) || ! is_int($entry['ino'])
            || $stat === false || $stat['uid'] !== $entry['uid'] || $stat['gid'] !== $entry['gid']
            || ($stat['mode'] & 07777) !== $entry['mode'] || $stat['ino'] !== $entry['ino']
            || (($stat['mode'] & 0022) !== 0)) {
            throw new Phase1Abort('anchor_ancestry_drift');
        }
        $actualPaths[] = $path;
    }
    if ($actualPaths !== ancestry_paths($requiredPaths)) {
        throw new Phase1Abort('anchor_ancestry_incomplete');
    }
}

function load_trust_contract(array $options, bool $requireFreshReceipt): array
{
    $anchorHash = assert_hex_hash($options['trust-anchor-sha256'], 'trust_anchor_hash_invalid');
    $anchor = read_exact_json_file($options['trust-anchor'], $anchorHash, true, 'trust_anchor');
    exact_keys($anchor, ['schema', 'activation_id', 'app_root', 'ancestry', 'importer', 'harness',
        'importer_package', 'harness_package', 'authentication_key', 'deployment_receipt_sha256', 'locks',
        'authentication'], 'trust_anchor_shape');
    if (! is_array($anchor['authentication_key'])) {
        throw new Phase1Abort('authentication_key_identity_invalid');
    }
    $keyFile = assert_exact_file($options['authentication-key'], $anchor['authentication_key'], 'authentication_key_identity_failed');
    if ($keyFile['uid'] !== 0 || ($keyFile['mode'] & 07777) !== 0600) {
        throw new Phase1Abort('authentication_key_permissions_invalid');
    }
    assert_root_private(dirname($options['authentication-key']), true, 'authentication_key_directory_invalid');
    $key = read_regular_file($options['authentication-key'], 'authentication_key', 32)['bytes'];
    if (strlen($key) !== 32) {
        throw new Phase1Abort('authentication_key_length_invalid');
    }
    $anchor = verify_authenticated_record($anchor, $key, 'trust_anchor');
    exact_keys($anchor, ['schema', 'activation_id', 'app_root', 'ancestry', 'importer', 'harness',
        'importer_package', 'harness_package', 'authentication_key', 'deployment_receipt_sha256', 'locks'], 'trust_anchor_shape');
    if ($anchor['schema'] !== PHASE1_SECURITY_SCHEMA
        || ! is_string($anchor['activation_id']) || preg_match('/\A[0-9a-f]{32}\z/', $anchor['activation_id']) !== 1) {
        throw new Phase1Abort('trust_anchor_invalid');
    }
    foreach (['importer', 'harness', 'importer_package', 'harness_package'] as $identity) {
        if (! is_array($anchor[$identity])) {
            throw new Phase1Abort('trust_anchor_identity_shape_invalid');
        }
    }
    if (! is_array($anchor['locks']) || ! is_string($anchor['locks']['phase'] ?? null)
        || ! is_string($anchor['locks']['maintenance'] ?? null)) {
        throw new Phase1Abort('lock_anchor_shape');
    }
    $root = confined_path($options['app-root'], 'application_root_invalid', true);
    if ($root !== $anchor['app_root']) {
        throw new Phase1Abort('application_root_identity_failed');
    }
    assert_ancestry($anchor['ancestry'], [$root, $root.'/app/Services/FixtureCalendarImporter.php', __FILE__,
        $options['importer-package'], $options['harness-package'], $options['authentication-key'],
        $options['deployment-receipt'], $options['trust-anchor'], dirname($anchor['locks']['phase'] ?? '/')]);
    assert_exact_file($root.'/app/Services/FixtureCalendarImporter.php', $anchor['importer'], 'importer_identity_failed');
    assert_exact_file(__FILE__, $anchor['harness'], 'harness_identity_failed');
    assert_exact_file($options['importer-package'], $anchor['importer_package'], 'importer_package_identity_failed');
    assert_exact_file($options['harness-package'], $anchor['harness_package'], 'harness_package_identity_failed');
    if ($anchor['importer']['sha256'] !== PHASE1_IMPORTER_SHA256
        || $anchor['importer']['uid'] !== 10004 || $anchor['importer']['gid'] !== 1003
        || $anchor['importer']['mode'] !== 0644
        || $anchor['importer_package']['sha256'] !== PHASE1_IMPORTER_SHA256
        || $anchor['harness_package']['sha256'] !== $anchor['harness']['sha256']) {
        throw new Phase1Abort('runtime_package_identity_failed');
    }

    $receiptHash = assert_hex_hash($anchor['deployment_receipt_sha256'], 'deployment_receipt_hash_invalid');
    $receipt = read_exact_json_file($options['deployment-receipt'], $receiptHash, true, 'deployment_receipt');
    $receipt = verify_authenticated_record($receipt, $key, 'deployment_receipt');
    exact_keys($receipt, ['schema', 'activation_id', 'importer_package_sha256', 'preimage', 'postimage', 'applied_at_utc'], 'deployment_receipt_shape');
    if ($receipt['schema'] !== PHASE1_SECURITY_SCHEMA || $receipt['activation_id'] !== $anchor['activation_id']
        || $receipt['importer_package_sha256'] !== $anchor['importer_package']['sha256']
        || $receipt['postimage'] !== $anchor['importer']) {
        throw new Phase1Abort('deployment_receipt_cross_reference_failed');
    }
    exact_keys($receipt['preimage'], ['sha256', 'uid', 'gid', 'mode', 'size'], 'deployment_preimage_shape');
    assert_hex_hash($receipt['preimage']['sha256'] ?? null, 'deployment_preimage_hash_invalid');
    assert_canonical_identity($receipt['preimage'], [
        'sha256' => PHASE1_DEPLOYED_PREIMAGE_SHA256,
        'uid' => 10004,
        'gid' => 1003,
        'mode' => 0644,
        'size' => 13839,
    ], 'deployment_preimage_identity_failed');
    $applied = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', (string) $receipt['applied_at_utc'], new DateTimeZone('UTC'));
    if (! $applied || $applied->getTimestamp() > time()
        || ($requireFreshReceipt && time() - $applied->getTimestamp() > 86400)) {
        throw new Phase1Abort('deployment_receipt_stale');
    }
    exact_keys($anchor['locks'], ['phase', 'maintenance', 'calendar_cache_name'], 'lock_anchor_shape');
    foreach (['phase', 'maintenance'] as $lock) {
        $path = $anchor['locks'][$lock] ?? null;
        if (! is_string($path) || dirname($path) === '/' || basename($path) !== $lock.'.lock') {
            throw new Phase1Abort('lock_anchor_invalid');
        }
        assert_root_private(dirname($path), true, 'lock_directory_invalid');
    }
    if (dirname($anchor['locks']['phase']) !== dirname($anchor['locks']['maintenance'])
        || ! is_string($anchor['locks']['calendar_cache_name'])
        || preg_match('~\Aapi-football:calendar-sync:[0-9a-f]{64}\z~', $anchor['locks']['calendar_cache_name']) !== 1) {
        throw new Phase1Abort('lock_anchor_invalid');
    }

    return ['anchor' => $anchor, 'anchor_sha256' => $anchorHash, 'receipt' => $receipt,
        'receipt_sha256' => $receiptHash, 'root' => $root, 'authentication_key' => $key];
}

function calendar_lock_name(): string
{
    return 'api-football:calendar-sync:'.hash('sha256', strtolower(trim(
        (string) config('app.name', 'laravel')."\0".(string) config('app.env', 'production'),
    )));
}

function assert_calendar_lock_contract(array $contract): void
{
    if ($contract['anchor']['locks']['calendar_cache_name'] !== calendar_lock_name()) {
        throw new Phase1Abort('calendar_lock_identity_failed');
    }
}

function acquire_flock(string $path, string $classification): mixed
{
    $exists = file_exists($path) || is_link($path);
    if ($exists) {
        assert_root_private($path, false, $classification.'_file_invalid');
        $handle = fopen($path, 'r+b');
    } else {
        $handle = fopen($path, 'x+b');
        if ($handle !== false) {
            chmod($path, 0600);
        }
    }
    if ($handle === false) {
        throw new Phase1Abort($classification.'_open_failed');
    }
    $fd = fstat($handle);
    $pathStat = lstat($path);
    if ($fd === false || $pathStat === false || $fd['dev'] !== $pathStat['dev'] || $fd['ino'] !== $pathStat['ino']
        || $fd['uid'] !== 0 || ($fd['mode'] & 07777) !== 0600 || $fd['nlink'] !== 1
        || ! flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new Phase1Abort($classification.'_unavailable');
    }

    return $handle;
}

function assert_writer_exclusion(string $proofPath, string $proofHash): array
{
    $proof = read_exact_json_file($proofPath, $proofHash, true, 'writer_proof');
    $required = ['schema', 'captured_at_utc', 'scheduler_paused', 'workers_paused', 'writer_samples', 'cron_preimage_sha256', 'cron_paused_sha256'];
    exact_keys($proof, $required, 'writer_exclusion_shape_invalid');
    if ($proof['schema'] !== 1
        || $proof['scheduler_paused'] !== true || $proof['workers_paused'] !== true
        || ! is_array($proof['writer_samples']) || count($proof['writer_samples']) !== 2) {
        throw new Phase1Abort('writer_exclusion_not_proven');
    }
    $sampleTimes = [];
    foreach ($proof['writer_samples'] as $sample) {
        if (! is_array($sample)) {
            throw new Phase1Abort('writer_exclusion_sample_invalid');
        }
        exact_keys($sample, ['captured_at_utc', 'processes'], 'writer_exclusion_sample_invalid');
        if ($sample['processes'] !== []) {
            throw new Phase1Abort('writer_exclusion_sample_invalid');
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', (string) $sample['captured_at_utc'], new DateTimeZone('UTC'));
        if (! $time) {
            throw new Phase1Abort('writer_exclusion_sample_time_invalid');
        }
        $sampleTimes[] = $time->getTimestamp();
    }
    $captured = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', (string) $proof['captured_at_utc'], new DateTimeZone('UTC'));
    if (! $captured || $sampleTimes[1] !== $captured->getTimestamp()
        || $sampleTimes[1] - $sampleTimes[0] < 1 || $sampleTimes[1] - $sampleTimes[0] > 120
        || abs(time() - $captured->getTimestamp()) > 300) {
        throw new Phase1Abort('writer_proof_stale_or_nonconsecutive');
    }
    foreach (['cron_preimage_sha256', 'cron_paused_sha256'] as $field) {
        if (! is_string($proof[$field]) || preg_match('/\A[0-9a-f]{64}\z/', $proof[$field]) !== 1) {
            throw new Phase1Abort('writer_proof_hash_invalid');
        }
    }

    return $proof;
}

function database_snapshot(array $ids, bool $locking = false): array
{
    $finish = static function ($query) use ($locking): array {
        if ($locking) {
            $query->lockForUpdate();
        }

        return $query->get()->map(fn ($row) => (array) $row)->all();
    };
    $fixtures = $ids['fixture_ids'] === [] ? [] : $finish(DB::table('fixtures')
        ->whereIn('api_fixture_id', $ids['fixture_ids'])->orderBy('id'));
    $localFixtureIds = array_map(static fn (array $row): int => (int) $row['id'], $fixtures);
    $scores = $localFixtureIds === [] ? [] : $finish(DB::table('fixture_scores')
        ->whereIn('fixture_id', $localFixtureIds)->orderBy('id'));
    $teams = $ids['team_ids'] === [] ? [] : $finish(DB::table('teams')
        ->whereIn('api_team_id', $ids['team_ids'])->orderBy('id'));
    $leagues = $ids['league_ids'] === [] ? [] : $finish(DB::table('leagues')
        ->whereIn('api_league_id', $ids['league_ids'])->orderBy('id'));

    return [
        'fixtures' => $fixtures,
        'fixture_scores' => $scores,
        'teams' => $teams,
        'leagues' => $leagues,
        'hashes' => [
            'fixtures' => row_hashes($fixtures),
            'fixture_scores' => row_hashes($scores),
            'teams' => row_hashes($teams),
            'leagues' => row_hashes($leagues),
        ],
    ];
}

function foreign_key_inventory(): array
{
    $schema = (string) DB::getDatabaseName();
    $rows = DB::table('information_schema.KEY_COLUMN_USAGE')
        ->select(['TABLE_NAME', 'COLUMN_NAME', 'CONSTRAINT_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME'])
        ->where('CONSTRAINT_SCHEMA', $schema)
        ->whereIn('REFERENCED_TABLE_NAME', ['fixtures', 'fixture_scores', 'teams', 'leagues'])
        ->orderBy('REFERENCED_TABLE_NAME')->orderBy('TABLE_NAME')->orderBy('CONSTRAINT_NAME')->orderBy('ORDINAL_POSITION')
        ->get()->map(fn ($r) => (array) $r)->all();

    $primaryKeys = [];
    $tables = array_values(array_unique(array_column($rows, 'TABLE_NAME')));
    if ($tables !== []) {
        foreach (DB::table('information_schema.COLUMNS')
            ->select(['TABLE_NAME', 'COLUMN_NAME'])
            ->where('TABLE_SCHEMA', $schema)->whereIn('TABLE_NAME', $tables)->where('COLUMN_KEY', 'PRI')
            ->orderBy('TABLE_NAME')->orderBy('ORDINAL_POSITION')->get() as $column) {
            $primaryKeys[(string) $column->TABLE_NAME][] = (string) $column->COLUMN_NAME;
        }
    }
    foreach ($rows as &$row) {
        $row['CHILD_PRIMARY_KEY'] = $primaryKeys[(string) $row['TABLE_NAME']] ?? [];
        if ($row['CHILD_PRIMARY_KEY'] === []) {
            throw new Phase1Abort('foreign_key_child_primary_key_missing');
        }
    }
    unset($row);

    return $rows;
}

function invariant_snapshot(array $ids): array
{
    $duplicateFixtures = DB::table('fixtures')->select('api_fixture_id')
        ->groupBy('api_fixture_id')->havingRaw('COUNT(*) > 1')->count();
    $duplicateTeams = DB::table('teams')->select('api_team_id')
        ->groupBy('api_team_id')->havingRaw('COUNT(*) > 1')->count();
    $duplicateScores = DB::table('fixture_scores')->select('fixture_id')
        ->groupBy('fixture_id')->havingRaw('COUNT(*) > 1')->count();
    $orphanScores = DB::table('fixture_scores as s')
        ->leftJoin('fixtures as f', 'f.id', '=', 's.fixture_id')->whereNull('f.id')->count();
    $brokenRelationships = DB::table('fixtures as f')
        ->leftJoin('leagues as l', 'l.id', '=', 'f.league_id')
        ->leftJoin('teams as h', 'h.id', '=', 'f.home_team_id')
        ->leftJoin('teams as a', 'a.id', '=', 'f.away_team_id')
        ->where(function ($query): void {
            $query->whereNull('l.id')->orWhereNull('h.id')->orWhereNull('a.id');
        })->count();
    $sessionTimezone = (string) (DB::selectOne('SELECT @@session.time_zone AS tz')->tz ?? '');

    return [
        'duplicates' => ['fixtures' => $duplicateFixtures, 'teams' => $duplicateTeams, 'fixture_scores' => $duplicateScores],
        'orphans' => ['fixture_scores' => $orphanScores, 'fixture_relationships' => $brokenRelationships],
        'session_timezone' => $sessionTimezone,
        'target' => database_snapshot($ids),
    ];
}

function assert_invariants(array $state): void
{
    if ($state['duplicates'] !== ['fixtures' => 0, 'teams' => 0, 'fixture_scores' => 0]
        || $state['orphans'] !== ['fixture_scores' => 0, 'fixture_relationships' => 0]) {
        throw new Phase1Abort('relationship_or_duplicate_invariant_failed');
    }
    if ($state['session_timezone'] !== '+00:00') {
        throw new Phase1Abort('database_session_not_utc');
    }
}

function assert_import_result(array $result): void
{
    $keys = ['rows', 'accepted', 'upserted', 'protected', 'skipped', 'failed', 'skip_reasons', 'failure_reasons',
        'row_telemetry', 'row_telemetry_total', 'row_telemetry_truncated', 'row_telemetry_limit'];
    if (array_keys($result) !== $keys || $result['failed'] !== 0 || $result['failure_reasons'] !== []
        || $result['row_telemetry_limit'] !== 100 || $result['row_telemetry_truncated'] !== 0
        || $result['row_telemetry_total'] !== count($result['row_telemetry'])) {
        throw new Phase1Abort('telemetry_equation_failed');
    }
    foreach ($result['row_telemetry'] as $row) {
        if (array_keys($row) !== ['fixture_id', 'league_id', 'status', 'date_bucket', 'reason']) {
            throw new Phase1Abort('telemetry_shape_failed');
        }
    }
}

function assert_write_policy(array $before, array $after): void
{
    $beforeFixtures = [];
    foreach ($before['fixtures'] as $row) {
        $beforeFixtures[(string) $row['api_fixture_id']] = $row;
    }
    foreach ($after['fixtures'] as $row) {
        $key = (string) $row['api_fixture_id'];
        $status = normalized_status($row['status_short'] ?? null);
        if (isset($beforeFixtures[$key])) {
            $old = normalized_status($beforeFixtures[$key]['status_short'] ?? null);
            if (in_array($old, PHASE1_PROTECTED, true)
                && canonical_json($row) !== canonical_json($beforeFixtures[$key])) {
                throw new Phase1Abort('protected_row_changed');
            }
        }
        if ((! isset($beforeFixtures[$key]) || canonical_json($row) !== canonical_json($beforeFixtures[$key]))
            && ! in_array($status, PHASE1_IMPORTABLE, true)) {
            throw new Phase1Abort('write_policy_failed');
        }
    }
}

function assert_payload_relationships(array $payloads, array $before, array $after): void
{
    $beforeByProvider = [];
    foreach ($before['fixtures'] as $row) {
        $beforeByProvider[(int) $row['api_fixture_id']] = $row;
    }
    $afterByProvider = [];
    foreach ($after['fixtures'] as $row) {
        $afterByProvider[(int) $row['api_fixture_id']] = $row;
    }
    $leagueLocal = [];
    foreach ($after['leagues'] as $row) {
        $leagueLocal[(int) $row['api_league_id']] = (int) $row['id'];
    }
    $teamLocal = [];
    foreach ($after['teams'] as $row) {
        $teamLocal[(int) $row['api_team_id']] = (int) $row['id'];
    }
    $scoreCounts = array_count_values(array_map(static fn (array $row): int => (int) $row['fixture_id'], $after['fixture_scores']));

    foreach ($payloads as $date => $rows) {
        foreach ($rows as $data) {
            $manifest = sanitize_response_row($data, $date);
            if ($manifest['classification'] !== 'validated') {
                throw new Phase1Abort('relationship_input_invalid');
            }
            $fixtureId = $manifest['fixture_id'];
            $incoming = $manifest['status'];
            $old = $beforeByProvider[$fixtureId] ?? null;
            if ($old !== null && in_array(normalized_status($old['status_short'] ?? null), PHASE1_PROTECTED, true)) {
                continue;
            }
            if (! in_array($incoming, PHASE1_IMPORTABLE, true)) {
                if (isset($afterByProvider[$fixtureId]) && canonical_json($afterByProvider[$fixtureId]) !== canonical_json($old)) {
                    throw new Phase1Abort('nonimportable_row_changed');
                }

                continue;
            }
            $row = $afterByProvider[$fixtureId] ?? throw new Phase1Abort('importable_fixture_missing');
            $expected = [
                'league_id' => $leagueLocal[$manifest['league_id']] ?? null,
                'home_team_id' => $teamLocal[$manifest['home_team_id']] ?? null,
                'away_team_id' => $teamLocal[$manifest['away_team_id']] ?? null,
                'season' => $data['league']['season'] ?? null,
                'kick_off' => gmdate('Y-m-d H:i:s', $data['fixture']['timestamp']),
                'status_short' => $incoming,
            ];
            foreach ($expected as $field => $value) {
                $actual = $row[$field] ?? null;
                if ((string) $actual !== (string) $value) {
                    throw new Phase1Abort('league_team_season_utc_relationship_failed');
                }
            }
            if (($scoreCounts[(int) $row['id']] ?? 0) !== 1) {
                throw new Phase1Abort('fixture_score_multiplicity_failed');
            }
        }
    }
}

function merge_original_baseline(array $baseline, array $extension, array $ids): array
{
    foreach (['fixtures', 'fixture_scores', 'teams', 'leagues'] as $table) {
        $known = [];
        foreach ($baseline[$table] as $row) {
            $known[(string) $row['id']] = true;
        }
        foreach ($extension[$table] as $row) {
            if (! isset($known[(string) $row['id']])) {
                $baseline[$table][] = $row;
            }
        }
        usort($baseline[$table], static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        $baseline['hashes'][$table] = row_hashes($baseline[$table]);
    }
    $baseline['ids'] = $ids;

    return $baseline;
}

function fetch_phase_payloads(object $gateway, array $dates, Phase1AttemptBudget $budget): array
{
    $payloads = [];
    foreach ($dates as $date) {
        $payloads[$date] = $gateway->get(
            '/fixtures',
            ['date' => $date],
            'calendar',
            'FixtureCalendarSync',
            function () use ($budget): bool {
                $claimed = $budget->claim();
                if ($claimed) {
                    fault_boundary('after:provider-attempt-'.$budget->used());
                }

                return $claimed;
            },
        );
        fault_boundary('after:provider-response-'.$budget->used());
        if (! is_array($payloads[$date]) || ! array_is_list($payloads[$date])) {
            throw new Phase1Abort('provider_payload_not_list');
        }
    }

    return $payloads;
}

function run_activation(array $options): int
{
    if (assert_option_contract($options) !== 'activate') {
        throw new Phase1Abort('activation_mode_invalid');
    }
    assert_safe_environment();
    $contract = load_trust_contract($options, true);
    $root = $contract['root'];
    assert_root_private($options['evidence-dir'], true, 'evidence_directory_invalid');
    $evidence = new Phase1Evidence($options['evidence-dir'], true);

    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $connection = initialize_database_session_utc($app);
    if (defined('PHASE1_INTEGRATION_MODE') && PHASE1_INTEGRATION_MODE === true
        && isset($GLOBALS['phase1_integration_bootstrap']) && is_callable($GLOBALS['phase1_integration_bootstrap'])) {
        ($GLOBALS['phase1_integration_bootstrap'])($app);
    }
    establish_database_session_utc($connection);
    assert_calendar_lock_contract($contract);

    if (config('api_football.calendar.enabled', false) !== false) {
        throw new Phase1Abort('persistent_gate_not_false');
    }
    $dates = phase1_utc_date_scope();
    if ((new FixtureCalendarWindow)->dates('near') !== $dates) {
        throw new Phase1Abort('near_window_invalid');
    }
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains((string) $event->command, 'sync:fixture-calendar')) {
            throw new Phase1Abort('calendar_schedule_present');
        }
    }

    // Global order: phase flock -> calendar cache lock -> maintenance flock -> DB transaction/row locks.
    $phaseLock = acquire_flock($contract['anchor']['locks']['phase'], 'phase_lock');
    $calendarLock = null;
    $maintenanceLock = null;
    $transactionOpen = false;
    $committed = false;
    try {
        $calendarLock = Cache::store((string) config('api_football.calendar.lock_store', 'redis'))
            ->lock((string) $contract['anchor']['locks']['calendar_cache_name'], 7200);
        if (! $calendarLock->get()) {
            throw new Phase1Abort('calendar_lock_unavailable');
        }
        $maintenanceLock = acquire_flock($contract['anchor']['locks']['maintenance'], 'maintenance_lock');
        // Recheck every identity and evidence freshness under all exclusion locks.
        $contract = load_trust_contract($options, true);
        assert_calendar_lock_contract($contract);
        $evidence = new Phase1Evidence($options['evidence-dir'], true);
        $proof = assert_writer_exclusion($options['writer-proof'], $options['writer-proof-sha256']);

        $quota = app(ApiFootballQuotaStore::class);
        $quotaBaseline = $quota->status();
        if (FixtureCalendarWindow::dates('near') !== $dates) {
            throw new Phase1Abort('utc_date_scope_failed');
        }
        $logPath = $root.'/storage/logs/laravel.log';
        $logOffset = is_file($logPath) ? filesize($logPath) : 0;

        fault_boundary('before:outer-transaction');
        config()->set('api_football.calendar.enabled', true);
        $gateway = app(ApiFootballGateway::class);
        $importer = app(FixtureCalendarImporter::class);
        $budget = new Phase1AttemptBudget;
        establish_database_session_utc($connection);
        $connection->beginTransaction();
        $transactionOpen = true;

        fault_boundary('after:outer-transaction-begin');
        $t1Payloads = fetch_phase_payloads($gateway, $dates, $budget);
        $t1Manifests = [];
        foreach ($t1Payloads as $date => $payload) {
            $t1Manifests[$date] = sanitized_response($payload, $date);
        }
        $ids = ids_from_manifests($t1Manifests);
        $baseline = database_snapshot($ids);
        $baseline['ids'] = $ids;
        $fks = foreign_key_inventory();
        $baselineInvariant = invariant_snapshot($ids);
        assert_invariants($baselineInvariant);

        $telemetry = ['T1' => [], 'T2' => []];
        foreach ($dates as $date) {
            $telemetry['T1'][$date] = $importer->import($t1Payloads[$date]);
            assert_import_result($telemetry['T1'][$date]);
        }
        $t1 = database_snapshot($ids);
        assert_write_policy($baseline, $t1);
        $t1Invariant = invariant_snapshot($ids);
        assert_payload_relationships($t1Payloads, $baseline, $t1);
        assert_invariants($t1Invariant);

        if ($budget->remaining() < 2) {
            throw new Phase1Abort('insufficient_budget_for_t2');
        }
        $t2Payloads = fetch_phase_payloads($gateway, $dates, $budget);
        $t2Manifests = [];
        foreach ($t2Payloads as $date => $payload) {
            $t2Manifests[$date] = sanitized_response($payload, $date);
        }
        $t1Ids = $ids;
        $t2Ids = ids_from_manifests($t2Manifests);
        $newT2Ids = [
            'fixture_ids' => array_values(array_diff($t2Ids['fixture_ids'], $t1Ids['fixture_ids'])),
            'team_ids' => array_values(array_diff($t2Ids['team_ids'], $t1Ids['team_ids'])),
            'league_ids' => array_values(array_diff($t2Ids['league_ids'], $t1Ids['league_ids'])),
        ];
        $ids = [
            'fixture_ids' => array_values(array_unique([...$ids['fixture_ids'], ...$t2Ids['fixture_ids']])),
            'team_ids' => array_values(array_unique([...$ids['team_ids'], ...$t2Ids['team_ids']])),
            'league_ids' => array_values(array_unique([...$ids['league_ids'], ...$t2Ids['league_ids']])),
        ];
        foreach ($ids as &$set) {
            sort($set, SORT_NUMERIC);
        }
        unset($set);
        $baseline = merge_original_baseline($baseline, database_snapshot($newT2Ids), $ids);

        foreach ($dates as $date) {
            $telemetry['T2'][$date] = $importer->import($t2Payloads[$date]);
            assert_import_result($telemetry['T2'][$date]);
        }
        $t2 = database_snapshot($ids);
        assert_write_policy($baseline, $t2);
        $t2Invariant = invariant_snapshot($ids);
        assert_payload_relationships($t2Payloads, $baseline, $t2);
        assert_invariants($t2Invariant);
        if ($t1['hashes'] !== $t2['hashes']) {
            throw new Phase1Abort('t2_not_idempotent');
        }
        if ($budget->used() > PHASE1_MAX_ATTEMPTS) {
            throw new Phase1Abort('global_attempt_budget_exceeded');
        }

        $quotaAfter = $quota->status();
        $intent = [
            'schema' => PHASE1_SECURITY_SCHEMA,
            'state' => 'PRECOMMIT_INTENT',
            'activation_id' => $contract['anchor']['activation_id'],
            'trust' => [
                'anchor_sha256' => $contract['anchor_sha256'],
                'deployment_receipt_sha256' => $contract['receipt_sha256'],
                'writer_proof_sha256' => $options['writer-proof-sha256'],
                'importer_package_sha256' => $contract['anchor']['importer_package']['sha256'],
                'harness_package_sha256' => $contract['anchor']['harness_package']['sha256'],
            ],
            'dates_utc' => $dates,
            'attempts' => ['baseline' => $quotaBaseline, 'after' => $quotaAfter, 'physical' => $budget->used(), 'maximum' => PHASE1_MAX_ATTEMPTS],
            'writer_exclusion' => $proof,
            'ids' => $ids,
            'foreign_keys' => $fks,
            'baseline' => $baseline,
            'postimage' => $t2,
            'invariants' => ['baseline' => $baselineInvariant, 't1' => $t1Invariant, 't2' => $t2Invariant],
            'responses' => ['T1' => $t1Manifests, 'T2' => $t2Manifests],
            'telemetry' => $telemetry,
            'runtime' => [
                'importer' => $contract['anchor']['importer'],
                'harness' => $contract['anchor']['harness'],
                'log_offset' => $logOffset,
            ],
        ];
        $hashes = ['commit-intent.json' => $evidence->writeAuthenticated('commit-intent.json', $intent, $contract['authentication_key'])];
        $chain = [
            'activation_id' => $contract['anchor']['activation_id'],
            'trust_anchor_sha256' => $contract['anchor_sha256'],
            'deployment_receipt_sha256' => $contract['receipt_sha256'],
            'importer_package_sha256' => $contract['anchor']['importer_package']['sha256'],
            'harness_package_sha256' => $contract['anchor']['harness_package']['sha256'],
        ];
        $precommitHash = $evidence->seal('precommit', $hashes, $chain, $contract['authentication_key']);
        fault_boundary('before:outer-commit');
        $connection->commit();
        $transactionOpen = false;
        $committed = true;
        fault_boundary('after:outer-commit');
        $committedHash = $evidence->writeAuthenticated('COMMITTED.json', [
            'schema' => PHASE1_SECURITY_SCHEMA,
            'state' => 'COMMITTED_PENDING_POSTCHECKS',
            'activation_id' => $contract['anchor']['activation_id'],
            'intent_sha256' => $hashes['commit-intent.json'],
            'precommit_seal_sha256' => $precommitHash,
            'trust_anchor_sha256' => $contract['anchor_sha256'],
            'committed_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ], $contract['authentication_key']);
        config()->set('api_football.calendar.enabled', false);

        $fresh = database_snapshot($ids);
        if ($fresh['hashes'] !== $t2['hashes']) {
            throw new Phase1Abort('postcommit_database_drift');
        }
        $finalQuota = $quota->status();
        if ((int) ($finalQuota['global'] ?? -1) - (int) ($quotaBaseline['global'] ?? -1) !== $budget->used()) {
            throw new Phase1Abort('postcommit_quota_accounting_mismatch');
        }
        $healthUrls = read_exact_json_file(
            $options['health-urls'],
            $options['health-urls-sha256'],
            true,
            'health_url_manifest',
        );
        if (! is_array($healthUrls) || ! array_is_list($healthUrls) || $healthUrls === [] || count($healthUrls) > 20) {
            throw new Phase1Abort('health_url_manifest_invalid');
        }
        $httpResults = [];
        foreach ($healthUrls as $entry) {
            if (! is_array($entry)) {
                throw new Phase1Abort('health_url_entry_invalid');
            }
            exact_keys($entry, ['url', 'host'], 'health_url_entry_invalid');
            $url = $entry['url'];
            $host = $entry['host'];
            if (! is_string($url) || strlen($url) > 300 || ! is_string($host)
                || preg_match('/\A(?=.{1,253}\z)[A-Za-z0-9.-]+\z/', $host) !== 1) {
                throw new Phase1Abort('health_url_invalid');
            }
            $parts = parse_url($url);
            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'http'
                || ! in_array($parts['host'] ?? '', ['127.0.0.1', '::1'], true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new Phase1Abort('health_url_not_loopback_plain_http');
            }
            $response = Http::withHeaders(['Host' => $host])->timeout(10)->get($url);
            $httpResults[] = [
                'url_sha256' => hash('sha256', $url),
                'host_sha256' => hash('sha256', strtolower($host)),
                'status' => $response->status(),
            ];
            if ($response->status() !== 200) {
                throw new Phase1Abort('postcommit_http_ui_failed');
            }
        }
        clearstatcache(true, $logPath);
        $logSize = is_file($logPath) ? filesize($logPath) : 0;
        if ($logSize < $logOffset || $logSize - $logOffset > 1048576) {
            throw new Phase1Abort('postcommit_log_scan_unbounded');
        }
        $logDelta = '';
        if ($logSize > $logOffset) {
            $handle = fopen($logPath, 'rb');
            if ($handle === false || fseek($handle, $logOffset) !== 0) {
                throw new Phase1Abort('postcommit_log_open_failed');
            }
            $logDelta = (string) fread($handle, $logSize - $logOffset);
            fclose($handle);
        }
        if (preg_match('/(?:ERROR|CRITICAL|ALERT|EMERGENCY|calendar_sync_(?:failed|partial_failure|blocked))/i', $logDelta) === 1) {
            throw new Phase1Abort('postcommit_log_gate_failed');
        }
        $evidence->writeAuthenticated('SAFE_TO_RESTORE_WRITERS.json', [
            'schema' => PHASE1_SECURITY_SCHEMA,
            'state' => 'POSTCHECKS_PASSED',
            'activation_id' => $contract['anchor']['activation_id'],
            'intent_sha256' => $hashes['commit-intent.json'],
            'precommit_seal_sha256' => $precommitHash,
            'committed_marker_sha256' => $committedHash,
            'trust_anchor_sha256' => $contract['anchor_sha256'],
            'database_postimage_sha256' => hash('sha256', canonical_json($fresh['hashes'])),
            'quota_after' => $finalQuota,
            'http_ui' => $httpResults,
            'log_delta_sha256' => hash('sha256', $logDelta),
            'authorized_next_steps' => ['cron_restore_exact', 'worker_restore_exact'],
        ], $contract['authentication_key']);

        return 0;
    } catch (Throwable $failure) {
        if ($transactionOpen) {
            $connection->rollBack();
            $transactionOpen = false;
        }
        $evidence->writeAuthenticated('ABORT.json', [
            'schema' => PHASE1_SECURITY_SCHEMA,
            'state' => $committed ? 'POSTCOMMIT_ABORT_WRITERS_MUST_REMAIN_PAUSED' : 'PRECOMMIT_ABORT_ROLLED_BACK',
            'classification' => $failure instanceof Phase1Abort ? $failure->getMessage() : 'unexpected_failure',
        ], $contract['authentication_key']);
        throw $failure;
    } finally {
        config()->set('api_football.calendar.enabled', false);
        if (is_resource($maintenanceLock)) {
            flock($maintenanceLock, LOCK_UN);
            fclose($maintenanceLock);
        }
        if ($calendarLock !== null) {
            try {
                $calendarLock->release();
            } catch (Throwable) {
            }
        }
        flock($phaseLock, LOCK_UN);
        fclose($phaseLock);
    }
}

function lock_recovery_targets(array $ids): void
{
    if ($ids['fixture_ids'] !== []) {
        $fixtures = DB::table('fixtures')
            ->whereIn('api_fixture_id', $ids['fixture_ids'])->orderBy('id')->lockForUpdate()->get(['id']);
        $localFixtureIds = $fixtures->pluck('id')->map('intval')->all();
        if ($localFixtureIds !== []) {
            DB::table('fixture_scores')
                ->whereIn('fixture_id', $localFixtureIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }
    if ($ids['team_ids'] !== []) {
        DB::table('teams')
            ->whereIn('api_team_id', $ids['team_ids'])->orderBy('id')->lockForUpdate()->get(['id']);
    }
    if ($ids['league_ids'] !== []) {
        DB::table('leagues')
            ->whereIn('api_league_id', $ids['league_ids'])->orderBy('id')->lockForUpdate()->get(['id']);
    }
}

function assert_recovery_references(array $intent): void
{
    $liveFks = foreign_key_inventory();
    if (canonical_json($liveFks) !== canonical_json($intent['foreign_keys'])) {
        throw new Phase1Abort('foreign_key_inventory_drift');
    }
    $inserted = [];
    foreach (['fixtures', 'fixture_scores', 'teams'] as $table) {
        $beforeIds = array_fill_keys(array_map('strval', array_column($intent['baseline'][$table], 'id')), true);
        $inserted[$table] = array_values(array_filter(
            $intent['postimage'][$table],
            static fn (array $row): bool => ! isset($beforeIds[(string) $row['id']]),
        ));
    }

    foreach ($liveFks as $fk) {
        foreach (['TABLE_NAME', 'COLUMN_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME'] as $identifier) {
            if (! is_string($fk[$identifier] ?? null)
                || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $fk[$identifier]) !== 1) {
                throw new Phase1Abort('foreign_key_identifier_invalid');
            }
        }
        if (! is_array($fk['CHILD_PRIMARY_KEY'] ?? null) || ! array_is_list($fk['CHILD_PRIMARY_KEY'])
            || $fk['CHILD_PRIMARY_KEY'] === []) {
            throw new Phase1Abort('foreign_key_child_primary_key_invalid');
        }
        foreach ($fk['CHILD_PRIMARY_KEY'] as $primaryColumn) {
            if (! is_string($primaryColumn) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $primaryColumn) !== 1) {
                throw new Phase1Abort('foreign_key_child_primary_key_invalid');
            }
        }
        $parent = (string) $fk['REFERENCED_TABLE_NAME'];
        if (! isset($inserted[$parent]) || $inserted[$parent] === []) {
            continue;
        }
        $values = array_values(array_unique(array_map(
            static fn (array $row): mixed => $row[$fk['REFERENCED_COLUMN_NAME']] ?? throw new Phase1Abort('fk_parent_column_missing'),
            $inserted[$parent],
        )));
        // Parent rows are already locked. Lock every matching dynamic child row;
        // a concurrent insert must wait on the parent FK record lock.
        $childRows = DB::table((string) $fk['TABLE_NAME'])
            ->whereIn((string) $fk['COLUMN_NAME'], $values)
            ->orderBy((string) $fk['COLUMN_NAME']);
        foreach ($fk['CHILD_PRIMARY_KEY'] as $primaryColumn) {
            $childRows->orderBy($primaryColumn);
        }
        $childRows = $childRows->lockForUpdate()->get()->map(fn ($row) => (array) $row)->all();
        $allowed = match ([$parent, (string) $fk['TABLE_NAME']]) {
            ['fixtures', 'fixture_scores'] => array_map(static fn (array $row): int => (int) $row['id'], $inserted['fixture_scores']),
            ['teams', 'fixtures'] => array_map(static fn (array $row): int => (int) $row['id'], $inserted['fixtures']),
            default => null,
        };
        if ($allowed === null) {
            if ($childRows !== []) {
                throw new Phase1Abort('inserted_row_has_unexpected_fk_reference');
            }

            continue;
        }
        $childIds = array_map(static function (array $row): int {
            if (! isset($row['id']) || positive_id($row['id']) === null) {
                throw new Phase1Abort('expected_fk_child_primary_key_missing');
            }

            return (int) $row['id'];
        }, $childRows);
        sort($childIds, SORT_NUMERIC);
        sort($allowed, SORT_NUMERIC);
        $childIds = array_values(array_unique($childIds));
        if ($childIds !== [] && $childIds !== array_values(array_unique($allowed))) {
            throw new Phase1Abort('inserted_row_has_unexpected_fk_reference');
        }
    }
}

function assert_intent_rows(array $rows, string $table, array $expectedHashes): void
{
    if (! array_is_list($rows)) {
        throw new Phase1Abort('intent_'.$table.'_rows_invalid');
    }
    $previous = 0;
    foreach ($rows as $row) {
        if (! is_array($row) || ! isset($row['id']) || ! is_int($row['id']) || $row['id'] <= $previous) {
            throw new Phase1Abort('intent_'.$table.'_rows_invalid');
        }
        $previous = $row['id'];
    }
    if (! canonical_identity_matches(row_hashes($rows), $expectedHashes)) {
        throw new Phase1Abort('intent_'.$table.'_hash_invalid');
    }
}

function validate_recovery_chain(Phase1Evidence $evidence, array $contract): array
{
    $intent = $evidence->readAuthenticated('commit-intent.json', $contract['authentication_key'], 'commit_intent');
    exact_keys($intent, ['schema', 'state', 'activation_id', 'trust', 'dates_utc', 'attempts', 'writer_exclusion',
        'ids', 'foreign_keys', 'baseline', 'postimage', 'invariants', 'responses', 'telemetry', 'runtime'], 'intent_shape_invalid');
    if ($intent['schema'] !== PHASE1_SECURITY_SCHEMA || $intent['state'] !== 'PRECOMMIT_INTENT'
        || $intent['activation_id'] !== $contract['anchor']['activation_id']) {
        throw new Phase1Abort('intent_identity_invalid');
    }
    if (! is_array($intent['dates_utc']) || ! array_is_list($intent['dates_utc']) || count($intent['dates_utc']) !== 2) {
        throw new Phase1Abort('intent_dates_invalid');
    }
    $date0 = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $intent['dates_utc'][0], new DateTimeZone('UTC'));
    $date1 = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $intent['dates_utc'][1], new DateTimeZone('UTC'));
    if (! $date0 || ! $date1 || $date0->format('Y-m-d') !== $intent['dates_utc'][0]
        || $date1->format('Y-m-d') !== $intent['dates_utc'][1] || $date0->modify('+1 day') != $date1) {
        throw new Phase1Abort('intent_dates_invalid');
    }
    if (! is_array($intent['attempts'])) {
        throw new Phase1Abort('intent_attempts_invalid');
    }
    exact_keys($intent['attempts'], ['baseline', 'after', 'physical', 'maximum'], 'intent_attempts_shape_invalid');
    if (! is_int($intent['attempts']['physical']) || $intent['attempts']['physical'] < 1
        || $intent['attempts']['physical'] > PHASE1_MAX_ATTEMPTS
        || $intent['attempts']['maximum'] !== PHASE1_MAX_ATTEMPTS
        || ! is_array($intent['attempts']['baseline']) || ! is_array($intent['attempts']['after'])) {
        throw new Phase1Abort('intent_attempts_invalid');
    }
    exact_keys($intent['trust'], ['anchor_sha256', 'deployment_receipt_sha256', 'writer_proof_sha256',
        'importer_package_sha256', 'harness_package_sha256'], 'intent_trust_shape_invalid');
    $expectedTrust = [
        'anchor_sha256' => $contract['anchor_sha256'],
        'deployment_receipt_sha256' => $contract['receipt_sha256'],
        'writer_proof_sha256' => sha256_value($intent['writer_exclusion']),
        'importer_package_sha256' => $contract['anchor']['importer_package']['sha256'],
        'harness_package_sha256' => $contract['anchor']['harness_package']['sha256'],
    ];
    if (canonical_json($intent['trust']) !== canonical_json($expectedTrust)) {
        throw new Phase1Abort('intent_trust_cross_reference_failed');
    }
    exact_keys($intent['ids'], ['fixture_ids', 'team_ids', 'league_ids'], 'intent_ids_shape_invalid');
    foreach ($intent['ids'] as $ids) {
        if (! is_array($ids) || ! array_is_list($ids) || $ids !== array_values(array_unique($ids))) {
            throw new Phase1Abort('intent_ids_invalid');
        }
        $sorted = $ids;
        sort($sorted, SORT_NUMERIC);
        if ($sorted !== $ids || array_filter($ids, static fn ($id): bool => ! is_int($id) || positive_id($id) === null) !== []) {
            throw new Phase1Abort('intent_ids_invalid');
        }
    }
    foreach (['baseline', 'postimage'] as $imageName) {
        $image = $intent[$imageName];
        $keys = ['fixtures', 'fixture_scores', 'teams', 'leagues', 'hashes'];
        if ($imageName === 'baseline') {
            $keys[] = 'ids';
        }
        exact_keys($image, $keys, 'intent_'.$imageName.'_shape_invalid');
        exact_keys($image['hashes'], ['fixtures', 'fixture_scores', 'teams', 'leagues'], 'intent_hash_shape_invalid');
        foreach (['fixtures', 'fixture_scores', 'teams', 'leagues'] as $table) {
            assert_intent_rows($image[$table], $imageName.'_'.$table, $image['hashes'][$table]);
        }
    }
    if (($intent['baseline']['ids'] ?? null) !== $intent['ids']) {
        throw new Phase1Abort('intent_baseline_ids_invalid');
    }
    if (! is_array($intent['runtime'])) {
        throw new Phase1Abort('intent_runtime_invalid');
    }
    exact_keys($intent['runtime'], ['importer', 'harness', 'log_offset'], 'intent_runtime_shape_invalid');
    if ($intent['runtime']['importer'] !== $contract['anchor']['importer']
        || $intent['runtime']['harness'] !== $contract['anchor']['harness']
        || ! is_int($intent['runtime']['log_offset']) || $intent['runtime']['log_offset'] < 0) {
        throw new Phase1Abort('intent_runtime_identity_invalid');
    }

    $intentHash = $evidence->hash('commit-intent.json');
    $precommit = $evidence->readAuthenticated('precommit.sha256.json', $contract['authentication_key'], 'precommit_seal');
    exact_keys($precommit, ['schema', 'phase', 'chain', 'files'], 'precommit_seal_shape_invalid');
    $expectedChain = [
        'activation_id' => $contract['anchor']['activation_id'],
        'trust_anchor_sha256' => $contract['anchor_sha256'],
        'deployment_receipt_sha256' => $contract['receipt_sha256'],
        'importer_package_sha256' => $contract['anchor']['importer_package']['sha256'],
        'harness_package_sha256' => $contract['anchor']['harness_package']['sha256'],
    ];
    if ($precommit['schema'] !== PHASE1_SECURITY_SCHEMA || $precommit['phase'] !== 'precommit'
        || canonical_json($precommit['chain']) !== canonical_json($expectedChain) || $precommit['files'] !== ['commit-intent.json' => $intentHash]) {
        throw new Phase1Abort('precommit_seal_cross_reference_failed');
    }
    $precommitHash = $evidence->hash('precommit.sha256.json');
    $committed = $evidence->readAuthenticated('COMMITTED.json', $contract['authentication_key'], 'committed_marker');
    exact_keys($committed, ['schema', 'state', 'activation_id', 'intent_sha256', 'precommit_seal_sha256',
        'trust_anchor_sha256', 'committed_at_utc'], 'committed_marker_shape_invalid');
    if ($committed['schema'] !== PHASE1_SECURITY_SCHEMA || $committed['state'] !== 'COMMITTED_PENDING_POSTCHECKS'
        || $committed['activation_id'] !== $contract['anchor']['activation_id']
        || $committed['intent_sha256'] !== $intentHash || $committed['precommit_seal_sha256'] !== $precommitHash
        || $committed['trust_anchor_sha256'] !== $contract['anchor_sha256']) {
        throw new Phase1Abort('committed_marker_cross_reference_failed');
    }

    return ['intent' => $intent, 'intent_sha256' => $intentHash, 'precommit_sha256' => $precommitHash,
        'committed_sha256' => $evidence->hash('COMMITTED.json')];
}

function run_recovery(array $options): int
{
    if (assert_option_contract($options) !== 'recover') {
        throw new Phase1Abort('recovery_mode_invalid');
    }
    assert_safe_environment();
    $contract = load_trust_contract($options, false);
    assert_root_private($options['evidence-dir'], true, 'evidence_directory_invalid');
    $root = $contract['root'];

    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $connection = initialize_database_session_utc($app);
    if (defined('PHASE1_INTEGRATION_MODE') && PHASE1_INTEGRATION_MODE === true
        && isset($GLOBALS['phase1_integration_bootstrap']) && is_callable($GLOBALS['phase1_integration_bootstrap'])) {
        ($GLOBALS['phase1_integration_bootstrap'])($app);
    }
    establish_database_session_utc($connection);
    assert_calendar_lock_contract($contract);
    if (config('api_football.calendar.enabled', false) !== false) {
        throw new Phase1Abort('persistent_gate_not_false');
    }

    // Same global order as activation: phase -> calendar -> maintenance -> transaction -> rows/FKs.
    $phaseLock = acquire_flock($contract['anchor']['locks']['phase'], 'phase_lock');
    $calendarLock = null;
    $maintenanceLock = null;
    try {
        $calendarLock = Cache::store((string) config('api_football.calendar.lock_store', 'redis'))
            ->lock((string) $contract['anchor']['locks']['calendar_cache_name'], 7200);
        if (! $calendarLock->get()) {
            throw new Phase1Abort('calendar_lock_unavailable');
        }
        $maintenanceLock = acquire_flock($contract['anchor']['locks']['maintenance'], 'maintenance_lock');
        // Authenticate immutable evidence only after every exclusion lock is held.
        $contract = load_trust_contract($options, false);
        assert_calendar_lock_contract($contract);
        $evidence = new Phase1Evidence($options['evidence-dir'], false);
        $chain = validate_recovery_chain($evidence, $contract);
        $intent = $chain['intent'];
        $proof = assert_writer_exclusion($options['writer-proof'], $options['writer-proof-sha256']);
        establish_database_session_utc($connection);
        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $outcome = $connection->transaction(function () use ($intent, $options): string {
            // Locking reads are the first target-state reads in this transaction.
            lock_recovery_targets($intent['ids']);
            assert_recovery_references($intent);
            $locked = database_snapshot($intent['ids'], true);
            $matchesPost = canonical_identity_matches($locked['hashes'], $intent['postimage']['hashes']);
            $matchesPre = canonical_identity_matches($locked['hashes'], $intent['baseline']['hashes']);
            if (! $matchesPost && ! $matchesPre) {
                throw new Phase1Abort('recovery_state_is_mixed_or_advanced');
            }
            if ($options['decision'] === 'keep') {
                if (! $matchesPost) {
                    throw new Phase1Abort('commit_not_verifiable');
                }

                return 'COMMIT_VERIFIED';
            }
            if ($matchesPre) {
                return 'ALREADY_AT_PREIMAGE';
            }
            if (! $matchesPost) {
                throw new Phase1Abort('rollback_requires_exact_postimage');
            }

            $baseline = $intent['baseline'];
            $post = $intent['postimage'];
            foreach (['fixture_scores', 'fixtures', 'teams'] as $table) {
                $beforeIds = array_column($baseline[$table], null, 'id');
                $postIds = array_column($post[$table], null, 'id');
                $inserted = array_values(array_diff(array_keys($postIds), array_keys($beforeIds)));
                sort($inserted, SORT_NUMERIC);
                if ($inserted !== []) {
                    DB::table($table)->whereIn('id', $inserted)->delete();
                }
            }
            foreach (['teams', 'fixtures', 'fixture_scores'] as $table) {
                foreach ($baseline[$table] as $row) {
                    $id = $row['id'];
                    unset($row['id']);
                    DB::table($table)->where('id', $id)->update($row);
                }
            }
            $restored = database_snapshot($intent['ids'], true);
            if (! canonical_identity_matches($restored['hashes'], $baseline['hashes'])) {
                throw new Phase1Abort('rollback_preimage_verification_failed');
            }
            assert_invariants(invariant_snapshot($intent['ids']));

            return 'EXACT_PREIMAGE_RESTORED';
        }, 3);

        $name = $options['decision'] === 'keep' ? 'RECOVERED-COMMIT.json' : 'RECOVERED-ROLLBACK.json';
        $marker = [
            'schema' => PHASE1_SECURITY_SCHEMA,
            'state' => $outcome,
            'activation_id' => $contract['anchor']['activation_id'],
            'intent_sha256' => $chain['intent_sha256'],
            'precommit_seal_sha256' => $chain['precommit_sha256'],
            'committed_marker_sha256' => $chain['committed_sha256'],
            'trust_anchor_sha256' => $contract['anchor_sha256'],
            'writer_proof_sha256' => $options['writer-proof-sha256'],
        ];
        if (is_file($evidence->directory().'/'.$name)) {
            $existing = $evidence->readAuthenticated($name, $contract['authentication_key'], 'recovery_marker');
            exact_keys($existing, array_keys($marker), 'recovery_marker_shape_invalid');
            $allowedStates = $options['decision'] === 'keep'
                ? ['COMMIT_VERIFIED']
                : ['EXACT_PREIMAGE_RESTORED', 'ALREADY_AT_PREIMAGE'];
            foreach (['schema', 'activation_id', 'intent_sha256', 'precommit_seal_sha256',
                'committed_marker_sha256', 'trust_anchor_sha256'] as $field) {
                if (($existing[$field] ?? null) !== $marker[$field]) {
                    throw new Phase1Abort('recovery_marker_replay_or_tamper');
                }
            }
            if (! in_array($existing['state'], $allowedStates, true)) {
                throw new Phase1Abort('recovery_marker_replay_or_tamper');
            }
            assert_hex_hash($existing['writer_proof_sha256'] ?? null, 'recovery_marker_writer_proof_invalid');
        } else {
            $evidence->writeAuthenticated($name, $marker, $contract['authentication_key']);
        }

        return 0;
    } finally {
        if (is_resource($maintenanceLock)) {
            flock($maintenanceLock, LOCK_UN);
            fclose($maintenanceLock);
        }
        if ($calendarLock !== null) {
            try {
                $calendarLock->release();
            } catch (Throwable) {
            }
        }
        flock($phaseLock, LOCK_UN);
        fclose($phaseLock);
    }
}
if (! defined('PHASE1_LIBRARY_ONLY')) {
    try {
        $options = parse_options($argv);
        $mode = assert_option_contract($options);
        $exit = match ($mode) {
            'activate' => run_activation($options),
            'recover' => run_recovery($options),
        };
        exit($exit);
    } catch (Throwable $failure) {
        fwrite(STDERR, canonical_json([
            'status' => 'BLOCKED',
            'classification' => $failure instanceof Phase1Abort ? $failure->getMessage() : 'unexpected_failure',
        ])."\n");
        exit(1);
    }
}
