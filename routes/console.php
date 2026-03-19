<?php
use App\Jobs\FetchLiveFixtures;
use Illuminate\Support\Facades\Schedule;

// Fetch live scores every 30 seconds (API-Sports PRO plan)
Schedule::job(new FetchLiveFixtures)
    ->everyThirtySeconds();
    

// Sync today's fixtures every 2 hours
Schedule::command('sync:fixtures')
    ->everyTwoHours();

// Sync tomorrow's fixtures daily at 6am
// Backfill last 14 days of fixtures (for team pages history)
// Runs daily at 4am, uses ~14 API calls
Schedule::call(function() {
    for ($i = 1; $i <= 14; $i++) {
        if (\App\Models\ApiCallLog::getTodayCount() >= 70) break;
        \Illuminate\Support\Facades\Artisan::call('sync:fixtures', [
            '--date' => now()->subDays($i)->format('Y-m-d')
        ]);
    }
})->dailyAt('04:00')->name('backfill-fixtures');

Schedule::command('sync:fixtures --date=' . now()->addDay()->format('Y-m-d'))
    ->dailyAt('06:00');

// Facebook: pre-match post at 10:00 (dry-run until token is configured)
Schedule::command('facebook:pre-match --dry-run')
    ->dailyAt('10:00')
    ->name('facebook-pre-match');

// Facebook: post-match results at 23:30
Schedule::command('facebook:post-match --dry-run')
    ->dailyAt('23:30')
    ->name('facebook-post-match');
