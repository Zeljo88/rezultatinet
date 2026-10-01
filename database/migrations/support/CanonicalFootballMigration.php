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
            'show_create_sha256' => '0701f9b01b4d67eb2ec8954c0837ebbeed4d2359fa46118756a8a0656958c337',
        ],
        'providers' => [
            'migration' => '2026_09_17_000002_create_providers_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000002_create_providers_table',
            'show_create_sha256' => '27674bed40a17eb032f45d87506a4bb9fa8b1624d046e0c13d0a99cda0e1f47c',
        ],
        'competitions' => [
            'migration' => '2026_09_17_000003_create_competitions_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000003_create_competitions_table',
            'show_create_sha256' => '04ad97842b08824de552426e5c166fe9bf309268109e4ee4e0afc0cd523880aa',
        ],
        'competition_seasons' => [
            'migration' => '2026_09_17_000004_create_competition_seasons_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000004_create_competition_seasons_table',
            'show_create_sha256' => '280249987bf1f8b1a7d735162eb9fdd6c249876db9eafbac2f62d6f79b596bbd',
        ],
        'participants' => [
            'migration' => '2026_09_17_000005_create_participants_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000005_create_participants_table',
            'show_create_sha256' => '432cba6eec05627aa47b762b7af82bfe5fc728727e76cb18d88ebb4fe3e41d76',
        ],
        'events' => [
            'migration' => '2026_09_17_000006_create_events_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000006_create_events_table',
            'show_create_sha256' => '82d4c02650f01b5bf6ab7c68f7f802c6664d5b0ca25f5c26d96cd8655146d933',
        ],
        'event_participants' => [
            'migration' => '2026_09_17_000007_create_event_participants_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000007_create_event_participants_table',
            'show_create_sha256' => '93697e8fbf695571a2b7d66477d214a98b3474b21090ad4c06b0d4ceb615213d',
        ],
        'provider_competition_mappings' => [
            'migration' => '2026_09_17_000008_create_provider_competition_mappings_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000008_create_provider_competition_mappings_table',
            'show_create_sha256' => '8254107fa0464c50fd773b0f87a5065a93e9d04c6ea8f5ff19a52b30de47216d',
        ],
        'provider_participant_mappings' => [
            'migration' => '2026_09_17_000009_create_provider_participant_mappings_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000009_create_provider_participant_mappings_table',
            'show_create_sha256' => '993e941be75b47c6d290bc9162302c29252bcc1cde9d4345743912baed036cd0',
        ],
        'provider_event_mappings' => [
            'migration' => '2026_09_17_000010_create_provider_event_mappings_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000010_create_provider_event_mappings_table',
            'show_create_sha256' => '4a9d3137387714fea9ae9d5de3410c787fb872a2233dfbec719ad2144359743b',
        ],
        'import_runs' => [
            'migration' => '2026_09_17_000011_create_import_runs_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000011_create_import_runs_table',
            'show_create_sha256' => 'a2b26fc96c0a3bf12e3f956c6f42c88939f5ee6a2445d2c0942f856af3b20221',
        ],
        'identity_quarantines' => [
            'migration' => '2026_09_17_000012_create_identity_quarantines_table',
            'marker' => 'rezultati.net canonical-football v1 2026_09_17_000012_create_identity_quarantines_table',
            'show_create_sha256' => 'ed530e2c9b15c9f553f26bd0d800ac99099e03c9aa107cac7b4a5794eb8e17f9',
        ],
    ];

    public static function create(string $table, string $migration, string $createSql): void
    {
        $contract = self::contract($table, $migration);

        if (! self::exists($table)) {
            DB::unprepared($createSql);
        }

        self::assertExactEmptyTable($table, $contract, 'restart recovery');
    }

    public static function drop(string $table, string $migration): void
    {
        $contract = self::contract($table, $migration);

        if (! self::exists($table)) {
            throw self::unsafe($table, 'rollback', 'table is unexpectedly missing');
        }

        self::assertExactEmptyTable($table, $contract, 'rollback');

        DB::statement(sprintf('DROP TABLE `%s`', $table));
    }

    /**
     * @param  array{migration: string, marker: string, show_create_sha256: string}  $contract
     */
    private static function assertExactEmptyTable(string $table, array $contract, string $operation): void
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
            throw self::unsafe($table, $operation, 'table type, engine, or canonical migration marker does not match');
        }

        $rowCount = (int) DB::scalar(sprintf('SELECT COUNT(*) FROM `%s`', $table));
        if ($rowCount !== 0) {
            throw self::unsafe($table, $operation, "table is not empty (rows=$rowCount)");
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
            throw self::unsafe($table, $operation, "table has inbound foreign-key dependents (references=$inboundCount)");
        }

        $showCreate = DB::select(sprintf('SHOW CREATE TABLE `%s`', $table));
        $values = isset($showCreate[0]) ? array_values((array) $showCreate[0]) : [];
        $actualHash = isset($values[1]) ? hash('sha256', $values[1]) : '';

        if (! hash_equals($contract['show_create_sha256'], $actualHash)) {
            throw self::unsafe($table, $operation, "schema fingerprint does not match (actual=$actualHash)");
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

    private static function unsafe(string $table, string $operation, string $reason): RuntimeException
    {
        return new RuntimeException(
            "Refusing $operation for canonical table `$table`: $reason. "
            .'Leave the table and migration ledger unchanged; stop and escalate for independent inspection.',
        );
    }
}
