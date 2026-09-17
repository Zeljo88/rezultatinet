<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/CanonicalFootballMigration.php';

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        CanonicalFootballMigration::create('provider_competition_mappings', '2026_09_17_000008_create_provider_competition_mappings_table', <<<'SQL'
CREATE TABLE provider_competition_mappings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id BIGINT UNSIGNED NOT NULL,
    external_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    competition_id BIGINT UNSIGNED NOT NULL,
    legacy_league_id BIGINT UNSIGNED NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    source_updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY provider_competition_external_unique (provider_id, external_id),
    UNIQUE KEY provider_competition_legacy_unique (legacy_league_id),
    KEY provider_competition_canonical_index (competition_id),
    CONSTRAINT provider_competition_provider_id_foreign FOREIGN KEY (provider_id) REFERENCES providers (id),
    CONSTRAINT provider_competition_competition_id_foreign FOREIGN KEY (competition_id) REFERENCES competitions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000008_create_provider_competition_mappings_table'
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS provider_competition_mappings');
    }
};
