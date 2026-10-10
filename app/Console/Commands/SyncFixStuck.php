<?php

namespace App\Console\Commands;

use App\Contracts\ApiFootballQuotaStore;
use App\Exceptions\ApiFootballBlocked;
use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Services\ApiFootballService;
use App\Support\FootballFixtureStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncFixStuck extends Command
{
    protected $signature = 'sync:fix-stuck {--dry-run : Show candidates without provider calls}';

    protected $description = 'Repair fixtures stuck in live status (manual budget defaults to zero)';

    public function handle(ApiFootballService $api, ApiFootballQuotaStore $quota): int
    {
        $fixtures = Fixture::whereIn('status_short', ['1H', '2H', 'HT', 'ET', 'P'])
            ->where('updated_at', '<', now()->subHours(2))->limit(25)->get();
        if ($this->option('dry-run')) {
            $this->table(['local_id', 'api_fixture_id', 'status'], $fixtures->map(fn ($f) => [$f->id, $f->api_fixture_id, $f->status_short]));

            return self::SUCCESS;
        }

        foreach ($fixtures as $fixture) {
            if (! $quota->acquireRepair($fixture->id, 'SyncFixStuck')) {
                continue;
            }
            // Preserve score-only changes made while the provider request is in flight.
            $selectedScore = FixtureScore::where('fixture_id', $fixture->id)->first()?->getRawOriginal();
            try {
                $data = $api->getFixtureById($fixture->api_fixture_id, 'SyncFixStuck', 'manual');
            } catch (ApiFootballBlocked $e) {
                $quota->recordRepair($fixture->id, 'manual_blocked');
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            if (! $data) {
                $quota->recordRepair($fixture->id, 'empty');

                continue;
            }
            $persisted = DB::transaction(function () use ($fixture, $selectedScore, $data): bool {
                $locked = Fixture::whereKey($fixture->id)->lockForUpdate()->first();
                if (! $locked || $locked->getRawOriginal() !== $fixture->getRawOriginal()
                    || ! FootballFixtureStatus::canPersistLive($locked->status_short, $data['fixture']['status']['short'] ?? null, $locked->elapsed_minute, $data['fixture']['status']['elapsed'] ?? null)) {
                    return false;
                }
                $lockedScore = FixtureScore::where('fixture_id', $locked->id)->lockForUpdate()->first();
                if ($lockedScore?->getRawOriginal() !== $selectedScore) {
                    return false;
                }
                $fixture = $locked;
                $fixture->update(['status_short' => $data['fixture']['status']['short'] ?? null,
                    'status_long' => $data['fixture']['status']['long'] ?? null, 'elapsed_minute' => $data['fixture']['status']['elapsed'] ?? null]);
                FixtureScore::updateOrCreate(['fixture_id' => $fixture->id], [
                    'goals_home' => $data['goals']['home'] ?? null, 'goals_away' => $data['goals']['away'] ?? null,
                    'home_halftime' => $data['score']['halftime']['home'] ?? null, 'away_halftime' => $data['score']['halftime']['away'] ?? null,
                    'home_fulltime' => $data['score']['fulltime']['home'] ?? null, 'away_fulltime' => $data['score']['fulltime']['away'] ?? null,
                ]);

                return true;
            }, attempts: 3);
            if (! $persisted) {
                $quota->releaseRepair($fixture->id);

                continue;
            }
            $quota->recordRepair($fixture->id, 'manual_updated', true);
        }

        return self::SUCCESS;
    }
}
