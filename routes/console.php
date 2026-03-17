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
Schedule::command('sync:fixtures --date=' . now()->addDay()->format('Y-m-d'))
    ->dailyAt('06:00');
