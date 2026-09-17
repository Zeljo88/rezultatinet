<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CanonicalFootballMigrationContractTest extends TestCase
{
    private const TABLES = [
        'sports',
        'providers',
        'competitions',
        'competition_seasons',
        'participants',
        'events',
        'event_participants',
        'provider_competition_mappings',
        'provider_participant_mappings',
        'provider_event_mappings',
        'import_runs',
        'identity_quarantines',
    ];

    public function test_migrations_are_one_table_per_restart_safe_dependency_step(): void
    {
        $files = $this->migrationFiles();

        $this->assertCount(12, $files);
        $this->assertSame(self::TABLES, array_map(
            fn (string $file): string => $this->createdTable(file_get_contents($file)),
            $files,
        ));

        foreach ($files as $position => $file) {
            $contents = file_get_contents($file);
            $table = self::TABLES[$position];

            $this->assertStringContainsString('public bool $withinTransaction = false;', $contents);
            $this->assertSame(1, substr_count($contents, 'CREATE TABLE '));
            $this->assertSame(1, substr_count($contents, "DROP TABLE IF EXISTS $table"));
        }
    }

    public function test_migration_ddl_is_byte_equivalent_to_the_reviewed_table_contract(): void
    {
        $reviewed = file_get_contents($this->root().'/tools/canonical-football-migration/reviewed-schema.sql');
        $this->assertSame(
            'cf42a7010b762886e548021b7c4be2e4e580487167014e13d7e55bedb28353b7',
            hash('sha256', $reviewed),
        );

        preg_match_all(
            '/CREATE TABLE ([a-z_]+) \(.*?\n\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/s',
            $reviewed,
            $matches,
        );

        $this->assertSame(self::TABLES, $matches[1]);

        $reviewedByTable = [];
        foreach ($matches[1] as $index => $table) {
            $reviewedByTable[$table] = rtrim($matches[0][$index], ";\r\n\t ");
        }

        foreach ($this->migrationFiles() as $file) {
            $contents = file_get_contents($file);
            preg_match("/DB::unprepared\(<<<'SQL'\n(.*?)\nSQL\);/s", $contents, $match);
            $table = $this->createdTable($contents);

            $this->assertArrayHasKey($table, $reviewedByTable);
            $this->assertSame($reviewedByTable[$table], $match[1]);
        }
    }

    public function test_migrations_cannot_mutate_legacy_or_application_data(): void
    {
        $all = implode("\n", array_map('file_get_contents', $this->migrationFiles()));

        $this->assertDoesNotMatchRegularExpression(
            '/(?:^|\n)\s*(?:ALTER|INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\s/mi',
            $all,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\b(?:leagues|teams|fixtures|fixture_scores|player_stats)\b/i',
            $all,
        );

        preg_match_all('/DROP TABLE IF EXISTS ([a-z_]+)/', $all, $drops);
        $this->assertSame(self::TABLES, $drops[1]);
    }

    public function test_publication_and_home_away_invariants_are_frozen(): void
    {
        $all = implode("\n", array_map('file_get_contents', $this->migrationFiles()));

        foreach ([
            'publishable TINYINT(1) NOT NULL DEFAULT 0',
            'version BIGINT UNSIGNED NOT NULL DEFAULT 1',
            'UNIQUE KEY event_participants_identity_unique (event_id, participant_id)',
            'UNIQUE KEY event_participants_role_unique (event_id, role)',
            'CONSTRAINT event_participants_side_check CHECK (side_order IN (1, 2))',
            "(role = 'home' AND side_order = 1)",
            "(role = 'away' AND side_order = 2)",
            'legacy_league_id BIGINT UNSIGNED NULL',
            'legacy_team_id BIGINT UNSIGNED NULL',
            'legacy_fixture_id BIGINT UNSIGNED NULL',
            "status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open'",
            'open_dedupe_key BINARY(32) GENERATED ALWAYS AS',
        ] as $required) {
            $this->assertStringContainsString($required, $all);
        }
    }

    public function test_baseline_and_intentional_historical_migration_are_unchanged(): void
    {
        $this->assertSame(
            '6e5388303d7fee19711ea6519b8ec28bd7663ed0f9aa09de1ac8b5fb22ddba5b',
            hash_file('sha256', $this->root().'/database/schema/mariadb-schema.sql'),
        );
        $this->assertSame(
            '4337f0d6b158fb6e6ab4a97615908052ae393184db2d2f7a79294f5e7e77e415',
            hash_file(
                'sha256',
                $this->root().'/database/migrations/2026_03_19_084743_fix_player_stats_unique_index.php',
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        $files = glob($this->root().'/database/migrations/2026_09_17_0000*_create_*_table.php');
        sort($files);

        return array_values($files);
    }

    private function createdTable(string $contents): string
    {
        preg_match('/CREATE TABLE ([a-z_]+) \(/', $contents, $match);
        $this->assertNotEmpty($match);

        return $match[1];
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
