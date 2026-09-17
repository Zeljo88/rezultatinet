<?php

use Illuminate\Support\Facades\DB;

final class CanonicalFootballMigration
{
    /**
     * @var array<string, array{migration: string, marker: string, show_create_sha256: string}>
     */
    private const CONTRACTS = [
        'sports' => [
            'migration' => '2026_09_17_000001_create_sports_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000001_create_sports_table',
            'show_create_sha256' => 'DISCOVER',
        ],
        'providers' => [
            'migration' => '2026_09_17_000002_create_providers_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000002_create_providers_table',
            'show_create_sha256' => 'DISCOVER',
        ],
        'competitions' => [
            'migration' => '2026_09_17_000003_create_competitions_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000003_create_competitions_table',
            'show_create_sha256' => 'DISCOVER',
        ],
        'competition_seasons' => [
            'migration' => '2026_09_17_000004_create_competition_seasons_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000004_create_competition_seasons_table',
            'show_create_sha256' => 'DISCOVER',
        ],
        'participants' => [
            'migration' => '2026_09_17_000005_create_participants_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000005_create_participants_table',
            'show_create_sha256' => 'DISCOVER',
        ],
        'events' => [
            'migration' => '2026_09_17_000006_create_events_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000006_create_events_table',
            'show_create_sha256' => 'DISCOVER',
        ],
        'event_participants' => [
            'migration' => '2026_09_17_000007_create_event_participants_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000007_create_event_participants_table',
            'show_create_sha256' => 'DISCOVER',
        ],
        'provider_competition_mappings' => [
            'migration' => '2026_09_17_000008_create_provider_competition_mappings_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000008_create_provider_competition_mappings_table',
            'show_create_sha256' => 'DISCOVER',
        ],
        'provider_participant_mappings' => [
            'migration' => '2026_09_17_000009_create_provider_participant_mappings_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000009_create_provider_participant_mappings_table',
            'show_create_sha256' => 'DISCOVER',
        ],
        'provider_event_mappings' => [
            'migration' => '2026_09_17_000010_create_provider_event_mappings_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000010_create_provider_event_mappings_table',
            'show_create_sha256' => 'DISCOVER',
        ],
        'import_runs' => [
            'migration' => '2026_09_17_000011_create_import_runs_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000011_create_import_runs_table',
            'show_create_sha256' => 'DISCOVER',
        ],
        'identity_quarantines' => [
            'migration' => '2026_09_17_000012_create_identity_quarantines_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000012_create_identity_quarantines_table',
            'show_create_sha256' => 'DISCOVER',
        ],
    ];

    public static function create(string $table, string $migration, string $createSql): void
    {
        $contract = self::contract($table, $migration);

        if (! self::exists($table)) {
            DB::unprepared($createSql);

            return;
        }

        self::assertSafeToAdopt($table, $contract);
    }

    /**
     * @param  array{migration: string, marker: string, show_create_sha256: string}  $contract
     */
    private static function assertSafeToAdopt(string $table, array $contract): void
    {
        $metadata = DB::selectOne(
            <<<'SQL'
SELECT table_type, engine, table_comment
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = ?
SQL,
            [$table],
        );

        if ($metadata === null
            || $metadata->table_type !== 'BASE TABLE'
            || $metadata->engine !== 'InnoDB'
            || $metadata->table_comment !== $contract['marker']) {
            throw self::unsafe($table, 'table type, engine, or canonical migration marker does not match');
        }

        $rowCount = (int) DB::scalar(sprintf('SELECT COUNT(*) FROM `%s`', $table));
        if ($rowCount !== 0) {
            throw self::unsafe($table, "table is not empty (rows=$rowCount)");
        }

        $inboundCount = (int) DB::scalar(
            <<<'SQL'
SELECT COUNT(*)
FROM information_schema.key_column_usage
WHERE referenced_table_schema = DATABASE()
  AND referenced_table_name = ?
  AND NOT (table_schema = DATABASE() AND table_name = ?)
SQL,
            [$table, $table],
        );
        if ($inboundCount !== 0) {
            throw self::unsafe($table, "table has inbound foreign-key dependents (references=$inboundCount)");
        }

        $showCreate = DB::select(sprintf('SHOW CREATE TABLE `%s`', $table));
        $values = isset($showCreate[0]) ? array_values((array) $showCreate[0]) : [];
        $actualHash = isset($values[1]) ? hash('sha256', $values[1]) : '';

        if ($contract['show_create_sha256'] === 'DISCOVER') {
            throw self::unsafe($table, "schema fingerprint is not pinned (actual=$actualHash)");
        }

        if (! hash_equals($contract['show_create_sha256'], $actualHash)) {
            throw self::unsafe($table, "schema fingerprint does not match (actual=$actualHash)");
        }
    }

    private static function exists(string $table): bool
    {
        return (int) DB::scalar(
            <<<'SQL'
SELECT COUNT(*)
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = ?
SQL,
            [$table],
        ) === 1;
    }

    /**
     * @return array{migration: string, marker: string, show_create_sha256: string}
     */
    private static function contract(string $table, string $migration): array
    {
        $contract = self::CONTRACTS[$table] ?? null;

        if ($contract === null || $contract['migration'] !== $migration) {
            throw new RuntimeException("Unknown canonical football migration contract: $migration/$table");
        }

        return $contract;
    }

    private static function unsafe(string $table, string $reason): RuntimeException
    {
        return new RuntimeException(
            "Refusing restart recovery for canonical table `$table`: $reason. "
            .'Leave the table and migration ledger unchanged; stop and escalate for independent inspection.',
        );
    }
}
