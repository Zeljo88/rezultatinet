# Calendar Phase 1 activation harness

Status: review-only. This directory does not authorize deployment, provider
access, database/Redis writes, activation, rollback, or writer restoration.

`phase1_harness.php` is the only activation/rollback executable. It never
changes the persistent calendar gate. It has three modes:

- `rehearse`: captured/sanitized HTTP fakes only; no Laravel bootstrap,
  provider, DB, Redis, filesystem evidence, or network access.
- `activate`: fresh T1 and T2 D0/D1 imports under one outer MariaDB
  transaction, with a global four-physical-attempt claim callback.
- `recover`: verify an interrupted commit or restore the exact preimage only
  while renewed writer exclusion is proven and current rows still equal the
  sealed intended postimage.

## Safety contract

Before activation, an independently authorized lifecycle executor must pause
all fixture writers and the scheduler, retain exact cron/worker preimages, and
write a fresh mode-0600 writer proof with these keys in this exact order:

```json
{"schema":1,"captured_at_utc":"2026-09-28T12:00:05Z","scheduler_paused":true,"workers_paused":true,"writer_samples":[{"captured_at_utc":"2026-09-28T12:00:00Z","processes":[]},{"captured_at_utc":"2026-09-28T12:00:05Z","processes":[]}],"cron_preimage_sha256":"64 lowercase hex","cron_paused_sha256":"64 lowercase hex"}
```

The proof hash is passed explicitly. It expires after five minutes. The
activation also holds its nonblocking phase flock and the ordinary namespaced
calendar lock, proves the persistent effective gate false and calendar
schedules absent, and enables the gate only in that PHP process.

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
fsynced. A crash between them is recovered by comparing all sealed row hashes;
mixed or advanced state is never overwritten.

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

1. Run `./VERIFY.sh`; require `VERIFIED` and the sealed hashes.
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
7. Execute exactly:
   ```bash
   ./phase1_harness.php --mode=activate \
     --app-root=/var/www/vhosts/rezultati.net/httpdocs \
     --evidence-dir=/ABSOLUTE/ROOT_0700_EVIDENCE \
     --writer-proof=/ABSOLUTE/writer-exclusion.json \
     --writer-proof-sha256=PINNED_64_HEX \
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

## Local verification

```bash
php -l phase1_harness.php
./phase1_harness.php --mode=rehearse > rehearsal-a.json
./phase1_harness.php --mode=rehearse > rehearsal-b.json
cmp rehearsal-a.json rehearsal-b.json
sha256sum rehearsal-a.json
```

The rehearsal fault list and expected invariants are in `FAULT-MATRIX.md`.
