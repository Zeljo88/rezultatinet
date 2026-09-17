# A1b MariaDB 10.11 clean-clone rehearsal

This harness uses `mariadb:10.11.13`, no published host port, an empty-password root account confined to the disposable Compose network, and a tmpfs database. It imports no production rows and makes no network/API calls from Laravel.

From a clean repository checkout, regenerate the baseline from the tracked,
sanitized schema-metadata fixture and verify it byte-for-byte before running the
rehearsal:

```bash
tmp_schema="$(mktemp)"
trap 'rm -f "$tmp_schema"' EXIT
python3 tools/a1b/generate_mariadb_baseline.py \
  tools/a1b/fixtures/2026-09-16-a1a-schema-manifest.json \
  "$tmp_schema"
cmp "$tmp_schema" database/schema/mariadb-schema.sql
tools/a1b/rehearse.sh tools/a1b/fixtures/2026-09-16-a1a-schema-manifest.json
```

The fixture contains only schema metadata and the 21-row Laravel migration
ledger required by the generator. It contains no application rows or
environment values.

It must prove, in order:

1. without `database/schema/mariadb-schema.sql`, historical migrations fail at missing `player_stats`;
2. with the schema state, `artisan migrate` and unit tests pass;
3. critical columns/indexes/FKs/checks match the A1a manifest.

Exit 2 means an exact MariaDB 10.11 container runtime is unavailable; do not substitute MySQL.
