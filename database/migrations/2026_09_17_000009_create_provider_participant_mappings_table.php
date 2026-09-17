<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/CanonicalFootballMigration.php';

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        CanonicalFootballMigration::create('provider_participant_mappings', '2026_09_17_000009_create_provider_participant_mappings_table', <<<'SQL'
CREATE TABLE provider_participant_mappings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id BIGINT UNSIGNED NOT NULL,
    external_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    participant_id BIGINT UNSIGNED NOT NULL,
    legacy_team_id BIGINT UNSIGNED NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    source_updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY provider_participant_external_unique (provider_id, external_id),
    UNIQUE KEY provider_participant_legacy_unique (legacy_team_id),
    KEY provider_participant_canonical_index (participant_id),
    CONSTRAINT provider_participant_provider_id_foreign FOREIGN KEY (provider_id) REFERENCES providers (id),
    CONSTRAINT provider_participant_participant_id_foreign FOREIGN KEY (participant_id) REFERENCES participants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000009_create_provider_participant_mappings_table'
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS provider_participant_mappings');
    }
};
