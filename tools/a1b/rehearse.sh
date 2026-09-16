#!/usr/bin/env bash
set -Eeuo pipefail
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
MANIFEST=${1:-"$(cd "$ROOT/.." && pwd)/reports/2026-09-16-a1a-schema-manifest.json"}
OUT=${2:-"$ROOT/build/a1b-rehearsal"}
COMPOSE=(docker compose -f "$ROOT/tools/a1b/compose.yaml")
mkdir -p "$OUT"
command -v docker >/dev/null || { echo "BLOCKED: docker is required for exact MariaDB 10.11 rehearsal" >&2; exit 2; }
"${COMPOSE[@]}" up -d --build --wait db
version=$("${COMPOSE[@]}" exec -T db mariadb -uroot --skip-column-names -e 'SELECT VERSION()')
[[ "$version" == 10.11.*-MariaDB* ]] || { echo "BLOCKED: expected MariaDB 10.11, got $version" >&2; exit 2; }
"${COMPOSE[@]}" build app
reset_db() { "${COMPOSE[@]}" exec -T db mariadb -uroot -e 'DROP DATABASE IF EXISTS a1b; CREATE DATABASE a1b CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'; }
reset_db
set +e
"${COMPOSE[@]}" run --rm -T app sh -lc '
  rm -rf /tmp/current && cp -a /workspace /tmp/current && cp -a /build/vendor /tmp/current/vendor
  rm -f /tmp/current/database/schema/mariadb-schema.sql
  cd /tmp/current
  php artisan migrate --force --no-interaction
' >"$OUT/current-clean-install.log" 2>&1
current_rc=$?
set -e
if [[ $current_rc -eq 0 ]] || ! grep -q 'player_stats' "$OUT/current-clean-install.log"; then
  echo "FAIL: historical clean install did not fail at missing player_stats as expected" >&2
  exit 1
fi

reset_db
"${COMPOSE[@]}" run --rm -T app sh -lc '
  rm -rf /tmp/proposed && cp -a /workspace /tmp/proposed && cp -a /build/vendor /tmp/proposed/vendor
  cd /tmp/proposed
  php artisan migrate --force --no-interaction
  php artisan migrate:status --no-interaction
  php artisan test --testsuite=Unit
' >"$OUT/proposed-clean-install.log" 2>&1
"${COMPOSE[@]}" exec -T db mariadb -uroot --batch --raw --skip-column-names a1b \
  < "$ROOT/tools/a1b/capture_information_schema.sql" > "$OUT/actual-critical-schema.ndjson"
python3 - "$OUT/actual-critical-schema.ndjson" "$OUT/actual-critical-schema.json" <<'PYTHON'
import json, pathlib, sys
rows = [json.loads(line) for line in pathlib.Path(sys.argv[1]).read_text().splitlines() if line.strip()]
pathlib.Path(sys.argv[2]).write_text(json.dumps({'tables': rows}, indent=2) + '\n')
PYTHON
python3 "$ROOT/tools/a1b/assert_manifest_parity.py" "$MANIFEST" "$OUT/actual-critical-schema.json" \
  | tee "$OUT/parity.log"
printf 'MariaDB=%s\ncurrent_rc=%s\nproposed_rc=0\n' "$version" "$current_rc" > "$OUT/summary.txt"
echo "PASS: exact MariaDB 10.11 current-failure/proposed-success rehearsal; evidence in $OUT"
