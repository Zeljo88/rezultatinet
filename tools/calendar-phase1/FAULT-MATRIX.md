# Deterministic failure matrix

The executable `--mode=rehearse` path uses only two embedded captured,
sanitized provider rows (UTC D0 NS and D1 PST). It emits canonical JSON and
fails unless every scenario preserves the global four-attempt ceiling and the
state invariant below.

| Fault boundary | Required deterministic result |
|---|---|
| provider request 1, 2, 3, 4 | Precommit failure returns DB to Baseline; attempts never exceed 4 |
| request exhaustion / insufficient T2 budget | T2 denied; outer rollback; DB Baseline |
| T1 import / row failure | Nested work rolls back through outer transaction |
| telemetry / truncation | No commit without bounded, exact, untruncated evidence |
| T2 import | T1 and T2 writes roll back together |
| journal/evidence fsync | No commit intent means no commit |
| outer rollback | Exact Baseline |
| commit | Unknown outcome remains writer-paused and recoverable from pre/post hashes |
| commit marker / postcommit process interruption | Fsynced intent identifies exact recoverable postimage |
| config restoration | Process-local gate resets false; writers remain paused |
| HTTP/UI/log/quota | No writer-release marker |
| cron restoration / worker restoration | Failure remains paused/recoverable |
| precommit process interruption | Connection close rolls outer transaction back |
| postcommit recovery | Exact pre/post classification; no broad restore |
| concurrent advancement | Recovery reports ADVANCED_BLOCKED and never overwrites |
| fixture_scores FK | Every incoming reference is enumerated before deletion |
| rollback idempotency | Baseline is accepted as already restored |
| relationship restoration | Fixture/score/league/team/season hashes must equal Baseline |

The canonical rehearsal output must be byte-identical across consecutive runs.
