<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public bool $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE provider_event_mappings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id BIGINT UNSIGNED NOT NULL,
    external_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    legacy_fixture_id BIGINT UNSIGNED NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    source_updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY provider_event_external_unique (provider_id, external_id),
    UNIQUE KEY provider_event_legacy_unique (legacy_fixture_id),
    KEY provider_event_canonical_index (event_id),
    CONSTRAINT provider_event_provider_id_foreign FOREIGN KEY (provider_id) REFERENCES providers (id),
    CONSTRAINT provider_event_event_id_foreign FOREIGN KEY (event_id) REFERENCES events (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS provider_event_mappings');
    }
};
