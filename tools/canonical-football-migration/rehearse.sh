#!/usr/bin/env bash
set -Eeuo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
OUT=${1:-"$ROOT/build/canonical-football-migration"}
COMPOSE=(docker compose -f "$ROOT/tools/a1b/compose.yaml")
TARGET_TABLES="'sports','providers','competitions','competition_seasons','participants','events','event_participants','provider_competition_mappings','provider_participant_mappings','provider_event_mappings','import_runs','identity_quarantines'"

mkdir -p "$OUT"
command -v docker >/dev/null || {
    echo "BLOCKED: Docker is required for exact MariaDB 10.11.13 execution" >&2
    exit 2
}

db_exec() {
    local database=$1
    shift
    "${COMPOSE[@]}" exec -T db mariadb -uroot "$database" "$@"
}

db_query() {
    local database=$1
    local sql=$2
    db_exec "$database" --batch --raw --skip-column-names -e "$sql"
}

run_artisan() {
    local database=$1
    shift
    "${COMPOSE[@]}" run --rm -T -e DB_DATABASE="$database" app sh -lc '
        set -eu
        rm -rf /tmp/canonical-migration
        cp -a /workspace /tmp/canonical-migration
        cp -a /build/vendor /tmp/canonical-migration/vendor
        cd /tmp/canonical-migration
        "$@"
    ' sh "$@"
}

capture_schema() {
    local database=$1
    local destination=$2
    db_exec "$database" --batch --raw --skip-column-names         < "$ROOT/tools/canonical-football-migration/capture-information-schema.sql"         > "$destination"
}

capture_legacy() {
    local database=$1
    local destination=$2
    db_exec "$database" --batch --raw --skip-column-names         < "$ROOT/tools/canonical-football-migration/capture-legacy.sql"         > "$destination"
}

expect_failure() {
    local label=$1
    local sql=$2
    local log=$3
    if db_exec canonical_migration -e "$sql" >> "$log" 2>&1; then
        echo "FAIL: expected rejection: $label" >&2
        return 1
    fi
    printf 'PASS expected rejection: %s\n' "$label" >> "$log"
}

assert_scalar() {
    local label=$1
    local expected=$2
    local sql=$3
    local actual
    actual=$(db_query canonical_migration "$sql")
    if [[ "$actual" != "$expected" ]]; then
        printf 'FAIL: %s expected=%s actual=%s\n' "$label" "$expected" "$actual" >&2
        return 1
    fi
    printf 'PASS %s=%s\n' "$label" "$actual"
}

exercise_constraints() {
    local log=$1
    db_exec canonical_migration <<'SQL' >> "$log" 2>&1
INSERT INTO sports (id, code, name, created_at, updated_at)
VALUES (1, 'football', 'Football', NOW(6), NOW(6));
INSERT INTO providers (
    id, sport_id, code, vendor_code, product_namespace, name, created_at, updated_at
) VALUES (
    1, 1, 'api_sports_football', 'api_sports', 'football_v3', 'API-Sports Football',
    NOW(6), NOW(6)
);
INSERT INTO competitions (
    id, public_id, sport_id, name, slug, created_at, updated_at
) VALUES (
    1, 'competition:test:1', 1, 'Test Competition', 'test-competition', NOW(6), NOW(6)
);
INSERT INTO competition_seasons (
    id, competition_id, source_key, label, created_at, updated_at
) VALUES (
    1, 1, '2026', '2026', NOW(6), NOW(6)
);
INSERT INTO participants (
    id, public_id, sport_id, type, display_name, slug, created_at, updated_at
) VALUES
    (1, 'participant:test:home', 1, 'team', 'Home', 'home', NOW(6), NOW(6)),
    (2, 'participant:test:away', 1, 'team', 'Away', 'away', NOW(6), NOW(6));
INSERT INTO events (
    id, public_id, sport_id, competition_season_id, status_code, starts_at,
    created_at, updated_at
) VALUES (
    1, 'event:test:1', 1, 1, 'scheduled', '2026-09-17 12:00:00', NOW(6), NOW(6)
);
INSERT INTO event_participants (
    event_id, participant_id, role, side_order, created_at, updated_at
) VALUES
    (1, 1, 'home', 1, NOW(6), NOW(6)),
    (1, 2, 'away', 2, NOW(6), NOW(6));
INSERT INTO provider_competition_mappings (
    provider_id, external_id, competition_id, legacy_league_id, first_seen_at, last_seen_at
) VALUES (
    1, '99001', 1, 9001, NOW(6), NOW(6)
);
INSERT INTO provider_participant_mappings (
    provider_id, external_id, participant_id, legacy_team_id, first_seen_at, last_seen_at
) VALUES
    (1, '99101', 1, 9101, NOW(6), NOW(6)),
    (1, '99102', 2, 9102, NOW(6), NOW(6));
INSERT INTO provider_event_mappings (
    provider_id, external_id, event_id, legacy_fixture_id, first_seen_at, last_seen_at
) VALUES (
    1, '99201', 1, 9201, NOW(6), NOW(6)
);
INSERT INTO import_runs (
    provider_id, sport_id, run_key, capability, status, started_at
) VALUES (
    1, 1, 'contract-run', 'legacy_header_backfill', 'waiting', NOW(6)
);
INSERT INTO identity_quarantines (
    provider_id, sport_id, entity_type, external_id, source_table, source_legacy_id,
    reason_code, first_seen_at, last_seen_at
) VALUES (
    1, 1, 'fixture', '99202', 'fixtures', 9202,
    'unknown_status', NOW(6), NOW(6)
);
SQL

    assert_scalar "canonical_table_count" "12"         "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ($TARGET_TABLES)"         >> "$log"
    assert_scalar "default_unpublished_events" "1"         "SELECT COUNT(*) FROM events WHERE id=1 AND publishable=0" >> "$log"
    assert_scalar "default_event_version" "1"         "SELECT COUNT(*) FROM events WHERE id=1 AND version=1" >> "$log"
    assert_scalar "default_open_quarantine" "1"         "SELECT COUNT(*) FROM identity_quarantines WHERE status='open' AND occurrences=1" >> "$log"

    expect_failure "sports code unique"         "INSERT INTO sports (code,name,created_at,updated_at) VALUES ('football','Duplicate',NOW(6),NOW(6))" "$log"
    expect_failure "provider sport foreign key"         "INSERT INTO providers (sport_id,code,vendor_code,product_namespace,name,created_at,updated_at) VALUES (999,'bad','bad','bad','Bad',NOW(6),NOW(6))" "$log"
    expect_failure "home role requires side 1"         "INSERT INTO event_participants (event_id,participant_id,role,side_order,created_at,updated_at) VALUES (1,2,'home',2,NOW(6),NOW(6))" "$log"
    expect_failure "away role requires side 2"         "INSERT INTO event_participants (event_id,participant_id,role,side_order,created_at,updated_at) VALUES (1,1,'away',1,NOW(6),NOW(6))" "$log"
    expect_failure "participant side domain"         "INSERT INTO event_participants (event_id,participant_id,role,side_order,created_at,updated_at) VALUES (1,1,'away',3,NOW(6),NOW(6))" "$log"
    expect_failure "import run status check"         "INSERT INTO import_runs (provider_id,sport_id,run_key,capability,status,started_at) VALUES (1,1,'bad-status','test','unknown',NOW(6))" "$log"
    expect_failure "provider event external identity unique"         "INSERT INTO provider_event_mappings (provider_id,external_id,event_id,first_seen_at,last_seen_at) VALUES (1,'99201',1,NOW(6),NOW(6))" "$log"
    expect_failure "provider event legacy identity unique"         "INSERT INTO provider_event_mappings (provider_id,external_id,event_id,legacy_fixture_id,first_seen_at,last_seen_at) VALUES (1,'other',1,9201,NOW(6),NOW(6))" "$log"
    expect_failure "one open quarantine unique"         "INSERT INTO identity_quarantines (provider_id,sport_id,entity_type,external_id,source_table,source_legacy_id,reason_code,first_seen_at,last_seen_at) VALUES (1,1,'fixture','other','fixtures',9202,'unknown_status',NOW(6),NOW(6))" "$log"
}

run_cycle() {
    local cycle=$1
    local cycle_out="$OUT/cycle-$cycle"
    mkdir -p "$cycle_out"

    "${COMPOSE[@]}" exec -T db mariadb -uroot -e         "DROP DATABASE IF EXISTS canonical_migration;
         DROP DATABASE IF EXISTS canonical_contract;
         CREATE DATABASE canonical_migration CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
         CREATE DATABASE canonical_contract CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

    db_exec canonical_migration < "$ROOT/database/schema/mariadb-schema.sql"
    db_exec canonical_migration < "$ROOT/tools/canonical-football-migration/legacy-fixtures.sql"
    capture_legacy canonical_migration "$cycle_out/legacy-before.txt"

    run_artisan canonical_migration php artisan migrate --force --no-interaction         > "$cycle_out/migrate-up.log" 2>&1
    run_artisan canonical_migration php tools/canonical-football-migration/show-create-fingerprints.php \
        > "$cycle_out/show-create-fingerprints.log" 2>&1
    db_exec canonical_contract < "$ROOT/tools/canonical-football-migration/reviewed-schema.sql"

    capture_schema canonical_migration "$cycle_out/actual-schema.txt"
    capture_schema canonical_contract "$cycle_out/reviewed-schema.txt"
    diff -u "$cycle_out/reviewed-schema.txt" "$cycle_out/actual-schema.txt"         > "$cycle_out/schema-parity.diff"

    exercise_constraints "$cycle_out/constraints.log"

    capture_legacy canonical_migration "$cycle_out/legacy-after-up.txt"
    diff -u "$cycle_out/legacy-before.txt" "$cycle_out/legacy-after-up.txt"         > "$cycle_out/legacy-after-up.diff"

    run_artisan canonical_migration php artisan migrate:rollback --force --no-interaction         > "$cycle_out/migrate-down.log" 2>&1

    assert_scalar "canonical_tables_after_rollback" "0"         "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ($TARGET_TABLES)"         >> "$cycle_out/rollback.log"
    assert_scalar "legacy_tables_after_rollback" "4"         "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('leagues','teams','fixtures','fixture_scores')"         >> "$cycle_out/rollback.log"
    assert_scalar "canonical_migration_ledger_rows_after_rollback" "0"         "SELECT COUNT(*) FROM migrations WHERE migration LIKE '2026_09_17_0000%'"         >> "$cycle_out/rollback.log"

    capture_legacy canonical_migration "$cycle_out/legacy-after-rollback.txt"
    diff -u "$cycle_out/legacy-before.txt" "$cycle_out/legacy-after-rollback.txt"         > "$cycle_out/legacy-after-rollback.diff"

    printf 'cycle=%s schema_parity=pass constraints=pass legacy_unchanged=pass rollback=pass\n'         "$cycle" > "$cycle_out/summary.txt"
}

version=$("${COMPOSE[@]}" exec -T db mariadb -uroot --skip-column-names -e 'SELECT VERSION()')
[[ "$version" == 10.11.13-MariaDB* ]] || {
    echo "BLOCKED: expected exact MariaDB 10.11.13, got $version" >&2
    exit 2
}

run_cycle 1
run_cycle 2
printf 'MariaDB=%s\ncycles=2\nresult=PASS\n' "$version" > "$OUT/summary.txt"
echo "PASS: canonical football migration contract on exact MariaDB 10.11.13"
