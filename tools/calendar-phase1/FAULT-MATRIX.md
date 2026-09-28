# Real integration and process-fault matrix

The manual CI workflow runs this matrix as root against exact MariaDB
10.11.13, a production-shaped data-free schema, fake HTTP transport, and an
isolated filesystem. It invokes the real activation and recovery functions;
there is no disconnected state-machine rehearsal.

| Scenario or boundary | Required result |
|---|---|
| normal activation + rollback | Two real imports commit; authenticated recovery restores exact empty fixture/team/score baseline |
| second rollback | Existing authenticated recovery marker and baseline are accepted idempotently |
| provider retries / four-claim cap | Four physical attempts maximum; T2 is denied when two claims do not remain; outer transaction rolls back |
| tampered intent/seal/commit marker | HMAC or cross-reference validation blocks before any recovery read/write transaction |
| anchor/key/package identity drift | Activation blocks before quota, provider, or DB mutation |
| unknown option | Strict allowlist rejects it before bootstrap or external work |
| proof/path/lock symlink or ownership drift | Non-symlink root-only contract blocks before provider use |
| occupied activation evidence directory | Freshness gate blocks; no existing name can be replaced |
| phase-lock contention | Nonblocking fixed lock fails closed before provider use |
| concurrent committed advancement | A second MariaDB transaction holds then commits an update while recovery waits; locking read sees drift and never overwrites it |
| dynamic incoming FKs | Exact parents are locked first; all matching child rows are locked and unexpected references block deletion |
| before outer transaction | SIGKILL leaves no DB writes and no recoverable commit chain |
| after transaction begin | Connection death rolls the real outer transaction back |
| after provider attempts/responses 1-4 | Real gateway accounting never exceeds four; uncommitted DB work rolls back |
| intent file fsync/publish/directory fsync | Precommit interruption never creates a valid committed chain |
| seal file fsync/publish/directory fsync | Partial seal is rejected; DB work rolls back |
| before outer commit | Exact baseline after process death |
| after outer commit but before commit-marker publication | Recovery blocks because authenticated `COMMITTED.json` is mandatory; writers must remain paused for owner adjudication |
| commit-marker file fsync before publication | Recovery blocks; a private temporary file is not accepted as a marker |
| commit-marker publication / directory fsync | Authenticated chain permits exact locking-read recovery to baseline |

Every successful rollback verifies fixture, score, team, league, FK,
duplicate, orphan, UTC, quota, evidence, and process-local gate invariants.
