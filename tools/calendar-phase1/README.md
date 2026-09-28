# Calendar Phase 1 activation harness

Status: review-only. This directory does not authorize deployment, provider
access, database/Redis writes, activation, rollback, or writer restoration.

`phase1_harness.php` is the only activation/rollback executable. It never
changes the persistent calendar gate. It has two modes:

- `activate`: fresh T1 and T2 D0/D1 imports under one outer MariaDB
  transaction, with a global four-physical-attempt claim callback.
- `recover`: verify an interrupted commit or restore the exact preimage only
  while renewed writer exclusion is proven and current rows still equal the
  sealed intended postimage.

## Safety contract

The command accepts only its documented exact option set. Every path must be
absolute, normalized, and free of symlinks in every existing component. The
control, evidence, and lock directories are root-owned mode 0700; control and
evidence files are root-owned mode 0600 regular single-link files. Directory
ancestry is complete, inode-pinned, and not group/world-writable. The only
flock files are `phase.lock` and `maintenance.lock` in one anchor-pinned lock
directory. The ordinary Laravel calendar lock name is also anchor-pinned and
recomputed from the bootstrapped application identity.

An owner-controlled 32-byte key is provisioned through the host's secret-file
mechanism as a root-owned mode 0600 file in a root-owned mode 0700 directory;
its bytes must never enter command arguments, logs, evidence, or review
artifacts. Its exact file identity is pinned in the SHA-256-pinned trust anchor.
HMAC-SHA-256 authenticates the deployment receipt, precommit intent and seal,
commit marker, abort/safe marker, and recovery marker. Recovery requires the
complete authenticated intent -> precommit seal -> committed marker chain and
will not infer a commit from mutable evidence.

Before activation, an independently authorized lifecycle executor must pause
all fixture writers and the scheduler, retain exact cron/worker preimages, and
write a fresh mode-0600 writer proof with these keys in this exact order:

```json
{"schema":1,"captured_at_utc":"2026-09-28T12:00:05Z","scheduler_paused":true,"workers_paused":true,"writer_samples":[{"captured_at_utc":"2026-09-28T12:00:00Z","processes":[]},{"captured_at_utc":"2026-09-28T12:00:05Z","processes":[]}],"cron_preimage_sha256":"64 lowercase hex","cron_paused_sha256":"64 lowercase hex"}
```

The proof hash is passed explicitly. It expires after five minutes. The
activation also holds its nonblocking phase flock and the ordinary namespaced
calendar lock, proves the persistent effective gate false and calendar
schedules absent, and enables the gate only in that PHP process. Before quota,
provider, or database work it re-verifies the exact importer candidate hash
`9f53bfff...57ea`, UID/GID/mode `10004/1003/0644`, exact importer and harness
package bytes, and the authenticated deployment receipt containing the exact
`34856210...38a2` live preimage gate captured immediately before replacement.

The harness calls the quota-controlled gateway directly with the unchanged
`calendar|FixtureCalendarSync` identity. Every physical request must claim
from one phase-wide budget before quota reservation. T2 is forbidden unless at
least two of four claims remain. The quota baseline/after/final counters are
sealed and must equal the harness claim count.

T1 and fresh T2 both fetch exact UTC D0/D1, at most 2,000 rows/date. The
sanitized manifests contain only validated IDs, safe status, UTC date bucket,
and classification. Wrong-date/malformed/unknown rows abort before import.
Importer telemetry is exact-key, bounded, untruncated, and failure-free.

The outer transaction remains open across all four date imports. Existing
importer transactions therefore use MariaDB savepoints; any precommit error or
process exit rolls back fixture, `fixture_scores`, and team writes together.
The harness validates exact protected rows, NS/TBD/PST/CANC-only writes,
D0/D1/UTC, duplicates, score multiplicity/orphans, league/team/season/kickoff
relationships, and all live FK metadata referencing fixtures,
`fixture_scores`, teams, or leagues.

A restricted exact preimage, intended postimage, bounded telemetry, quota
counters, FK inventory, runtime mode/ownership stat, and `PRECOMMIT_INTENT`
are fsynced before commit. After commit, `COMMITTED_PENDING_POSTCHECKS` is
fsynced. Recovery acquires phase, ordinary calendar, and maintenance locks
before reading evidence, switches its transaction to `READ COMMITTED`, locks
all exact parent/target rows and every dynamic incoming-FK child set, and then
hashes locking-read values. Mixed or concurrently advanced state is never
overwritten.

All writers remain paused after commit. The harness rechecks exact DB state,
quota accounting, a bounded 1 MiB log delta, and at most 20 checksum-pinned
plain-HTTP loopback UI URLs. Only then does it fsync
`SAFE_TO_RESTORE_WRITERS.json`. Exact cron and worker restoration remains
forbidden without that marker. A postcommit rollback locks all target rows,
requires the exact intended postimage and unchanged FK inventory, verifies
every incoming reference (including `fixture_scores`), restores explicit
preimages/deletes only exact inserts, and verifies the baseline inside the same
transaction.

## Exact review/deployment/activation sequence

1. Run `./VERIFY.sh`; require `VERIFIED_STATIC`, the sealed hashes, and the isolated CI gate.
2. Independently approve the exact importer commit/package and this harness.
3. Under separate production authority, deploy only the importer package while
   the persistent gate is false, using its `APPLY-ROLLBACK.md`.
4. Copy only this sealed harness directory to a root-owned mode-0700 temporary
   operations directory; verify again. It is not an application runtime file.
5. Pause scheduler and every fixture writer; capture exact restorable
   cron/worker state; prove two zero-writer samples and emit the canonical
   checksum-pinned writer proof. Do not restore writers on failure.
6. Create a new root-owned mode-0700 evidence directory and a checksum-pinned
   JSON array of loopback health URLs (no query, fragment, userinfo, or secret).
   Through a separately reviewed lifecycle executor, create the authenticated
   deployment receipt and trust anchor, and pin the anchor SHA-256 out of band.
   The receipt must be emitted immediately after the exact package preimage
   check and atomic replacement; a later reconstructed receipt is invalid.
7. Execute exactly:
   ```bash
   ./phase1_harness.php --mode=activate \
     --app-root=/var/www/vhosts/rezultati.net/httpdocs \
     --evidence-dir=/ABSOLUTE/ROOT_0700_EVIDENCE \
     --writer-proof=/ABSOLUTE/writer-exclusion.json \
     --writer-proof-sha256=PINNED_64_HEX \
     --trust-anchor=/ABSOLUTE/ROOT_0600_TRUST_ANCHOR.json \
     --trust-anchor-sha256=OWNER_PINNED_64_HEX \
     --deployment-receipt=/ABSOLUTE/ROOT_0600_DEPLOYMENT_RECEIPT.json \
     --importer-package=/ABSOLUTE/FixtureCalendarImporter.php \
     --harness-package=/ABSOLUTE/phase1_harness.php \
     --authentication-key=/ABSOLUTE/ROOT_0600_AUTHENTICATION_KEY \
     --health-urls=/ABSOLUTE/health-urls.json \
     --health-urls-sha256=PINNED_64_HEX
   ```
8. If exit is nonzero before commit, require
   `PRECOMMIT_ABORT_ROLLED_BACK`, exact baseline, false gate, and keep writers
   paused until evidence is sealed. If commit outcome is uncertain or any
   postcommit check fails, run only this executable in `--mode=recover` with a
   new writer proof and explicit `--decision=keep` or separately authorized
   `--decision=rollback`. Never restore from a stale/global snapshot.
9. Restore exact cron/workers only after
   `SAFE_TO_RESTORE_WRITERS.json` (or a completed recovery marker), then prove
   exact restoration, false persistent/effective gate, absent calendar
   schedules/process/lock, clean HTTP/UI/log/quota state, and seal final
   evidence.

## Verification

```bash
php -l phase1_harness.php
./VERIFY.sh
```

The real Laravel + MariaDB 10.11.13 process-fault suite is
`tests/Feature/CalendarPhase1HarnessIntegrationTest.php`, executed only by the
manual, read-only-permission workflow
`.github/workflows/calendar-phase1-harness-integration.yml`. It uses fake HTTP,
an isolated data-free schema, a root-owned fixture tree, real transactions,
row/FK locks, fsync/link publication, OS process interruption, and a genuinely
concurrent database writer. `FAULT-MATRIX.md` lists the required assertions.
