# Canonical football schema shadow

**Status:** Draft migration for independent review; production execution is not authorized.

## Decision

Add the reviewed 12-table canonical football schema as 12 ordered Laravel migrations.
One table per migration is intentional: MariaDB DDL auto-commits, so each successful
table has an independent migration-ledger checkpoint and an interrupted run can resume.
Laravel rolls a completed batch back in reverse filename/dependency order.

The schema is additive and empty. Legacy football tables remain the sole read/write
authority. Canonical events default to `publishable=0`; there is no backfill,
discovery, shadow execution, public cutover, provider/API call, runtime model, route,
scheduler, or queue change in this PR.

Public URL identity remains provider-free: any future public identity must use canonical
`public_id`, never a provider external ID. This PR does not expose or consume that ID.

## Operational boundaries

Do not run any of these against production:

- `php artisan schema:load`
- `php artisan migrate:fresh`
- the temporary design/rehearsal `up.sql` or `down.sql`
- any manual copy of the design SQL

A separately authorized deployment may eventually run only the reviewed Laravel
migration head after all production gates pass. Backfill, discovery, shadow reads,
publication/indexing, consumer cutover, and any rollback that drops non-empty canonical
tables each require separate future review and authorization.

Before any production DDL authorization, require current aggregate preflight, exact-head
MariaDB 10.11.13 CI, backup/restore proof, free-space and metadata-lock window gates,
and independent review. Legacy tables and data must never be altered by these migrations.

## Review provenance

- Reviewed rehearsal head: `821b7c258045d1104a88338a9cd32ea23d7a8bc5`
- Successful rehearsal run: `35226344509`
- Reviewed additive DDL SHA-256: `cdd6283cffb62861af5b5a287cb79c98a6d441920f42d93183a0666297e3ce7e`
- Reviewed rollback DDL SHA-256: `44619c42461b70d4871e9320ff806e6c664a716365dc0cf41da32b20f5b1f6e0`
