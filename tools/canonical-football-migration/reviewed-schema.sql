CREATE TABLE sports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(100) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY sports_code_unique (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000001_create_sports_table';

-- A provider row is an immutable product/feed namespace, not an umbrella vendor account.
CREATE TABLE providers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sport_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    vendor_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_namespace VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(100) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY providers_code_unique (code),
    UNIQUE KEY providers_sport_product_unique (sport_id, product_namespace),
    CONSTRAINT providers_sport_id_foreign FOREIGN KEY (sport_id) REFERENCES sports (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000002_create_providers_table';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000003_create_competitions_table';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000004_create_competition_seasons_table';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000005_create_participants_table';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000006_create_events_table';

CREATE TABLE event_participants (
    event_id BIGINT UNSIGNED NOT NULL,
    participant_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    side_order TINYINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_id, side_order),
    UNIQUE KEY event_participants_identity_unique (event_id, participant_id),
    UNIQUE KEY event_participants_role_unique (event_id, role),
    KEY event_participants_participant_index (participant_id),
    CONSTRAINT event_participants_event_id_foreign FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT event_participants_participant_id_foreign FOREIGN KEY (participant_id) REFERENCES participants (id),
    CONSTRAINT event_participants_side_check CHECK (side_order IN (1, 2)),
    CONSTRAINT event_participants_role_side_check CHECK (
        (role = 'home' AND side_order = 1) OR
        (role = 'away' AND side_order = 2)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000007_create_event_participants_table';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000008_create_provider_competition_mappings_table';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000009_create_provider_participant_mappings_table';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000010_create_provider_event_mappings_table';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000011_create_import_runs_table';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='rezultati.net canonical-football v1 2026_09_17_000012_create_identity_quarantines_table';
