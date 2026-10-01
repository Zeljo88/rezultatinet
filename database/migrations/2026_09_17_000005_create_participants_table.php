<?php

use Illuminate\Database\Migrations\Migration;
require_once __DIR__.'/support/CanonicalFootballMigration.php';

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        CanonicalFootballMigration::create('participants', '2026_09_17_000005_create_participants_table', <<<'SQL'
CREATE TABLE participants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sport_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    short_name VARCHAR(10) NULL,
    country_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NULL,
    image_url VARCHAR(255) NULL COMMENT 'canonical-owned participant media URL',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY participants_public_id_unique (public_id),
    UNIQUE KEY participants_sport_slug_unique (sport_id, slug),
    KEY participants_sport_type_index (sport_id, type),
    CONSTRAINT participants_sport_id_foreign FOREIGN KEY (sport_id) REFERENCES sports (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000005_create_participants_table'
SQL);
    }

    public function down(): void
    {
        CanonicalFootballMigration::drop('participants', '2026_09_17_000005_create_participants_table');
    }
};
