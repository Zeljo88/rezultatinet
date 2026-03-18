<?php
use App\Jobs\FetchLiveFixtures;
use Illuminate\Support\Facades\Schedule;

// Fetch live scores every 15 min during match hours
Schedule::job(new FetchLiveFixtures)
    ->everyFifteenMinutes()
    ->between('10:00', '01:00');

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
