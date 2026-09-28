#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\ApiFootball\ApiFootballGateway;
use App\Services\ApiFootball\RedisApiFootballQuotaStore;
use App\Services\FixtureCalendarImporter;
use App\Support\FixtureCalendarWindow;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
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
const PHASE1_SCHEMA = 1;
const PHASE1_MAX_ATTEMPTS = 4;
const PHASE1_MAX_ROWS_PER_DATE = 2000;
const PHASE1_IMPORTABLE = ['NS', 'TBD', 'PST', 'CANC'];
const PHASE1_PROTECTED = [
    '1H', '2H', 'HT', 'ET', 'BT', 'P', 'SUSP', 'INT', 'LIVE',
    'FT', 'AET', 'PEN', 'AWD', 'WO', 'CANC', 'ABD',
];

final class Phase1Abort extends RuntimeException {}

final class Phase1Evidence
{
    public function __construct(private readonly string $directory)
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new Phase1Abort('evidence_directory_create_failed');
        }
        chmod($directory, 0700);
        if ((fileperms($directory) & 0777) !== 0700) {
            throw new Phase1Abort('evidence_directory_mode_invalid');
        }
    }

    public function write(string $name, mixed $value): string
    {
        if (! preg_match('/\A[A-Za-z0-9._-]+\z/', $name)) {
            throw new Phase1Abort('invalid_evidence_name');
        }
        $json = canonical_json($value)."\n";
        $temporary = $this->directory.'/.'.$name.'.'.bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new Phase1Abort('evidence_open_failed');
        }
        chmod($temporary, 0600);
        try {
            if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle) || ! fsync($handle)) {
                throw new Phase1Abort('evidence_fsync_failed');
            }
        } finally {
            fclose($handle);
        }
        if (! rename($temporary, $this->directory.'/'.$name)) {
            throw new Phase1Abort('evidence_rename_failed');
        }
        $directoryHandle = fopen($this->directory, 'r');
        if ($directoryHandle === false || ! fsync($directoryHandle)) {
            if (is_resource($directoryHandle)) {
                fclose($directoryHandle);
            }
            throw new Phase1Abort('evidence_directory_fsync_failed');
        }
        fclose($directoryHandle);

        return hash('sha256', $json);
    }

    public function read(string $name): array
    {
        $path = $this->directory.'/'.$name;
        if (! is_file($path) || is_link($path) || (fileperms($path) & 0777) !== 0600) {
            throw new Phase1Abort('evidence_file_invalid');
        }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new Phase1Abort('evidence_json_invalid');
        }

        return $decoded;
    }

    public function seal(string $phase, array $files): void
    {
        ksort($files, SORT_STRING);
        $this->write($phase.'.sha256.json', [
            'schema' => PHASE1_SCHEMA,
            'phase' => $phase,
            'files' => $files,
        ]);
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

function assert_writer_exclusion(string $proofPath, string $proofHash): array
{
    if (! is_file($proofPath) || is_link($proofPath) || ! hash_equals($proofHash, hash_file('sha256', $proofPath))) {
        throw new Phase1Abort('writer_proof_identity_failed');
    }
    $proof = json_decode((string) file_get_contents($proofPath), true, 512, JSON_THROW_ON_ERROR);
    $required = ['schema', 'captured_at_utc', 'scheduler_paused', 'workers_paused', 'writer_samples', 'cron_preimage_sha256', 'cron_paused_sha256'];
    if (! is_array($proof) || array_keys($proof) !== $required || $proof['schema'] !== 1
        || $proof['scheduler_paused'] !== true || $proof['workers_paused'] !== true
        || ! is_array($proof['writer_samples']) || count($proof['writer_samples']) !== 2) {
        throw new Phase1Abort('writer_exclusion_not_proven');
    }
    $sampleTimes = [];
    foreach ($proof['writer_samples'] as $sample) {
        if (! is_array($sample) || array_keys($sample) !== ['captured_at_utc', 'processes'] || $sample['processes'] !== []) {
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

function database_snapshot(array $ids): array
{
    $fixtures = $ids['fixture_ids'] === [] ? [] : DB::table('fixtures')
        ->whereIn('api_fixture_id', $ids['fixture_ids'])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $localFixtureIds = array_map(static fn (array $row): int => (int) $row['id'], $fixtures);
    $scores = $localFixtureIds === [] ? [] : DB::table('fixture_scores')
        ->whereIn('fixture_id', $localFixtureIds)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $teams = $ids['team_ids'] === [] ? [] : DB::table('teams')
        ->whereIn('api_team_id', $ids['team_ids'])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $leagues = $ids['league_ids'] === [] ? [] : DB::table('leagues')
        ->whereIn('api_league_id', $ids['league_ids'])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

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
    if (! in_array($state['session_timezone'], ['+00:00', 'UTC'], true)) {
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
            fn (): bool => $budget->claim(),
        );
        if (! is_array($payloads[$date]) || ! array_is_list($payloads[$date])) {
            throw new Phase1Abort('provider_payload_not_list');
        }
    }

    return $payloads;
}

function run_activation(array $options): int
{
    foreach (['app-root', 'evidence-dir', 'writer-proof', 'writer-proof-sha256', 'health-urls', 'health-urls-sha256'] as $required) {
        if (! isset($options[$required]) || $options[$required] === '') {
            throw new Phase1Abort('missing_'.$required);
        }
    }
    $proof = assert_writer_exclusion($options['writer-proof'], $options['writer-proof-sha256']);
    $evidence = new Phase1Evidence($options['evidence-dir']);
    $root = realpath($options['app-root']);
    if ($root === false || ! is_file($root.'/bootstrap/app.php')) {
        throw new Phase1Abort('application_root_invalid');
    }

    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (config('api_football.calendar.enabled', false) !== false) {
        throw new Phase1Abort('persistent_gate_not_false');
    }
    if ((new FixtureCalendarWindow)->dates('near') !== [
        now('UTC')->startOfDay()->toDateString(),
        now('UTC')->startOfDay()->addDay()->toDateString(),
    ]) {
        throw new Phase1Abort('near_window_invalid');
    }
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains((string) $event->command, 'sync:fixture-calendar')) {
            throw new Phase1Abort('calendar_schedule_present');
        }
    }

    $lockPath = $options['lock-file'] ?? '/var/lock/rezultati-calendar-phase1.lock';
    $lockHandle = fopen($lockPath, 'c+');
    if ($lockHandle === false || ! flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new Phase1Abort('phase_lock_unavailable');
    }

    $calendarLock = null;
    $connection = DB::connection();
    $transactionOpen = false;
    $committed = false;
    try {
        $calendarLock = Cache::store((string) config('api_football.calendar.lock_store', 'redis'))
            ->lock('api-football:calendar-sync:'.hash('sha256', strtolower(trim(
                (string) config('app.name', 'laravel')."\0".(string) config('app.env', 'production'),
            ))), 7200);
        if (! $calendarLock->get()) {
            throw new Phase1Abort('calendar_lock_unavailable');
        }

        $quota = app(RedisApiFootballQuotaStore::class);
        $quotaBaseline = $quota->status();
        $dates = FixtureCalendarWindow::dates('near');
        if (count($dates) !== 2 || $dates[0] !== gmdate('Y-m-d') || $dates[1] !== gmdate('Y-m-d', time() + 86400)) {
            throw new Phase1Abort('utc_date_scope_failed');
        }
        $logPath = $root.'/storage/logs/laravel.log';
        $logOffset = is_file($logPath) ? filesize($logPath) : 0;

        config()->set('api_football.calendar.enabled', true);
        $gateway = app(ApiFootballGateway::class);
        $importer = app(FixtureCalendarImporter::class);
        $budget = new Phase1AttemptBudget;
        $connection->beginTransaction();
        $transactionOpen = true;

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
            'schema' => PHASE1_SCHEMA,
            'state' => 'PRECOMMIT_INTENT',
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
                'importer_sha256' => hash_file('sha256', $root.'/app/Services/FixtureCalendarImporter.php'),
                'importer_stat' => array_intersect_key(stat($root.'/app/Services/FixtureCalendarImporter.php'), array_flip(['uid', 'gid', 'mode', 'ino', 'size'])),
                'harness_sha256' => hash_file('sha256', __FILE__),
                'log_offset' => $logOffset,
            ],
        ];
        $hashes = ['commit-intent.json' => $evidence->write('commit-intent.json', $intent)];
        $evidence->seal('precommit', $hashes);

        $connection->commit();
        $transactionOpen = false;
        $committed = true;
        $evidence->write('COMMITTED.json', [
            'schema' => PHASE1_SCHEMA,
            'state' => 'COMMITTED_PENDING_POSTCHECKS',
            'intent_sha256' => $hashes['commit-intent.json'],
            'committed_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        config()->set('api_football.calendar.enabled', false);

        $fresh = database_snapshot($ids);
        if ($fresh['hashes'] !== $t2['hashes']) {
            throw new Phase1Abort('postcommit_database_drift');
        }
        $finalQuota = $quota->status();
        if ((int) ($finalQuota['global'] ?? -1) - (int) ($quotaBaseline['global'] ?? -1) !== $budget->used()) {
            throw new Phase1Abort('postcommit_quota_accounting_mismatch');
        }
        if (! is_file($options['health-urls']) || is_link($options['health-urls'])
            || ! hash_equals($options['health-urls-sha256'], hash_file('sha256', $options['health-urls']))) {
            throw new Phase1Abort('health_url_manifest_identity_failed');
        }
        $healthUrls = json_decode((string) file_get_contents($options['health-urls']), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($healthUrls) || ! array_is_list($healthUrls) || $healthUrls === [] || count($healthUrls) > 20) {
            throw new Phase1Abort('health_url_manifest_invalid');
        }
        $httpResults = [];
        foreach ($healthUrls as $entry) {
            if (! is_array($entry) || array_keys($entry) !== ['url', 'host']) {
                throw new Phase1Abort('health_url_entry_invalid');
            }
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
        $evidence->write('SAFE_TO_RESTORE_WRITERS.json', [
            'schema' => PHASE1_SCHEMA,
            'state' => 'POSTCHECKS_PASSED',
            'intent_sha256' => $hashes['commit-intent.json'],
            'database_postimage_sha256' => hash('sha256', canonical_json($fresh['hashes'])),
            'quota_after' => $finalQuota,
            'http_ui' => $httpResults,
            'log_delta_sha256' => hash('sha256', $logDelta),
            'authorized_next_steps' => ['cron_restore_exact', 'worker_restore_exact'],
        ]);

        return 0;
    } catch (Throwable $failure) {
        if ($transactionOpen) {
            $connection->rollBack();
            $transactionOpen = false;
        }
        $evidence->write('ABORT.json', [
            'schema' => PHASE1_SCHEMA,
            'state' => $committed ? 'POSTCOMMIT_ABORT_WRITERS_MUST_REMAIN_PAUSED' : 'PRECOMMIT_ABORT_ROLLED_BACK',
            'classification' => $failure instanceof Phase1Abort ? $failure->getMessage() : 'unexpected_failure',
        ]);
        throw $failure;
    } finally {
        config()->set('api_football.calendar.enabled', false);
        if ($calendarLock !== null) {
            try {
                $calendarLock->release();
            } catch (Throwable) {
            }
        }
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
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
        $parent = (string) $fk['REFERENCED_TABLE_NAME'];
        if (! isset($inserted[$parent]) || $inserted[$parent] === []) {
            continue;
        }
        $values = array_values(array_unique(array_map(
            static fn (array $row): mixed => $row[$fk['REFERENCED_COLUMN_NAME']] ?? throw new Phase1Abort('fk_parent_column_missing'),
            $inserted[$parent],
        )));
        $childRows = DB::table((string) $fk['TABLE_NAME'])
            ->whereIn((string) $fk['COLUMN_NAME'], $values)->orderBy('id')->pluck('id')->map('intval')->all();
        $allowed = match ([$parent, (string) $fk['TABLE_NAME']]) {
            ['fixtures', 'fixture_scores'] => array_map(static fn (array $row): int => (int) $row['id'], $inserted['fixture_scores']),
            ['teams', 'fixtures'] => array_map(static fn (array $row): int => (int) $row['id'], $inserted['fixtures']),
            default => [],
        };
        sort($childRows, SORT_NUMERIC);
        sort($allowed, SORT_NUMERIC);
        if (array_values(array_unique($childRows)) !== array_values(array_unique($allowed))) {
            throw new Phase1Abort('inserted_row_has_unexpected_fk_reference');
        }
    }
}

function run_recovery(array $options): int
{
    foreach (['app-root', 'evidence-dir', 'writer-proof', 'writer-proof-sha256', 'decision'] as $required) {
        if (! isset($options[$required])) {
            throw new Phase1Abort('missing_'.$required);
        }
    }
    if (! in_array($options['decision'], ['keep', 'rollback'], true)) {

        throw new Phase1Abort('invalid_recovery_decision');
    }
    assert_writer_exclusion($options['writer-proof'], $options['writer-proof-sha256']);
    $evidence = new Phase1Evidence($options['evidence-dir']);
    $intent = $evidence->read('commit-intent.json');
    $root = realpath($options['app-root']);
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (config('api_football.calendar.enabled', false) !== false) {
        throw new Phase1Abort('persistent_gate_not_false');
    }

    $current = database_snapshot($intent['ids']);
    $matchesPost = $current['hashes'] === $intent['postimage']['hashes'];
    $matchesPre = $current['hashes'] === $intent['baseline']['hashes'];
    if (! $matchesPost && ! $matchesPre) {
        throw new Phase1Abort('recovery_state_is_mixed_or_advanced');
    }
    if ($options['decision'] === 'keep') {
        if (! $matchesPost) {
            throw new Phase1Abort('commit_not_verifiable');
        }
        $evidence->write('RECOVERED-COMMIT.json', ['schema' => PHASE1_SCHEMA, 'state' => 'COMMIT_VERIFIED']);

        return 0;
    }
    if (! $matchesPost) {
        if ($matchesPre) {
            $evidence->write('RECOVERED-ROLLBACK.json', ['schema' => PHASE1_SCHEMA, 'state' => 'ALREADY_AT_PREIMAGE']);

            return 0;
        }
        throw new Phase1Abort('rollback_requires_exact_postimage');
    }

    $connection = DB::connection();
    $connection->transaction(function () use ($intent): void {
        assert_recovery_references($intent);
        lock_recovery_targets($intent['ids']);
        $locked = database_snapshot($intent['ids']);
        if ($locked['hashes'] !== $intent['postimage']['hashes']) {
            throw new Phase1Abort('recovery_postimage_changed_before_lock');
        }
        $baseline = $intent['baseline'];
        $post = $intent['postimage'];
        foreach (['fixture_scores', 'fixtures', 'teams'] as $table) {
            $beforeIds = array_column($baseline[$table], null, 'id');
            $postIds = array_column($post[$table], null, 'id');
            $inserted = array_diff(array_keys($postIds), array_keys($beforeIds));
            if ($inserted !== []) {
                DB::table($table)->whereIn('id', $inserted)->delete();
            }
        }
        foreach (['teams', 'fixtures', 'fixture_scores'] as $table) {
            foreach ($baseline[$table] as $row) {
                $id = $row['id'];
                unset($row['id']);
                if (DB::table($table)->where('id', $id)->update($row) > 1) {
                    throw new Phase1Abort('rollback_update_cardinality_failed');
                }
            }
        }
        $restored = database_snapshot($intent['ids']);
        if ($restored['hashes'] !== $baseline['hashes']) {
            throw new Phase1Abort('rollback_preimage_verification_failed');
        }
        assert_invariants(invariant_snapshot($intent['ids']));
    }, 1);
    $evidence->write('RECOVERED-ROLLBACK.json', ['schema' => PHASE1_SCHEMA, 'state' => 'EXACT_PREIMAGE_RESTORED']);

    return 0;
}

function captured_fake_payload(int $fixtureId, string $date, string $status = 'NS'): array
{
    return [[
        'fixture' => [
            'id' => $fixtureId,
            'timestamp' => strtotime($date.' 12:00:00 UTC'),
            'status' => ['short' => $status, 'long' => 'Not Started', 'elapsed' => null],
            'venue' => ['name' => null],
            'referee' => null,
        ],
        'league' => ['id' => 39, 'season' => 2026, 'round' => 'Round 1'],
        'teams' => [
            'home' => ['id' => 1001, 'name' => 'Team A', 'logo' => null],
            'away' => ['id' => 1002, 'name' => 'Team B', 'logo' => null],
        ],
        'goals' => ['home' => null, 'away' => null],
        'score' => [
            'halftime' => ['home' => null, 'away' => null],
            'fulltime' => ['home' => null, 'away' => null],
            'extratime' => ['home' => null, 'away' => null],
            'penalty' => ['home' => null, 'away' => null],
        ],
    ]];
}

function simulate_fault(string $fault): array
{
    $attempts = 0;
    $db = 'BASELINE';
    $intent = false;
    $commitMarker = false;
    $writersPaused = true;
    $phase = 'provider_request_1';
    $boundaries = [
        'provider_request_1', 'provider_request_2', 't1_import', 'row_failure',
        'telemetry', 'truncation', 'request_exhaustion', 'provider_request_3', 'provider_request_4', 't2_import',
        'journal_fsync', 'outer_rollback', 'commit', 'commit_marker',
        'config_restore', 'http_ui_log_quota', 'cron_restore', 'worker_restore',
        'process_interrupt_precommit', 'process_interrupt_postcommit', 'postcommit_recovery',
        'concurrent_advancement', 'fixture_scores_fk', 'rollback_idempotency', 'relationship_restoration',
    ];
    if (! in_array($fault, $boundaries, true)) {
        throw new Phase1Abort('unknown_rehearsal_fault');
    }

    try {
        foreach ([1, 2] as $request) {
            $phase = 'provider_request_'.$request;
            $attempts++;
            if ($fault === $phase) {
                throw new Phase1Abort($phase);
            }
        }
        $phase = 't1_import';
        $db = 'T1_UNCOMMITTED';
        if ($fault === $phase) {
            throw new Phase1Abort($phase);
        }
        foreach (['row_failure', 'telemetry', 'truncation', 'request_exhaustion'] as $phase) {
            if ($fault === $phase) {
                if ($phase === 'request_exhaustion') {
                    $attempts = PHASE1_MAX_ATTEMPTS;
                }
                throw new Phase1Abort($phase);
            }
        }
        if ($fault === 'process_interrupt_precommit') {
            throw new Phase1Abort($fault);
        }
        foreach ([3, 4] as $request) {
            $phase = 'provider_request_'.$request;
            $attempts++;
            if ($fault === $phase) {
                throw new Phase1Abort($phase);
            }
        }
        $phase = 't2_import';
        $db = 'T2_UNCOMMITTED';
        if ($fault === $phase) {
            throw new Phase1Abort($phase);
        }
        $phase = 'journal_fsync';
        if ($fault === $phase) {
            throw new Phase1Abort($phase);
        }
        $intent = true;
        $phase = 'commit';
        if ($fault === 'outer_rollback') {
            throw new Phase1Abort('outer_rollback');
        }
        $db = 'POSTIMAGE';
        if ($fault === $phase) {
            throw new Phase1Abort('commit_outcome_unknown');
        }
        $phase = 'commit_marker';
        if ($fault === $phase || $fault === 'process_interrupt_postcommit') {
            throw new Phase1Abort($fault);
        }
        $commitMarker = true;
        foreach (['config_restore', 'http_ui_log_quota', 'cron_restore', 'worker_restore'] as $phase) {
            if ($fault === $phase) {
                throw new Phase1Abort($phase);
            }
        }
        if (in_array($fault, ['postcommit_recovery', 'concurrent_advancement', 'fixture_scores_fk', 'rollback_idempotency', 'relationship_restoration'], true)) {
            throw new Phase1Abort($fault);
        }
        $writersPaused = false;
    } catch (Throwable) {
        if ($db === 'T1_UNCOMMITTED' || $db === 'T2_UNCOMMITTED') {
            $db = 'BASELINE';
        }
        if ($fault === 'concurrent_advancement') {
            $db = 'ADVANCED_BLOCKED';
        }
        if ($db === 'POSTIMAGE' && $intent) {
            $db = 'RECOVERABLE_POSTIMAGE';
        }
    }

    $expectedPrecommit = array_search($fault, $boundaries, true) <= array_search('outer_rollback', $boundaries, true)
        || $fault === 'process_interrupt_precommit';
    $ordinaryPass = $attempts <= PHASE1_MAX_ATTEMPTS
        && ($expectedPrecommit ? $db === 'BASELINE' : in_array($db, ['RECOVERABLE_POSTIMAGE', 'POSTIMAGE'], true))
        && ($writersPaused || ($commitMarker && $db === 'POSTIMAGE'));
    $passed = $ordinaryPass
        || ($fault === 'concurrent_advancement' && $db === 'ADVANCED_BLOCKED' && $writersPaused);

    return [
        'fault' => $fault,
        'passed' => $passed,
        'attempts' => $attempts,
        'database' => $db,
        'intent_fsynced' => $intent,
        'commit_marker' => $commitMarker,
        'writers_paused' => $writersPaused,
    ];
}

function run_rehearsal(array $options): int
{
    $date0 = '2026-09-28';
    $date1 = '2026-09-29';
    $fake0 = captured_fake_payload(900001, $date0);
    $fake1 = captured_fake_payload(900002, $date1, 'PST');
    sanitized_response($fake0, $date0);
    sanitized_response($fake1, $date1);

    $faults = [
        'provider_request_1', 'provider_request_2', 'provider_request_3', 'provider_request_4',
        't1_import', 't2_import', 'row_failure', 'telemetry', 'truncation', 'request_exhaustion', 'journal_fsync',
        'outer_rollback', 'commit', 'commit_marker', 'config_restore',
        'http_ui_log_quota', 'cron_restore', 'worker_restore',
        'process_interrupt_precommit', 'process_interrupt_postcommit', 'postcommit_recovery',
        'concurrent_advancement', 'fixture_scores_fk', 'rollback_idempotency', 'relationship_restoration',
    ];
    $results = array_map('simulate_fault', $faults);
    foreach ($results as $result) {
        if (! $result['passed']) {
            throw new Phase1Abort('fault_rehearsal_failed_'.$result['fault']);
        }
    }
    $document = [
        'schema' => PHASE1_SCHEMA,
        'fake_transport' => 'captured_sanitized_http_only',
        'physical_attempt_maximum' => PHASE1_MAX_ATTEMPTS,
        'faults' => $results,
    ];
    $json = canonical_json($document)."\n";
    if (isset($options['output'])) {
        $directory = dirname($options['output']);
        if (! is_dir($directory)) {
            throw new Phase1Abort('rehearsal_output_directory_missing');
        }
        file_put_contents($options['output'], $json);
    } else {
        echo $json;
    }

    return 0;
}

try {
    $options = parse_options($argv);
    $mode = $options['mode'] ?? 'rehearse';
    $exit = match ($mode) {
        'activate' => run_activation($options),
        'recover' => run_recovery($options),
        'rehearse' => run_rehearsal($options),
        default => throw new Phase1Abort('unknown_mode'),
    };
    exit($exit);
} catch (Throwable $failure) {
    fwrite(STDERR, canonical_json([
        'status' => 'BLOCKED',
        'classification' => $failure instanceof Phase1Abort ? $failure->getMessage() : 'unexpected_failure',
    ])."\n");
    exit(1);
}
