# Football API integration and quota audit — 2026-09-16

## Scope and safety

Read-only audit of:

- repository: `/home/azureuser/.openclaw/workspace-rex/rezultatinet-seo-fixes`
- production application: `/var/www/vhosts/rezultati.net/httpdocs`
- production scheduler, application-user crontab, queue processes, selected logs, and aggregate database queries through `zeljo_rezultati`
- owner-provided subscription evidence: API-Football Pro active, 7,500 requests/day, dashboard snapshot 103 used, subscription end 2026-12-16

No API test request was made. No production, code, database, scheduler, configuration, or GSC change was made. No credentials were displayed. The official v3 documentation URL returned HTTP 403 to this audit host even with a browser user agent, so endpoint behavior below is grounded in the application code and live evidence, not an invented documentation result.

Audit time: 2026-09-16 11:00–11:05 UTC. Production and repository SHA-256 hashes matched for the scheduler, three sports services, four football jobs, `ApiCallLog`, and `config/services.php`.

## Executive assessment

**Overall: not safe to treat the current 7,000-row database guard as a reliable quota control.** The system has a sound high-level pattern—background ingestion into a local database and pages reading local data—but quota enforcement and provider resilience are fragmented.

P0 findings:

1. `api_call_logs` counts logical application operations, not actual HTTP attempts. It misses Laravel HTTP retries and whole queue-job retries, has no provider response headers, status, sport, job, or outcome, and is not atomic.
2. The live dashboard snapshot (103) and production counter (1,708 at 11:03 UTC) are not comparable/reconciled. Production had logged 1,327 `live=all` operations since UTC midnight and many empty provider responses before 11:00; successful responses resumed at about 11:00. The provider dashboard must remain the authority until physical-attempt accounting is implemented.
3. `FixZombieFixtures` is actively draining up to 480 logical calls/day (10 fixture-detail requests every 30 minutes) against a backlog of 463 stale fixtures. The same fixtures were retried repeatedly while responses were empty.
4. `FinalizeFinishedFixtures` runs every five minutes with no per-run cap. Three fixture IDs were each requested 35–36 times by 11:00 UTC. `touch()` only restarts the 15-minute eligibility window for standard stuck fixtures; it does not provide a meaningful long cooldown.
5. Central-client `retry(2, 500)` plus Redis worker `--tries=3` can amplify a failing logical operation to as many as 9 physical HTTP attempts. There is no 429-specific behavior, `Retry-After` handling, or circuit breaker.
6. The production log is approximately 84 GB and uses the single log channel. Repetitive scheduler/provider messages materially amplify disk risk.
7. The active football schedule does not sync fixture calendars. `sync:fixtures`, future sync, and backfill are paused. Live results can only update fixtures whose league and teams already exist; live logs show fixtures being discarded every 30 seconds because team rows are missing.

Recent database history shows 3,629–3,909 logged logical operations/day, with a 14-day median of about 3,700. That is below 7,500, but it is not evidence that physical provider use is safe.

## Fact labels

- **Code fact:** observed in the matching repository/production PHP source.
- **Live config fact:** observed in production scheduler, crontab, processes, non-secret environment selectors, logs, or aggregate DB query.
- **Owner evidence:** supplied in the audit request; not independently queried from the provider.
- **Estimate/assumption:** explicitly modeled; not a measured provider total.

## Existing provider/client/adapter architecture

### Current shape

1. **Credential/config:** `config/services.php` maps `API_FOOTBALL_KEY` to `services.api_football.key`. The same setting is also reused by basketball and tennis services. **Code fact.**
2. **Primary football client:** `App\Services\ApiFootballService` wraps Laravel `Http` with base URL `https://v3.football.api-sports.io`, 15-second timeout, and `retry(2, 500)`. **Code fact.**
3. **Bypasses:** `SyncFixtures`, `SyncStandings`, `SyncLeagues`, `SyncFixtureEvents`, and `SyncFixStuck` construct HTTP calls directly rather than using the service. Authentication header conventions are mixed (`X-RapidAPI-Key`/host and `x-apisports-key`). **Code fact.**
4. **No adapter boundary:** there is no provider interface, DTO/normalizer layer, repository abstraction, or provider-agnostic adapter. API payload arrays are interpreted directly in jobs/commands. **Code fact.**
5. **Ingestion:** scheduled commands/jobs call the provider, normalize selected fields, and upsert local MySQL tables. Redis backs queues; cache is configured as Redis in the effective last duplicate `.env` assignment. **Live config fact.**
6. **Serving:** Livewire pages and HTTP controllers read models/database data. A complete search of `app/Http`, `app/Livewire`, and `routes/web.php` found no API-Football service, API-Sports host, or direct HTTP provider call. **Code fact.**

### Provider call paths

| Endpoint | Parameters/purpose | Caller(s) | Active schedule? | Request shape |
|---|---|---|---|---|
| `GET /fixtures` | `live=all` | `ApiFootballService::getLiveFixtures` → `FetchLiveFixtures` | Yes, every 30 seconds | One batched request returns all live fixtures |
| `GET /fixtures` | `id={fixture}` | `getFixtureById` → finalizer, zombie repair, events backfill; direct `SyncFixStuck` | Finalizer every 5 min; zombie every 30 min; others manual/paused | Per fixture |
| `GET /fixtures` | `date={Y-m-d}` | `getTodayFixtures`; direct `SyncFixtures` | No scheduled fixture sync | One batched request per date |
| `GET /fixtures/lineups` | `fixture={id}` | `getLineups` → `FetchFixtureLineups`, `SyncLineups` | Conditional job dispatch from live polling; command paused | Per fixture |
| `GET /fixtures/events` | `fixture={id}` | direct `SyncFixtureEvents` | Manual only | Per fixture |
| `GET /players/topscorers` | league + season | `SyncTopScorers` | Paused | Per league |
| `GET /players/topassists` | league + season | `SyncTopScorers` | Paused | Per league |
| `GET /standings` | league + season | direct `SyncStandings` | Daily 04:30 UTC | Per league-season attempt, up to 3 seasons |
| `GET /leagues` | `current=true` | direct `SyncLeagues` | Manual only | One batch |

`getTodayFixtures()` exists but no active scheduled caller was found.

## Commands/jobs and effective frequency

Production application-user crontab runs `php artisan schedule:run` every minute. Laravel's sub-minute scheduler keeps the invocation alive to dispatch the 30-second event. Two Redis queue workers were visible, both with `--tries=3`; one had a one-hour max time. **Live config fact.**

| Workload | Effective frequency | Calls per run | Overlap/dedup |
|---|---:|---:|---|
| `FetchLiveFixtures` | Every 30 sec, nominally 2,880/day | 1 logical `live=all` call | No `withoutOverlapping`, no unique job; queue delay/overlap possible |
| `FinalizeFinishedFixtures` | Every 5 min, 288 runs/day | 0..N fixture-ID calls; no cap | Scheduler `withoutOverlapping`; queued job itself not unique |
| `sync:standings` | Daily 04:30 | 1–3 calls × up to 14 leagues; observed 42 today | Named and `withoutOverlapping` |
| `FixZombieFixtures` | Every 30 min, 48 runs/day | Up to 10 fixture-ID calls = up to 480/day | Named and `withoutOverlapping`; 60-minute row cooldown |
| `FetchFixtureLineups` | Conditionally dispatched by each live poll | 1 per eligible fixture/day | `ShouldBeUnique`; fixture timestamp is set before request |
| Fixture date sync/backfills | Paused | — | Commented out |
| Events backfill | Paused | Potentially one per fixture | Commented out |
| Top scorers/assists | Paused | 20 logical calls for ten configured leagues | Commented out |
| `sync:lineups` command | Paused | One per eligible fixture | Commented out |
| `sync:fix-stuck` command | Paused | One per stale fixture | Commented out |

The 01:00–07:00 UTC night guard only skips when the database has no live-status rows. It did not skip today: stale live statuses kept polling active at roughly 120 `live=all` calls/hour overnight. **Live evidence.**

## Database and cache path

- `live=all` → league/team lookup → `fixtures`, `fixture_scores`, and embedded response events into `fixture_events`; conditional queued lineup fetch into `fixture_lineups`; then a Reverb broadcast. **Code fact.**
- Fixture-detail repair → `fixtures` and `fixture_scores`. **Code fact.**
- Standings → `teams` and `standings`; code then deletes other-season standings for that league. This audit did not invoke it. **Code fact.**
- Top scorer/assist calls → `players` and `player_stats`. **Code fact.**
- `ApiCallLog` → `api_call_logs(endpoint, called_date, timestamps)`. **Code fact.**
- No provider-response cache or request coalescing layer was found. Redis is used for cache/queue infrastructure, but provider results are persisted directly to MySQL. **Code/live config fact.**
- Pages read the local DB; no page request directly calls API-Football. **Code fact.**

## Retry, backoff, failure, and 429 behavior

### Central football service

- Timeout: 15 seconds.
- Retry: two retries with a fixed 500 ms delay (up to three physical attempts in one service operation).
- Non-success: methods return `[]` when a response object reaches the method and is unsuccessful.
- Exception after retries: generally escapes. `FetchFixtureLineups` catches `Throwable`; the three main scheduled football jobs do not.
- Queue retry: worker `--tries=3` can rerun an uncaught failed job, potentially multiplying a single logical operation to 3 HTTP attempts × 3 job attempts = 9 physical attempts.
- No exponential backoff/jitter, no status-specific retry policy, no `Retry-After`, no provider reset-time tracking, and no circuit breaker.

### Direct callers

- Behavior is inconsistent. Some check `successful()`, standings does not validate success before parsing/logging, and only `SyncFixStuck` explicitly sets a timeout.
- No direct caller has 429-specific handling.

### Failure side effects

- `FetchLiveFixtures` inserts an API log row after the service returns, including when the returned array is empty. It does not capture HTTP status or provider error details.
- Finalizer/zombie jobs also log a call when data is empty, then touch the fixture. The cooldowns still allow repeated waste; three finalizer targets had 35–36 requests each by 11:00.
- `FetchFixtureLineups` marks `lineups_fetched_at` before calling the API. An empty/failing call suppresses another attempt for the rest of that day.
- A 429 can therefore be retried aggressively, fail a queue job, and still be absent or represented as only one row in `api_call_logs`.

## Existing counters and logging

### Database counter

`ApiCallLog::getTodayCount()` counts rows whose `called_date` is today. Guards vary across code: 7,000, 7,400, and 7,500. Checks are read-then-act, not atomic, so concurrent workers can cross a threshold.

What is missing:

- actual physical attempt count
- provider response status and API error payload category
- response rate-limit headers/current provider usage
- retry attempt number
- request owner (job/command), sport, duration, cached/skipped state
- atomic reservation before request
- a shared reset time/time-zone definition
- alerts at 70%/85%/95%

### Live observations

- DB logical calls at 11:03 UTC: **1,708**.
- Of those: **1,327** `GET /fixtures?live=all`; 42 standings attempts; the remainder mostly fixture-ID repair.
- Owner dashboard snapshot: **103 used**. **Owner evidence.** Snapshot time/reset basis is unknown.
- Recent complete days: 3,629–3,909 DB rows/day; median ≈3,700.
- Basketball/tennis-prefixed API rows during the last 14 complete/current days: zero.
- Production log: approximately **84 GB**, single `laravel.log`.
- Production DB: 665,165 API log rows and 4,234,711 failed-job rows. The failed-job table is an operational warning; detailed aggregation timed out and is a known unknown.

The dashboard/DB difference is expected to be possible because the DB logs logical post-call operations, while the provider counts accepted physical requests according to its own reset/account rules. Empty/error calls before approximately 11:00 and successful responses after 11:00 further prevent direct reconciliation. Neither number should be transformed into the other without provider headers/response telemetry.

## Football-only enablement

### Football

Active provider workloads are football-only: live polling, finalizer, standings, zombie repair, and conditional football lineups. **Live config fact.**

### Basketball

- Both basketball scheduler entries are commented out in production.
- `ApiBasketballService::getGamesByDate()` returns `[]` without making an HTTP request; the old calling method remains under `_DISABLED`.
- A manual `sync:basketball` invocation would create a misleading local API log row but make no provider request.
- Last basketball table update: 2026-03-22 08:30 UTC.

**Assessment:** externally disabled in both scheduler and current service path, though not protected by an explicit feature flag.

### Tennis

- Both tennis scheduler entries are commented out.
- `getLiveMatches()` returns before its HTTP code.
- `getMatchesByDate()` is still live code. A manual `sync:tennis` would make a tennis provider request.
- Tennis table is empty.

**Assessment:** scheduled traffic is disabled, but tennis is **not hard-disabled**. A manual/accidental command can call the provider.

### Other sports

No other sports-provider scheduled calls were found. There is no centralized sport allowlist/kill switch; disablement relies on commented scheduler code and method stubs. **Code fact.**

## Request estimates

### Modeling assumptions

- One `live=all` request is batched across match count, so a busy day does not multiply live-poll calls.
- Because stale live rows defeat the night guard, current baseline assumes 24-hour polling: `2 polls/min × 60 × 24 = 2,880` logical calls/day.
- Zombie worst under current cap: `48 runs × 10 = 480` fixture-detail calls/day.
- Standings current maximum/observed today: `14 leagues × 3 seasons = 42` calls/day.
- `F` = finalizer fixture-detail calls/day; it has no per-run cap.
- `L` = unique top-league lineup calls/day; currently recent fixtures showed zero eligible configured top-11 league IDs and lineup data has not updated since 2026-05-30.
- These are logical-operation estimates. Physical attempts can be higher because of HTTP and queue retries.

### Normal weekday

Formula: `2,880 live + 480 zombie + 42 standings + ~250 finalizer + ~0 lineups = ~3,652` logical calls/day.

Rounded operational estimate: **~3,700/day**, consistent with the 14-day median.

### Busy Champions League day

Formula: `2,880 live + 480 zombie + 42 standings + ~280 finalizer + up to 18 lineups = ~3,700` logical calls/day.

Estimate: **~3,700–3,900/day**. Match volume has little effect on the batched live endpoint, but it can add per-fixture lineup/finalizer calls. Important caveat: the active fixture-calendar sync is paused, and recent DB rows did not identify configured top-11 fixtures, so 18 is a scenario assumption rather than a measured current workload.

### Weekend peak

Formula: `2,880 live + 480 zombie + 42 standings + ~400 finalizer + up to 80 lineups = ~3,882` logical calls/day.

Estimate: **~3,900/day**, matching the recent observed maximum of 3,909 DB rows (2026-09-13).

### Worst case

Logical guard case: effectively **about 7,000/day**, after which central jobs intend to stop. This is not a strict ceiling because:

- checks are non-atomic and concurrent;
- constants vary up to 7,500;
- finalizer has no per-run cap;
- calls can occur between guard and log insertion;
- retry attempts are not counted.

Transport-amplified case: a failing central-client request may reach **9 physical attempts per logical operation** (3 HTTP attempts × 3 queue attempts). It is not valid to multiply all 7,000 operations by nine as a likely forecast, but **63,000 is the theoretical failure-amplified upper bound absent a provider-side cutoff**. The provider will likely throttle earlier; current code would react poorly to that throttle.

## Recommended hard budgets and thresholds

Use provider-reported usage/headers as authority and atomically reserve every physical attempt before sending it.

### Global daily plan control (7,500/day)

- **70% = 5,250:** warning; stop manual/backfill, scorer/assist, events, and nonessential repair; move live polling to 60 seconds when no priority match is live.
- **85% = 6,375:** critical; allow only batched live polling and tightly capped completion repair; disable lineups and standings until reset.
- **95% = 7,125:** hard stop/circuit open for all normal traffic until the provider reset. Keep the final 375-request reserve unused for accounting drift/operations; do not automatically consume it.
- **Absolute vendor limit = 7,500:** never an application target.

### Sub-budgets (all inside the 7,125 global hard stop)

- `fixtures live=all`: hard 3,000/day (current full-day 30-second requirement is 2,880).
- fixture-ID repair (finalizer + zombie combined): hard 500/day initially; per-fixture dedup and exponential cooldown required.
- lineups: hard 250/day and one successful/terminal attempt per fixture.
- standings: hard 60/day, but fix season selection so normal use is approximately 14/day rather than 42.
- manual/backfill/events/scorers: default hard 0 in production; require an explicit budget allocation, dry-run count, and operator confirmation.
- non-football sports: hard 0 while owner policy remains football-only.

Sub-budget totals need not consume the global budget; unallocated headroom is intentional.

## Twelve architecture controls and compliance

**Blocker/qualification:** the referenced owner-pasted list of 12 rules is not present in this conversation, repository, workspace notes, or accessible Rex session history. It would be unsafe to claim verbatim compliance with text that was not available. The following is an explicit provisional assessment against the 12 controls implied by the request. It must be remapped to the owner's exact wording when that brief is supplied.

| # | Provisional architecture control | Status | Evidence/assessment |
|---:|---|---|---|
| 1 | One centralized provider client | **Fail** | Five commands bypass `ApiFootballService`. |
| 2 | Provider credentials remain server-side | **Pass** | Config/env only; no browser/provider call found. |
| 3 | No page request directly calls provider | **Pass** | Web/Livewire search found DB reads only. |
| 4 | Persist provider data and serve local DB/cache | **Pass with gap** | MySQL persistence is standard; no provider-response cache/coalescing. |
| 5 | Prefer batch endpoints over per-fixture calls | **Partial** | `live=all` and date calls batch; repair/events/lineups are per fixture and zombie use is excessive. |
| 6 | Central, atomic daily quota budget | **Fail** | Row counts, inconsistent ceilings, race windows, retries omitted. |
| 7 | Per-workload budgets and deduplication | **Fail** | Only lineup uniqueness/daily timestamp; no shared allocations; repairs repeat. |
| 8 | Bounded retries with exponential backoff/jitter | **Fail** | Fixed 500 ms; queue retry multiplication; no jitter. |
| 9 | Explicit 429/`Retry-After` handling and circuit breaker | **Fail** | None found. |
| 10 | Observable physical attempts, outcomes, and provider usage | **Fail** | Logs only endpoint/date logical rows; no status/header/retry/outcome. |
| 11 | Scheduler overlap/concurrency safety | **Partial** | Some `withoutOverlapping`; live polling is neither unique nor overlap-protected; checks are non-atomic. |
| 12 | Football-only allowlist; all other sports hard-disabled | **Partial/Fail** | Schedules are off and basketball call path stubbed; tennis date call remains manually callable; no feature flag/allowlist. |

## Known unknowns

1. Exact wording of the owner's 12 architecture rules (missing input).
2. Provider dashboard snapshot timestamp, reset timezone, and whether 103 reflects the same subscription/account/key used by production.
3. API-Football response rate-limit headers and exact 429 contract; the official documentation page was inaccessible from the audit host, and no test request was made by instruction.
4. Number of physical HTTP attempts made today; current instrumentation cannot reconstruct retries.
5. Why provider responses were empty for many fixture-detail calls before about 11:00 and then began succeeding (plan activation/reset, provider availability, auth/account state, or another cause).
6. Detailed composition of 4.23 million failed queue rows; an aggregate scan timed out and no DB index/schema change was permitted.
7. Whether an upstream Plesk scheduler outside the application user's crontab also invokes Laravel. The account crontab and visible processes were checked; system-level scheduler visibility may be restricted.
8. Exact future match volumes because fixture schedule ingestion is paused and current future DB coverage is sparse.
9. API-Football plan behavior for retries and failed/unauthorized requests; provider dashboard/header evidence is needed.

## Implementation plan (do not implement yet)

### P0 — quota integrity and stop runaway use

1. Introduce one football gateway used by every caller; remove direct provider HTTP construction from commands.
2. Count/reserve each physical attempt atomically before send; store endpoint class, caller, status, outcome, attempt, duration, and safe rate-limit header values. Never store secrets or full sensitive headers.
3. Implement global 5,250/6,375/7,125 threshold state plus endpoint sub-budgets and a provider reset timestamp.
4. Handle 429 explicitly: no blind retry, honor `Retry-After`/reset when valid, open a circuit, and prevent queue retry storms. Retry only bounded transient failures with exponential backoff and jitter.
5. Temporarily redesign zombie/finalizer selection before leaving them at present cadence: strict date window, status correctness, per-fixture attempt cap, multi-hour exponential cooldown, terminal-error handling, and a combined 500/day repair budget. Add a finalizer per-run cap.
6. Make football the explicit allowlist and set basketball/tennis budgets to zero; manual commands must fail closed while disabled.
7. Resolve log/queue operational risk: rotate/compress application logs, rate-limit repetitive messages, and investigate/retire failed-job backlog using a separately approved maintenance plan.

### P1 — efficient, complete ingestion

1. Restore a quota-safe fixture-calendar pipeline using one date-batched call, limited horizon, deduped daily refresh, and accurate league/team bootstrap so live rows are not discarded for missing teams.
2. Change standings to choose the current season deterministically and normally issue one request per league, not three blind attempts.
3. Add request coalescing/locks for live polling and queue uniqueness; prevent a delayed 30-second job from overlapping the next poll.
4. Separate ingestion from normalization/persistence through provider DTOs/adapters; add contract tests for malformed/empty/error payloads.
5. Define lineup policy by priority league, kickoff window, response state, and retry cooldown; do not mark success before a successful/terminal response.
6. Build a read-only quota dashboard comparing internal physical attempts with provider headers/dashboard snapshots and alerting at 70/85/95%.

### P2 — resilience and governance

1. Add recorded-fixture integration tests, provider contract monitoring, scheduler load tests, and 429/timeout/5xx chaos tests.
2. Add daily reconciliation, anomaly detection by endpoint/caller, and forecasted end-of-day usage.
3. Document operator runbooks for plan expiry, quota exhaustion, provider outage, manual backfill allocation, and sport enablement.
4. Add retention/indexing/partition strategy for API-call and failed-job telemetry after a separately approved DB-change plan.

## Bottom line

The application already follows the most important serving principle: user page traffic does not call API-Football, and live scores are ingested in one batched request. However, the active repair loops, unbounded finalizer selection, fragmented clients, retry multiplication, and non-physical request counter mean the current apparent headroom cannot be trusted. Implement P0 accounting/circuit-breaking and tame fixture repair before enabling fixture backfills, lineups at scale, or any non-football sport.
