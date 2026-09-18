<?php

use Illuminate\Database\Migrations\Migration;
require_once __DIR__.'/support/CanonicalFootballMigration.php';

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        CanonicalFootballMigration::create('competition_seasons', '2026_09_17_000004_create_competition_seasons_table', <<<'SQL'
CREATE TABLE competition_seasons (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    competition_id BIGINT UNSIGNED NOT NULL,
    source_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'immutable source season identity',
    label VARCHAR(100) NOT NULL,
    starts_on DATE NULL,
    ends_on DATE NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY competition_seasons_source_unique (competition_id, source_key),
    CONSTRAINT competition_seasons_competition_id_foreign FOREIGN KEY (competition_id) REFERENCES competitions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000004_create_competition_seasons_table'
SQL);
    }

    public function down(): void
    {
        CanonicalFootballMigration::drop('competition_seasons', '2026_09_17_000004_create_competition_seasons_table');
    }
};
