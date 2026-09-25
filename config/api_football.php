<?php

return [
    'enabled_sports' => array_values(array_filter(array_map('trim', explode(',', (string) env('SPORT_PROVIDER_ALLOWLIST', 'football'))))),
    'base_url' => 'https://v3.football.api-sports.io',
    'timeout' => (int) env('API_FOOTBALL_TIMEOUT', 15),
    'max_attempts' => 2,
    'retry_base_ms' => (int) env('API_FOOTBALL_RETRY_BASE_MS', 250),
    'daily_limit' => 7500,
    'thresholds' => [
        'warn' => 5250,
        'critical' => 6375,
        'hard_stop' => 7125,
    ],
    'budgets' => [
        'live' => 3000,
        'fixture_repair' => 500,
        'lineups' => 250,
        'standings' => 60,
        // Hard physical-attempt cap. Each retry reserves another attempt.
        'calendar' => 80,
        'manual' => (int) env('API_FOOTBALL_BUDGET_MANUAL', 0),
        'backfill' => (int) env('API_FOOTBALL_BUDGET_BACKFILL', 0),
        'events' => (int) env('API_FOOTBALL_BUDGET_EVENTS', 0),
        'scorers' => (int) env('API_FOOTBALL_BUDGET_SCORERS', 0),
    ],
    'calendar' => [
        // Both scheduler registration and the provider service path fail closed
        // unless this explicit deployment gate is enabled.
        'enabled' => env('API_FOOTBALL_CALENDAR_ENABLED', false),
        'lock_store' => env('API_FOOTBALL_CALENDAR_LOCK_STORE', 'redis'),
        'lock_seconds' => 7200,
        // Wednesday 03:15 UTC is the documented low-traffic weekly slot.
        'weekly_day' => 3,
        'weekly_time' => '03:15',
    ],
    'repair' => [
        'finalizer_per_run' => (int) env('API_FOOTBALL_FINALIZER_PER_RUN', 5),
        'zombie_per_run' => (int) env('API_FOOTBALL_ZOMBIE_PER_RUN', 5),
        'max_attempts_per_fixture' => (int) env('API_FOOTBALL_REPAIR_MAX_ATTEMPTS', 4),
        'base_cooldown_seconds' => (int) env('API_FOOTBALL_REPAIR_COOLDOWN', 7200),
    ],
];
