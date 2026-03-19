<?php
use App\Jobs\FetchLiveFixtures;
use Illuminate\Support\Facades\Schedule;

// Fetch live scores every 30 seconds (API-Sports PRO plan)
Schedule::job(new FetchLiveFixtures)
    ->everyThirtySeconds();
    

// Sync today's fixtures every 2 hours
Schedule::command('sync:fixtures')
    ->everyTwoHours();

// Backfill last 14 days of fixtures (for team pages history)
// Runs daily at 4am, uses ~14 API calls
Schedule::call(function() {
    for ($i = 1; $i <= 14; $i++) {
        if (\App\Models\ApiCallLog::getTodayCount() >= 6500) break;
        \Illuminate\Support\Facades\Artisan::call('sync:fixtures', [
            '--date' => now()->subDays($i)->format('Y-m-d')
        ]);
    }
})->dailyAt('04:00')->name('backfill-fixtures');

// Sync next 7 days of fixtures daily at 6:30am
Schedule::call(function() {
    for ($i = 0; $i <= 7; $i++) {
        if (\App\Models\ApiCallLog::getTodayCount() >= 6500) break;
        \Illuminate\Support\Facades\Artisan::call('sync:fixtures', [
            '--date' => now()->addDays($i)->format('Y-m-d')
        ]);
    }
})->dailyAt('06:30')->name('sync-future-fixtures');

// Facebook: pre-match post at 10:00 (dry-run until token is configured)
Schedule::command('facebook:pre-match --dry-run')
    ->dailyAt('10:00')
    ->name('facebook-pre-match');

// Facebook: post-match results at 23:30
Schedule::command('facebook:post-match --dry-run')
    ->dailyAt('23:30')
    ->name('facebook-post-match');

// Backfill missing events for finished matches every hour
Schedule::command('sync:events-backfill')->hourly();

// Sync top scorers weekly (Monday 3am)
Schedule::command('sync:top-scorers')->weeklyOn(1, '03:00')->name('sync-top-scorers');

// Sync match lineups every 30 minutes
Schedule::command('sync:lineups')->everyThirtyMinutes()->name('sync-lineups');
