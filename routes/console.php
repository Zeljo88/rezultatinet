<?php
use Illuminate\Support\Facades\Schedule;

Schedule::job(\App\Jobs\FetchLiveFixtures::class)
    ->everyFifteenMinutes()
    ->between('10:00', '01:00');
