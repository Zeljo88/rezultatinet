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
3. Captures legacy checksums.
4. Applies additive canonical DDL.
5. Backfills through legacy fixture 3.
6. Asserts the saved checkpoint and partial cardinalities.
7. Resumes to fixture 6.
8. Runs a second complete idempotent import under a new run key.
9. Asserts mapping, score/null, status, participant, quarantine, publication, and version invariants.
10. Proves legacy checksums unchanged.
11. Runs rollback and proves legacy tables/checksums remain.

Cycle 2 repeats from a new empty tmpfs database and performs the complete backfill directly. Final cleanup removes containers and volumes.

Expected final synthetic state:

- 2 competitions, 3 competition seasons, 4 team participants.
- 4 mapped events, each with exactly 2 distinct participants.
- 2 quarantined source fixtures: self-participant and unknown status.
- 0 publishable events.
- Event versions remain 1 after idempotent replay.
- 2 nullable-score events.
- Legacy row counts and checksums remain unchanged.

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

## Current execution status

The authoring host has no Docker, Podman, LXD CLI, MariaDB client, or MariaDB server. Static validation passes, but the database rehearsal has not run here. Do not treat expected counts as observed results. Production DDL/backfill remains NO-GO until `./run.sh` passes on a Docker-capable isolated host and its evidence is reviewed.
