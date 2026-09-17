# Canonical football MariaDB rehearsal

This is an inert, synthetic-only rehearsal for the proposed canonical football identity/event spine. It is outside Laravel migration auto-discovery and refuses to run unless the selected database is exactly `canonical_football_rehearsal`.

## Safety properties

- Pinned to `mariadb:10.11.13`.
- No host port is published.
- Docker network is `internal: true`; the database cannot call providers or the public network.
- Uses an empty root password only inside the unexposed disposable container.
- Uses tmpfs for database storage.
- `up.sql`, `down.sql`, and the backfill enforce the rehearsal database name.
- Backfill only reads `leagues`, `teams`, `fixtures`, and `fixture_scores`.
- Rollback drops only the 12 new canonical/rehearsal tables.
- The shell trap removes the project container and volumes on success or failure.
- No Laravel, production database, scheduler, cache, queue, provider, or public route is touched.

## Run

Prerequisites: Docker with Compose v2.

```bash
cd /home/azureuser/.openclaw/workspace-rex/artifacts/canonical-football
python3 verify-artifacts.py
./run.sh
```

Override only the local evidence destination if needed:

```bash
EVIDENCE_DIR=/absolute/local/path ./run.sh
```

## What the run proves

Cycle 1:

1. Starts a fresh MariaDB 10.11.13 container.
2. Loads an exact minimal legacy schema subset and six deterministic synthetic fixtures.
3. Captures complete ordered SHA-256 legacy fingerprints.
4. Applies additive canonical DDL.
5. Backfills through legacy fixture 3.
6. Asserts the saved checkpoint and partial cardinalities.
7. Resumes to fixture 6.
8. Runs a second complete idempotent import under a new run key.
9. Rehearses overlap rejection, bounded lock timeout, durable failure, retry, stale recovery, and lock release.
10. Asserts mapping, score/null, status, participant, quarantine, publication, and version invariants.
11. Proves legacy SHA-256 fingerprints unchanged.
12. Runs rollback and proves legacy tables/fingerprints remain.

Cycle 2 repeats from a new empty tmpfs database and performs the complete backfill directly. Final cleanup removes containers and volumes.

Expected final synthetic state:

- 2 competitions, 3 competition seasons, 4 team participants.
- 4 mapped events, each with exactly 2 distinct participants.
- 2 quarantined source fixtures: self-participant and unknown status.
- 0 publishable events.
- Event versions remain 1 after idempotent replay.
- 2 nullable-score events.
- Legacy row counts and complete-row SHA-256 fingerprints remain unchanged.

## Files

- `up.sql` / `down.sql`: exact additive DDL and rehearsal rollback.
- `legacy-schema.sql` / `seed.sql`: minimal legacy shape and synthetic input.
- `preflight.sql`: aggregate-only anomaly/status inventory.
- `backfill.sql`: restartable mapping and header projection procedure.
- `checkpoint-assertions.sql`, `assertions.sql`, `rollback-assertions.sql`: executable gates.
- `legacy-checksums.sql`: source immutability fingerprint.
- `legacy-to-canonical-mapping.csv`: field and authority matrix.
- `status-normalization.csv`: explicit status policy.
- `expected-rehearsal-counts.csv`: checkpoint/final count contract.
- `docker-compose.yml` / `run.sh`: isolated two-cycle harness.
- `verify-artifacts.py`: static safety/inventory validation.

## Final-review correction contract

- `event_participants` enforces `home/1` and `away/2` with a MariaDB CHECK, unique `(event_id, role)`, unique `(event_id, side_order)`, and unique `(event_id, participant_id)`. Backfill and final assertions require exactly one home and one away for every event.
- Backfill takes the capability-wide advisory lock `canonical_football:legacy_header_backfill` with a two-second timeout. Different run keys cannot project concurrently.
- A lock timeout or SQL exception is recorded as a committed `failed` import run after projection work rolls back. The advisory lock is released on success, no-op completion, pause, and exception.
- Once the advisory lock is acquired, another `waiting` or `running` row is stale by contract because MariaDB releases advisory locks when a connection ends. The new owner closes that row with recovery provenance before proceeding.
- Retry ownership belongs to the operator holding the explicit run key. Failed attempts remain immutable evidence; retries use a new, linked-by-evidence run key in this rehearsal (`overlap-retry`, `injected-failure-retry`, or `stale-recovery-run`).
- Failure injection is harness-only: the runner temporarily creates a conflict in a disposable canonical mapping, proves durable failure, restores it, and retries. No test parameter or hook exists in production-proposal SQL and no legacy row is changed.
- Each cycle deterministically rejects a distinct overlapping run, verifies the bounded timeout, retries successfully, records and recovers a synthetic stale run, and proves the lock is free afterward.
- Legacy immutability uses SHA-256 for every complete row, ordered by primary key, followed by SHA-256 of each table's bounded row-hash stream. Before, after replay, and after rollback manifests must be byte-identical.

### Production-safe legacy fingerprint method

For production preflight only, scan each legacy table in reviewed half-open primary-key chunks (for example 5,000 rows). For each row, serialize every column with type/null framing and compute SHA-256; stream ordered `primary_key<TAB>row_sha256` lines to an offline evidence file. Record each chunk's bounds, row count, and SHA-256, then hash the ordered chunk manifest. Repeat with the same snapshot boundary after backfill and rollback. This bounds memory/output per query, supports restart, and does not use `GROUP_CONCAT`; chunk size and snapshot/replica impact require production measurement and approval.


## Current execution status

The earlier harness passed on GitHub Actions. These final-review corrections are pending a new clean two-cycle GitHub Actions run at the corrected branch head; do not treat them as observed until that run is green and archived. Production DDL/backfill remains NO-GO.
