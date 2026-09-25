<?php

namespace App\Console\Commands;

use App\Exceptions\ApiFootballBlocked;
use App\Services\ApiFootballService;
use App\Services\FixtureCalendarImporter;
use App\Support\FixtureCalendarWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncFixtureCalendar extends Command
{
    protected $signature = 'sync:fixture-calendar {--window=near : near (D+0..1), week (D+2..7), or month (D+8..30)}';

    protected $description = 'Synchronize future football fixtures through the quota-controlled calendar path';

    public function handle(ApiFootballService $api, FixtureCalendarImporter $importer): int
    {
        if (! config('api_football.calendar.enabled', false)) {
            $this->warn('Future fixture calendar ingestion is disabled.');

            return self::FAILURE;
        }

        $window = (string) $this->option('window');
        try {
            $dates = FixtureCalendarWindow::dates($window);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        try {
            $lock = Cache::store((string) config('api_football.calendar.lock_store', 'redis'))
                ->lock($this->lockName(), (int) config('api_football.calendar.lock_seconds', 7200));
            if (! $lock->get()) {
                Log::channel('api_football')->notice('calendar_sync_overlap_skipped', ['window' => $window]);
                $this->warn('Another fixture calendar sync is already running.');

                return self::SUCCESS;
            }
        } catch (Throwable) {
            Log::channel('api_football')->error('calendar_sync_lock_unavailable', ['window' => $window]);
            $this->error('Calendar single-flight state is unavailable; sync denied.');

            return self::FAILURE;
        }

        $summary = ['upserted' => 0, 'protected' => 0, 'skipped' => 0];
        try {
            foreach ($dates as $date) {
                $current = $importer->import($api->getCalendarFixturesByDate($date));
                foreach ($summary as $key => $value) {
                    $summary[$key] += $current[$key];
                }
            }
        } catch (ApiFootballBlocked $e) {
            Log::channel('api_football')->warning('calendar_sync_blocked', [
                'window' => $window,
                'reason' => $e->reason->value,
                ...$summary,
            ]);
            $this->error('Calendar provider path was blocked: '.$e->reason->value);

            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            Log::channel('api_football')->error('calendar_sync_failed', ['window' => $window, ...$summary]);
            $this->error('Calendar sync failed.');

            return self::FAILURE;
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // The finite lease remains the fail-safe when release is unavailable.
            }
        }

        Log::channel('api_football')->info('calendar_sync_completed', [
            'window' => $window,
            'dates' => count($dates),
            ...$summary,
        ]);
        $this->info(sprintf(
            'Calendar %s: %d dates, %d upserted, %d protected, %d skipped.',
            $window,
            count($dates),
            $summary['upserted'],
            $summary['protected'],
            $summary['skipped'],
        ));

        return self::SUCCESS;
    }

    private function lockName(): string
    {
        return 'api-football:calendar-sync:'.hash('sha256', strtolower(trim(
            (string) config('app.name', 'laravel')."\0".(string) config('app.env', 'production'),
        )));
    }
}
