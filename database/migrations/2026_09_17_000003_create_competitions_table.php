<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/CanonicalFootballMigration.php';

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        CanonicalFootballMigration::create('competitions', '2026_09_17_000003_create_competitions_table', <<<'SQL'
CREATE TABLE competitions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sport_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    country_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NULL,
    image_url VARCHAR(255) NULL COMMENT 'canonical-owned competition media URL',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY competitions_public_id_unique (public_id),
    UNIQUE KEY competitions_sport_slug_unique (sport_id, slug),
    CONSTRAINT competitions_sport_id_foreign FOREIGN KEY (sport_id) REFERENCES sports (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000003_create_competitions_table'
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS competitions');
    }
};
