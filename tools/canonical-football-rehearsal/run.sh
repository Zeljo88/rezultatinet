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
    sql_file legacy-checksums.sql >"${EVIDENCE_DIR}/${cycle}-legacy-before.tsv"

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
    sql_file assertions.sql >"${EVIDENCE_DIR}/${cycle}-assertions.tsv"
    ended=$(date +%s%N)
    elapsed_ms=$(( (ended - started) / 1000000 ))
    printf 'cycle\tmode\tddl_backfill_assertions_ms\n%s\t%s\t%s\n'         "${cycle}" "${mode}" "${elapsed_ms}" >"${EVIDENCE_DIR}/${cycle}-benchmark.tsv"

    sql_file legacy-checksums.sql >"${EVIDENCE_DIR}/${cycle}-legacy-after.tsv"
    diff -u "${EVIDENCE_DIR}/${cycle}-legacy-before.tsv" "${EVIDENCE_DIR}/${cycle}-legacy-after.tsv"         >"${EVIDENCE_DIR}/${cycle}-legacy-unchanged.diff"

    sql_file down.sql
    sql_file rollback-assertions.sql >"${EVIDENCE_DIR}/${cycle}-rollback.txt"
    sql_file legacy-checksums.sql >"${EVIDENCE_DIR}/${cycle}-legacy-after-rollback.tsv"
    diff -u "${EVIDENCE_DIR}/${cycle}-legacy-before.tsv" "${EVIDENCE_DIR}/${cycle}-legacy-after-rollback.tsv"         >"${EVIDENCE_DIR}/${cycle}-rollback-legacy-unchanged.diff"

    "${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null
}

run_cycle cycle-1 checkpoint
run_cycle cycle-2 direct

sha256sum "${ARTIFACT_DIR}"/*.sql "${ARTIFACT_DIR}/docker-compose.yml" "${ARTIFACT_DIR}/run.sh" >"${EVIDENCE_DIR}/artifact-sha256.txt"
printf '%s\n' 'VALIDATED: two fresh MariaDB 10.11.13 cycles passed; containers and volumes removed.'     >"${EVIDENCE_DIR}/verdict.txt"
CURRENT_CYCLE="complete"
