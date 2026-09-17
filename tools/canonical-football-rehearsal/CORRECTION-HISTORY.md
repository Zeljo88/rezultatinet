# Correction history

## MariaDB 10.11.13 ambiguity fix

- Failing runs: 35221155757, 35221376872, 35221494055.
- Exact terminal error: `ERROR 1052 (23000): Column 'source_updated_at' in UPDATE is ambiguous`.
- Cause: the event upsert compared an unqualified target `source_updated_at` while its `INSERT ... SELECT` joins mapping tables with columns of the same name.
- Change: qualify only the comparison operand as `events.source_updated_at`; no legacy, application, runtime, migration, provider, or production path changed.
- Before SHA-256: `57a89d95d1d814518d7bfc1b7d11116df7c1bfd57cad4b20352df5d83602a4d9`.
- After SHA-256: `ad08b28ea593b7241cde04849430c43f1b087169834085cd7c4361912fa49c14`.
- Workspace source remains unchanged until a complete passing two-cycle run proves this correction.

## MariaDB initialization handoff retry

- Failing run: 35221656533.
- Exact terminal error after Compose reported healthy: `ERROR 2002 (HY000): Can't connect to local server through socket '/run/mysqld/mysqld.sock' (2)`.
- Cause: the MariaDB image can briefly expose its initialization server as healthy before handing off to the final server.
- Change: add a bounded 30-attempt, one-second local socket query retry after `docker compose up --wait`.
- Before SHA-256: `10137036209f00a1839d76f1fe8f8eb6ac4baa1b4b1409c02a1da48b37ef0c5a`.
- After SHA-256: `d89e49547bb0d30f87e16bc336b22b6a6b72eea473bafe09aa64b8f9d40cb208`.
- No host port, provider call, external endpoint, production path, or fallback was added.

## Evidence checksum path fix

- Failing run: 35221833048, after both database cycles completed and cleaned up.
- Exact terminal failure: `sha256sum` could not open the malformed final runner path.
- Cause: trailing spaces and a misplaced quote joined the `run.sh` argument to the evidence redirection target.
- Change: close the `run.sh` argument before the output redirection.
- Before SHA-256: `d89e49547bb0d30f87e16bc336b22b6a6b72eea473bafe09aa64b8f9d40cb208`.
- After SHA-256: `d89e49547bb0d30f87e16bc336b22b6a6b72eea473bafe09aa64b8f9d40cb208`.
- Database behavior, SQL, assertions, cleanup, and network isolation are unchanged.
- Implementation note: commit `cdbbeea` recorded the intended checksum-path fix but its substitution was ineffective; run 35221969645 therefore still used SHA-256 `d89e49547bb0d30f87e16bc336b22b6a6b72eea473bafe09aa64b8f9d40cb208`.
- Effective follow-up SHA-256: `ed6ee1bd38d09a7a6c63e54326d9ec832b34d75a266dd3b55c154637dd85216f`.

## Independent final-review corrections

- Review source: `2026-09-17-canonical-football-final-review.md`; findings M1–M4 and L1–L2.
- M2: `event_participants` now has unique `(event_id, role)` and a MariaDB-compatible CHECK coupling `home=1` and `away=2`; backfill and final assertions require exactly one of each.
- M3: backfill now uses a capability-wide MariaDB advisory lock with a bounded two-second timeout, commits run registration before projection work, records SQL failure after rollback, releases the lock on every exit, and closes stale `running` rows only after acquiring the lock.
- M3 rehearsal: each fresh cycle holds the advisory lock from another connection, proves a different run key is rejected and durably recorded, retries under an explicit new run key, injects a disposable canonical mapping conflict without a production SQL hook, proves durable failure, retries, and proves stale-run recovery.
- L2: legacy evidence now hashes every column of every row with SHA-256 in primary-key order and hashes each ordered table stream. Before/after/rollback manifests must match. The README specifies a bounded production chunk-manifest method without `GROUP_CONCAT`.
- M1, M4, and L1 are packet/evidence corrections maintained in the Rex report workspace after the corrected branch run is green; they do not add runtime/application scope.
- The 12-table additive MVP, reverse dependency rollback, legacy read-only contract, unpublished default, no-provider path, and no production hook remain unchanged.
- Corrected-head proof: run `35224791556`, job `105213485694`, head `f03d31326091b54f676c6e1a85d7beb1f7ade39c`, success; artifact `10498677999`; inner bundle SHA-256 `45c3f1a9d5772122f7ae5de5928d2a8ff5ed68da10a1015d7943ad4f84566046`.
- Both cycles passed at exact MariaDB 10.11.13 with 3 durable failures, 5 completed runs, free lock, identical before/after/rollback SHA-256 manifests, rollback 0 canonical/4 legacy, and cleanup 0 containers/networks/volumes.
