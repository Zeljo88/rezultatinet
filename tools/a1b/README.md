# A1b MariaDB 10.11 clean-clone rehearsal

This harness uses `mariadb:10.11.13`, no published host port, an empty-password root account confined to the disposable Compose network, and a tmpfs database. It imports no production rows and makes no network/API calls from Laravel.

```bash
tools/a1b/rehearse.sh ../reports/2026-09-16-a1a-schema-manifest.json
```

It must prove, in order:

1. without `database/schema/mariadb-schema.sql`, historical migrations fail at missing `player_stats`;
2. with the schema state, `artisan migrate` and unit tests pass;
3. critical columns/indexes/FKs/checks match the A1a manifest.

Exit 2 means an exact MariaDB 10.11 container runtime is unavailable; do not substitute MySQL.
