# NOT REGISTERED / NOT ACTIVATED

After separate migration, engine proof, season expiry policy, deployment and recurring-provider authority only:

```php
Schedule::command('hnl:standings-refresh --execute')->dailyAt('04:45')->timezone('UTC');
```

Do not paste into deployed routes now. Fixed UTC avoids DST slot drift and follows other-league 04:30 work; Zagreb presentation remains unchanged. No queue, daemon, job retries or global 86400s mutex. Service owns a 120s league-wide Redis mutex plus DB league-first serialization. Cooperative 60s deadline checks are not hard process termination; real-engine/worker-death gate remains mandatory. Ledger unique league/UTC-day includes manual executions and every failed/ambiguous claim. At most 1 request/day, 28–31/month, 365/non-leap year (366/leap year). No catch-up bursts. Preserve nominal7500/hard7125/standings60/calendar80/breaker/job budgets.
