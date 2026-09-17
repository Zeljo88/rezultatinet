<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE identity_quarantines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id BIGINT UNSIGNED NOT NULL,
    sport_id BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_table VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_legacy_id BIGINT UNSIGNED NOT NULL,
    reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    safe_detail VARCHAR(255) NULL,
    payload_hash BINARY(32) NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    occurrences BIGINT UNSIGNED NOT NULL DEFAULT 1,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open',
    open_dedupe_key BINARY(32) GENERATED ALWAYS AS (
        CASE WHEN status = 'open' THEN UNHEX(SHA2(CONCAT_WS('|', provider_id, sport_id, entity_type, source_table, source_legacy_id, reason_code), 256)) ELSE NULL END
    ) PERSISTENT,
    PRIMARY KEY (id),
    UNIQUE KEY identity_quarantines_one_open_unique (open_dedupe_key),
    KEY identity_quarantines_lookup_index (provider_id, sport_id, entity_type, external_id, status),
    CONSTRAINT identity_quarantines_provider_id_foreign FOREIGN KEY (provider_id) REFERENCES providers (id),
    CONSTRAINT identity_quarantines_sport_id_foreign FOREIGN KEY (sport_id) REFERENCES sports (id),
    CONSTRAINT identity_quarantines_status_check CHECK (status IN ('open', 'resolved', 'ignored'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS identity_quarantines');
    }
};
