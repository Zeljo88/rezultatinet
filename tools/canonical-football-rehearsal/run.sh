#!/usr/bin/env bash
set -Eeuo pipefail

ARTIFACT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EVIDENCE_DIR="${EVIDENCE_DIR:-${ARTIFACT_DIR}/../../reports/evidence/canonical-football}"
COMPOSE=(docker compose -f "${ARTIFACT_DIR}/docker-compose.yml" --project-name canonical-football-rehearsal)
DB_NAME="canonical_football_rehearsal"
CURRENT_CYCLE="bootstrap"

mkdir -p "${EVIDENCE_DIR}"

cleanup() {
    local exit_code=$?
    "${COMPOSE[@]}" logs --no-color >"${EVIDENCE_DIR}/${CURRENT_CYCLE}-container.log" 2>&1 || true
    "${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
    exit "${exit_code}"
}
trap cleanup EXIT

if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
    printf '%s\n' 'Docker Compose is required; no database or remote fallback is permitted.' >&2
    exit 2
fi

sql_file() {
    local file=$1
    "${COMPOSE[@]}" exec -T mariadb mariadb --protocol=socket -uroot "${DB_NAME}" <"${ARTIFACT_DIR}/${file}"
}

sql_call() {
    local statement=$1
    "${COMPOSE[@]}" exec -T mariadb mariadb --protocol=socket -uroot "${DB_NAME}" --execute "${statement}"
}

sql_rows() {
    local file=$1
    "${COMPOSE[@]}" exec -T mariadb mariadb --batch --raw --skip-column-names --protocol=socket -uroot "${DB_NAME}" <"${ARTIFACT_DIR}/${file}"
}

capture_legacy_fingerprints() {
    local destination=$1
    local fingerprint_dir table row_count digest
    fingerprint_dir=$(mktemp -d)
    sql_rows legacy-checksums.sql >"${fingerprint_dir}/all.tsv"
    printf 'table_name\trow_count\tordered_row_sha256\n' >"${destination}"
    for table in fixture_scores fixtures leagues teams; do
        awk -F '\t' -v expected="${table}" '$1 == expected { print $2 "\t" $3 }' \
            "${fingerprint_dir}/all.tsv" >"${fingerprint_dir}/${table}.rows"
        row_count=$(wc -l <"${fingerprint_dir}/${table}.rows")
        digest=$(sha256sum "${fingerprint_dir}/${table}.rows" | awk '{ print $1 }')
        printf '%s\t%s\t%s\n' "${table}" "${row_count}" "${digest}" >>"${destination}"
    done
    rm -rf "${fingerprint_dir}"
}

expect_backfill_failure() {
    local cycle=$1
    local run_key=$2
    local expected_message=$3
    local status
    set +e
    sql_call "CALL canonical_football_backfill('${run_key}', 18446744073709551615);" \
        >"${EVIDENCE_DIR}/${cycle}-${run_key}-stdout.txt" \
        2>"${EVIDENCE_DIR}/${cycle}-${run_key}-stderr.txt"
    status=$?
    set -e
    test "${status}" -ne 0
    grep -F "${expected_message}" "${EVIDENCE_DIR}/${cycle}-${run_key}-stderr.txt" >/dev/null
}

run_operational_rehearsal() {
    local cycle=$1
    local holder_pid attempt held started ended elapsed_ms

    sql_call "SELECT GET_LOCK('canonical_football:legacy_header_backfill', 0) AS acquired; SELECT SLEEP(5) AS held; SELECT RELEASE_LOCK('canonical_football:legacy_header_backfill') AS released;" \
        >"${EVIDENCE_DIR}/${cycle}-lock-holder.tsv" &
    holder_pid=$!
    held=0
    for ((attempt=1; attempt<=30; attempt++)); do
        held=$(sql_call "SELECT IS_USED_LOCK('canonical_football:legacy_header_backfill') IS NOT NULL AS held;" | tail -n 1)
        if [[ "${held}" == "1" ]]; then
            break
        fi
        sleep 0.1
    done
    test "${held}" = "1"

    started=$(date +%s%N)
    expect_backfill_failure "${cycle}" overlap-run 'single-flight lock timeout after 2 seconds'
    ended=$(date +%s%N)
    elapsed_ms=$(( (ended - started) / 1000000 ))
    test "${elapsed_ms}" -ge 1800
    test "${elapsed_ms}" -le 4000
    printf 'test\tbounded_timeout_ms\ndistinct_run_overlap\t%s\n' "${elapsed_ms}" \
        >"${EVIDENCE_DIR}/${cycle}-lock-contention.tsv"
    wait "${holder_pid}"
    sql_call "CALL canonical_football_backfill('overlap-retry', 18446744073709551615);"

    sql_call "UPDATE provider_competition_mappings SET external_id='harness-conflict' WHERE legacy_league_id=1;"
    expect_backfill_failure "${cycle}" injected-failure-run 'competition mapping conflict'
    sql_call "UPDATE provider_competition_mappings SET external_id='1001' WHERE legacy_league_id=1;"
    sql_call "CALL canonical_football_backfill('injected-failure-retry', 18446744073709551615);"

    sql_call "INSERT INTO import_runs (provider_id, sport_id, run_key, capability, status, started_at) SELECT p.id, s.id, 'synthetic-stale-run', 'legacy_header_backfill', 'running', UTC_TIMESTAMP(6) FROM providers p JOIN sports s ON s.id=p.sport_id WHERE p.code='api_sports_football';"
    sql_call "CALL canonical_football_backfill('stale-recovery-run', 18446744073709551615);"
    sql_file operational-assertions.sql >"${EVIDENCE_DIR}/${cycle}-operational-assertions.tsv"
}


start_database() {
    local attempt database_info
    "${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
    "${COMPOSE[@]}" up --detach --wait mariadb
    for ((attempt=1; attempt<=30; attempt++)); do
        if database_info=$("${COMPOSE[@]}" exec -T mariadb mariadb --protocol=socket -uroot --execute \
            "SELECT VERSION() AS mariadb_version, DATABASE() AS current_database;" "${DB_NAME}" 2>/dev/null); then
            printf '%s\n' "${database_info}"
            return 0
        fi
        sleep 1
    done
    printf '%s\n' 'MariaDB socket did not become stable after 30 attempts.' >&2
    "${COMPOSE[@]}" logs --no-color --tail 100 mariadb >&2 || true
    return 1
}

run_cycle() {
    local cycle=$1
    local mode=$2
    local started ended elapsed_ms
    CURRENT_CYCLE="${cycle}"

    start_database >"${EVIDENCE_DIR}/${cycle}-environment.txt"
    sql_file legacy-schema.sql
    sql_file seed.sql
    sql_file preflight.sql >"${EVIDENCE_DIR}/${cycle}-preflight.txt"
    capture_legacy_fingerprints "${EVIDENCE_DIR}/${cycle}-legacy-before.tsv"

    started=$(date +%s%N)
    sql_file up.sql
    sql_file backfill.sql

    if [[ "${mode}" == "checkpoint" ]]; then
        sql_call "CALL canonical_football_backfill('checkpoint-run', 3);"
        sql_file checkpoint-assertions.sql >"${EVIDENCE_DIR}/${cycle}-checkpoint.txt"
        sql_call "CALL canonical_football_backfill('checkpoint-run', 18446744073709551615);"
    else
        sql_call "CALL canonical_football_backfill('direct-run', 18446744073709551615);"
    fi

    sql_call "CALL canonical_football_backfill('idempotent-rerun', 18446744073709551615);"
    run_operational_rehearsal "${cycle}"
    sql_file assertions.sql >"${EVIDENCE_DIR}/${cycle}-assertions.tsv"
    ended=$(date +%s%N)
    elapsed_ms=$(( (ended - started) / 1000000 ))
    printf 'cycle\tmode\tddl_backfill_assertions_ms\n%s\t%s\t%s\n'         "${cycle}" "${mode}" "${elapsed_ms}" >"${EVIDENCE_DIR}/${cycle}-benchmark.tsv"

    capture_legacy_fingerprints "${EVIDENCE_DIR}/${cycle}-legacy-after.tsv"
    diff -u "${EVIDENCE_DIR}/${cycle}-legacy-before.tsv" "${EVIDENCE_DIR}/${cycle}-legacy-after.tsv"         >"${EVIDENCE_DIR}/${cycle}-legacy-unchanged.diff"

    sql_file down.sql
    sql_file rollback-assertions.sql >"${EVIDENCE_DIR}/${cycle}-rollback.txt"
    capture_legacy_fingerprints "${EVIDENCE_DIR}/${cycle}-legacy-after-rollback.tsv"
    diff -u "${EVIDENCE_DIR}/${cycle}-legacy-before.tsv" "${EVIDENCE_DIR}/${cycle}-legacy-after-rollback.tsv"         >"${EVIDENCE_DIR}/${cycle}-rollback-legacy-unchanged.diff"

    "${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null
}

run_cycle cycle-1 checkpoint
run_cycle cycle-2 direct

sha256sum "${ARTIFACT_DIR}"/*.sql "${ARTIFACT_DIR}/docker-compose.yml" "${ARTIFACT_DIR}/run.sh" >"${EVIDENCE_DIR}/artifact-sha256.txt"
printf '%s\n' 'VALIDATED: two fresh MariaDB 10.11.13 cycles passed; containers and volumes removed.'     >"${EVIDENCE_DIR}/verdict.txt"
CURRENT_CYCLE="complete"
