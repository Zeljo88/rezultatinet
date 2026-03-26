<?php
use App\Jobs\FetchLiveFixtures;
use App\Jobs\FinalizeFinishedFixtures;
use Illuminate\Support\Facades\Schedule;

// ✅ ACTIVE — Fetch live football scores every 30 seconds (~2,880/day)
Schedule::job(new FetchLiveFixtures)
    ->everyThirtySeconds();

// ✅ ACTIVE — Finalize fixtures stuck in 2H/ET after 15+ min (FT cleanup)
Schedule::job(new FinalizeFinishedFixtures)
    ->everyFiveMinutes()
    ->withoutOverlapping();

// ──────────────────────────────────────────────────────────────────────────────
// PAUSED — API kvota hitno. Sve jobove pausirati osim FetchLiveFixtures.
// Re-enable after implementing per-day dedup and smarter throttling.
// ──────────────────────────────────────────────────────────────────────────────

// ❌ PAUSED — sync:fixtures every 2h (uses API calls per date)
// Schedule::command('sync:fixtures')
//     ->everyTwoHours();

// ❌ PAUSED — backfill 14 days of fixtures at 04:00
// Schedule::call(function() {
//     for ($i = 1; $i <= 14; $i++) {
//         if (\App\Models\ApiCallLog::getTodayCount() >= 6500) break;
//         \Illuminate\Support\Facades\Artisan::call('sync:fixtures', [
//             '--date' => now()->subDays($i)->format('Y-m-d')
//         ]);
//     }
// })->dailyAt('04:00')->name('backfill-fixtures');

// ❌ PAUSED — sync next 7 days of fixtures at 06:30
// Schedule::call(function() {
//     for ($i = 0; $i <= 7; $i++) {
//         if (\App\Models\ApiCallLog::getTodayCount() >= 6500) break;
//         \Illuminate\Support\Facades\Artisan::call('sync:fixtures', [
//             '--date' => now()->addDays($i)->format('Y-m-d')
//         ]);
//     }
// })->dailyAt('06:30')->name('sync-future-fixtures');

// ❌ PAUSED — Facebook pre-match post
// Schedule::command('facebook:pre-match --dry-run')
//     ->dailyAt('10:00')
//     ->name('facebook-pre-match');

// ❌ PAUSED — Facebook post-match results
// Schedule::command('facebook:post-match --dry-run')
//     ->dailyAt('23:30')
//     ->name('facebook-post-match');

// ❌ PAUSED — events backfill every hour (MAIN CAUSE of 5000+ individual fixture calls)
// This runs hourly and calls getFixtureById for EVERY fixture without events.
// Re-enable only as dailyAt('03:00') with --days=1 limit.
// Schedule::command('sync:events-backfill')->hourly();

// ❌ PAUSED — top scorers weekly (low priority)
// Schedule::command('sync:top-scorers')->weeklyOn(1, '03:00')->name('sync-top-scorers');

// ❌ PAUSED — lineups every 30 min (1,100+ calls/day, filter not sufficient)
// Lineups are now dispatched only from FetchLiveFixtures for live top-league matches.
// Schedule::command('sync:lineups')->everyThirtyMinutes()->name('sync-lineups');

// ──────────────────────────────────────────────────────────────────────────────
// BASKETBALL — PAUSED (44 calls today, should be 0)
// ──────────────────────────────────────────────────────────────────────────────
// Schedule::call(function() {
//     $hasLive = \App\Models\BasketballGame::whereIn('status_short',
//         ['Q1','Q2','Q3','Q4','HT','OT','LIVE','BP','BT'])->exists();
//     if ($hasLive) {
//         \Illuminate\Support\Facades\Artisan::call('sync:basketball');
//     }
// })->everyFifteenMinutes()->name('basketball-live-check');

// Schedule::command('sync:basketball')->hourly()->name('basketball-hourly');

//// ──────────────────────────────────────────────────────────────────────────────
//// TENNIS — already paused
//// ──────────────────────────────────────────────────────────────────────────────
//Schedule::call(function() {
//    $liveStatuses = ['In Play','1st Set','2nd Set','3rd Set','4th Set','5th Set','Break Time'];
//    $hasLive = \App\Models\TennisMatch::whereIn('status', $liveStatuses)->exists();
//    if ($hasLive) {
//        \Illuminate\Support\Facades\Artisan::call('sync:tennis');
//    }
//})->everyFifteenMinutes()->name('tennis-live-check');
//
//Schedule::command('sync:tennis')->hourly()->name('tennis-hourly');

// ❌ PAUSED — fix stuck fixtures every 30 min
// Schedule::command('sync:fix-stuck')
//     ->everyThirtyMinutes()
//     ->name('sync-fix-stuck')
//     ->withoutOverlapping();
