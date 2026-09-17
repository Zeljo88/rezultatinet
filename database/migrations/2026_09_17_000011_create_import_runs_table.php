<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE import_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id BIGINT UNSIGNED NOT NULL,
    sport_id BIGINT UNSIGNED NOT NULL,
    run_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    capability VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_legacy_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_rows_seen BIGINT UNSIGNED NOT NULL DEFAULT 0,
    applied_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    unresolved_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    error_summary VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY import_runs_run_key_unique (run_key),
    KEY import_runs_scope_index (sport_id, capability, started_at),
    CONSTRAINT import_runs_provider_id_foreign FOREIGN KEY (provider_id) REFERENCES providers (id),
    CONSTRAINT import_runs_sport_id_foreign FOREIGN KEY (sport_id) REFERENCES sports (id),
    CONSTRAINT import_runs_status_check CHECK (status IN ('waiting', 'running', 'paused', 'completed', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS import_runs');
    }
};
