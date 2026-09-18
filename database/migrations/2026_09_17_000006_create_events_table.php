<?php

use Illuminate\Database\Migrations\Migration;
require_once __DIR__.'/support/CanonicalFootballMigration.php';

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        CanonicalFootballMigration::create('events', '2026_09_17_000006_create_events_table', <<<'SQL'
CREATE TABLE events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sport_id BIGINT UNSIGNED NOT NULL,
    competition_season_id BIGINT UNSIGNED NOT NULL,
    status_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    starts_at DATETIME NOT NULL COMMENT 'UTC by application contract',
    source_updated_at DATETIME NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    home_score INT NULL,
    away_score INT NULL,
    publishable TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY events_public_id_unique (public_id),
    KEY events_sport_starts_index (sport_id, starts_at),
    KEY events_season_starts_index (competition_season_id, starts_at),
    CONSTRAINT events_sport_id_foreign FOREIGN KEY (sport_id) REFERENCES sports (id),
    CONSTRAINT events_competition_season_id_foreign FOREIGN KEY (competition_season_id) REFERENCES competition_seasons (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000006_create_events_table'
SQL);
    }

    public function down(): void
    {
        CanonicalFootballMigration::drop('events', '2026_09_17_000006_create_events_table');
    }
};
