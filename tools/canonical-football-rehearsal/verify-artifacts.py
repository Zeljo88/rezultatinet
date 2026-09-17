#!/usr/bin/env python3
from pathlib import Path
import re
import sys

root = Path(__file__).resolve().parent
required = {
    "up.sql", "down.sql", "legacy-schema.sql", "seed.sql", "preflight.sql",
    "backfill.sql", "legacy-checksums.sql", "checkpoint-assertions.sql",
    "assertions.sql", "rollback-assertions.sql", "docker-compose.yml", "run.sh",
    "legacy-to-canonical-mapping.csv", "status-normalization.csv",
    "expected-rehearsal-counts.csv",
}
missing = sorted(name for name in required if not (root / name).is_file())
if missing:
    raise SystemExit(f"missing artifacts: {', '.join(missing)}")

up = (root / "up.sql").read_text()
down = (root / "down.sql").read_text()
backfill = (root / "backfill.sql").read_text()
compose = (root / "docker-compose.yml").read_text()
run = (root / "run.sh").read_text()

canonical_tables = [
    "sports", "providers", "competitions", "competition_seasons", "participants",
    "events", "event_participants", "provider_competition_mappings",
    "provider_participant_mappings", "provider_event_mappings", "import_runs",
    "identity_quarantines",
]
legacy_tables = ["leagues", "teams", "fixtures", "fixture_scores"]

for table in canonical_tables:
    if not re.search(rf"CREATE TABLE {table}\s*\(", up):
        raise SystemExit(f"up.sql missing CREATE TABLE {table}")
    if not re.search(rf"DROP TABLE IF EXISTS {table}\s*;", down):
        raise SystemExit(f"down.sql missing DROP TABLE {table}")

for table in legacy_tables:
    if re.search(rf"\b(?:ALTER|DROP|UPDATE|DELETE\s+FROM|INSERT\s+INTO)\s+{table}\b", backfill, re.I):
        raise SystemExit(f"backfill mutates legacy table {table}")
    if re.search(rf"DROP TABLE IF EXISTS {table}\s*;", down):
        raise SystemExit(f"down.sql drops legacy table {table}")

required_tokens = [
    "legacy_league_id", "legacy_team_id", "legacy_fixture_id", "source_key",
    "source_updated_at", "version", "home_score INT NULL", "away_score INT NULL",
    "publishable", "image_url", "open_dedupe_key", "last_legacy_id",
]
for token in required_tokens:
    if token not in up:
        raise SystemExit(f"up.sql missing required token: {token}")

if "mariadb:10.11.13" not in compose or "internal: true" not in compose:
    raise SystemExit("compose is not pinned to isolated MariaDB 10.11.13")
if re.search(r"^\s*ports\s*:", compose, re.M):
    raise SystemExit("compose exposes database ports")
if any(token in (up + down + backfill + run).lower() for token in ("curl ", "wget ", "http::", "https::")):
    raise SystemExit("provider/network command found in rehearsal path")
if "down --volumes --remove-orphans" not in run:
    raise SystemExit("run.sh does not clean container volumes")
if "refusing to run outside canonical_football_rehearsal" not in up:
    raise SystemExit("up.sql lacks database-name guard")
if "refusing to run outside canonical_football_rehearsal" not in down:
    raise SystemExit("down.sql lacks database-name guard")

print("PASS: artifact inventory")
print("PASS: 12 additive canonical tables and rollback coverage")
print("PASS: no legacy mutation in backfill/down")
print("PASS: namespace, provenance, season, version, nullable score, publication, media, quarantine, checkpoint fields")
print("PASS: MariaDB 10.11.13 pin, internal network, no exposed ports, cleanup")
print("PASS: no provider/network command in execution path")
sys.exit(0)
