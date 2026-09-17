<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public bool $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS competition_seasons');
    }
};
