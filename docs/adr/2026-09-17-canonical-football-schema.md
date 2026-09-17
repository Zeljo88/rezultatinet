# Canonical football schema shadow

**Status:** Draft migration for independent review; production execution is not authorized.

## Decision

Add the reviewed 12-table canonical football schema as 12 dependency-ordered Laravel
migrations. MariaDB auto-commits each `CREATE TABLE`, while Laravel records the
migration only after `up()` returns. Every migration therefore uses the shared,
migration-only `CanonicalFootballMigration` helper.

Each canonical table has a migration-specific table comment. On both first creation and
retry, the helper requires the exact MariaDB 10.11.13 `SHOW CREATE TABLE` SHA-256,
the exact marker, InnoDB base-table metadata, zero rows, and zero inbound foreign-key
dependents. If an interruption commits DDL before the Laravel ledger insert, a retry
adopts that exact empty table without dropping or replacing it; return from `up()`
then lets Laravel record the missing ledger row. Every mismatch aborts before any
recovery mutation.

The marker comment is the only overlay on the approved 12 `CREATE TABLE` statements.
Removing the 12 comments reproduces the reviewed extracted contract SHA-256
`cf42a7010b762886e548021b7c4be2e4e580487167014e13d7e55bedb28353b7`.
The approved full `up.sql` hash and both extracted/marked hashes are linked in
`tools/canonical-football-migration/approved-contract-manifest.json`.

The schema remains additive and empty. Legacy football tables remain the sole read/write
authority. Canonical events default to `publishable=0`; there is no backfill,
discovery, shadow execution, public cutover, provider/API call, runtime model, route,
scheduler, or queue change in this PR.

## Partial failure and rollback contract

The 12 files follow foreign-key dependency order. A failure before a table is created
leaves the current file pending. A failure after DDL commit but before ledger insertion
is repaired only by the guarded adoption path above. A failure after a ledger insert
resumes at the next file. A successful retry yields all 12 ledger rows.

A normal completed batch rolls back in reverse dependency order. Crash-window tests also
prove `migrate:rollback --step=12` removes all 12 canonical tables when prerequisites
and the resumed migration landed in different batches. Rollback is authorized only
while the canonical schema is empty; it does not target legacy tables.

## Operator recovery and abort procedure

After an interrupted schema-only migration, and only inside a separately authorized
empty-schema window:

1. stop concurrent migration attempts and preserve the failed command logs;
2. rerun `php artisan migrate --force --no-interaction` from the exact reviewed head;
3. if guarded adoption succeeds, verify all 12 canonical ledger rows and tables before
   any separately authorized rollback or later phase;
4. if recovery aborts, stop. Leave the table, its data, dependents, and Laravel ledger
   unchanged. Capture `SHOW CREATE TABLE`, table comment/type/engine, exact row count,
   inbound foreign keys, and migration-ledger state for independent escalation.

Never repair an abort by editing the ledger, dropping/renaming/altering a table, loading
baseline/design SQL, running `schema:load` or `migrate:fresh`, or copying manual SQL.
A wrong marker, wrong schema fingerprint, non-empty table, inbound dependent, view,
legacy table, or unknown name collision is deliberately not recoverable by this PR.

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
production-shaped interruption/retry timing, named owners/window, and independent
review. Legacy tables and data must never be altered by these migrations.

Residual risk remains if an external actor modifies an orphan between validation and
Laravel's ledger insert; operational exclusivity is therefore mandatory. Any unexpected
object or data causes a fail-closed escalation, not automated cleanup.

## Review provenance

- Reviewed rehearsal head: `821b7c258045d1104a88338a9cd32ea23d7a8bc5`
- Successful rehearsal run: `35226344509`
- Reviewed additive DDL SHA-256: `cdd6283cffb62861af5b5a287cb79c98a6d441920f42d93183a0666297e3ce7e`
- Reviewed extracted CREATE SHA-256: `cf42a7010b762886e548021b7c4be2e4e580487167014e13d7e55bedb28353b7`
- Marked migration contract SHA-256: `ea5cc7206bd09aba376df0e5f21a0dfba10b34e91113a322ce1642774e724bd5`
- Reviewed rollback DDL SHA-256: `44619c42461b70d4871e9320ff806e6c664a716365dc0cf41da32b20f5b1f6e0`
