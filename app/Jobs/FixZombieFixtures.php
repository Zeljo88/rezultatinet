<?php

namespace App\Jobs;

use App\Contracts\ApiFootballQuotaStore;
use App\Exceptions\ApiFootballBlocked;
use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Services\ApiFootballService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FixZombieFixtures implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    private const FINAL = ['FT', 'AET', 'PEN', 'AWD', 'WO', 'CANC', 'ABD', 'PST', 'INT', 'SUSP', 'TBD', 'NS'];

    public function uniqueId(): string
    {
        return 'fix-zombie-fixtures';
    }

    public function handle(ApiFootballService $api, ApiFootballQuotaStore $quota): void
    {
        $hour = (int) now('UTC')->format('G');
        if ($hour >= 1 && $hour < 7) {
            $recentLive = Fixture::whereIn('status_short', ['1H', 'HT', '2H', 'ET', 'P', 'BT'])
                ->where('kick_off', '>=', now()->subHours(4))->where('updated_at', '>=', now()->subMinutes(20))->exists();
            if (! $recentLive) {
                return;
            }
        }

        $limit = max(1, (int) config('api_football.repair.zombie_per_run', 5));
        $fixtures = Fixture::where('kick_off', '<', now()->subHours(3))->where('kick_off', '>=', now()->subDays(2))
            ->whereNotIn('status_short', self::FINAL)->orderByDesc('kick_off')->limit($limit * 6)->get();
        $checked = $repaired = 0;
        foreach ($fixtures as $fixture) {
            if ($checked >= $limit || ! $fixture->api_fixture_id || ! $quota->acquireRepair($fixture->id, 'FixZombieFixtures')) {
                continue;
            }
            $checked++;
            try {
                $data = $api->getFixtureById($fixture->api_fixture_id, 'FixZombieFixtures');
            } catch (ApiFootballBlocked) {
                $quota->recordRepair($fixture->id, 'quota_blocked');
                break;
            }
            if (empty($data)) {
                $quota->recordRepair($fixture->id, 'empty');

                continue;
            }

            $new = $data['fixture']['status']['short'] ?? null;
            if (! $new || $new === $fixture->status_short) {
                $quota->recordRepair($fixture->id, 'unchanged_'.$new);

                continue;
            }
            $fixture->update(['status_short' => $new, 'status_long' => $data['fixture']['status']['long'] ?? null,
                'elapsed_minute' => $data['fixture']['status']['elapsed'] ?? null]);
            FixtureScore::updateOrCreate(['fixture_id' => $fixture->id], [
                'goals_home' => $data['goals']['home'] ?? null, 'goals_away' => $data['goals']['away'] ?? null,
                'home_halftime' => $data['score']['halftime']['home'] ?? null, 'away_halftime' => $data['score']['halftime']['away'] ?? null,
                'home_fulltime' => $data['score']['fulltime']['home'] ?? null, 'away_fulltime' => $data['score']['fulltime']['away'] ?? null,
                'home_extratime' => $data['score']['extratime']['home'] ?? null, 'away_extratime' => $data['score']['extratime']['away'] ?? null,
                'home_penalties' => $data['score']['penalty']['home'] ?? null, 'away_penalties' => $data['score']['penalty']['away'] ?? null,
            ]);
            $terminal = in_array($new, self::FINAL, true);
            $quota->recordRepair($fixture->id, 'updated_'.$new, $terminal);
            $repaired++;
        }
        if ($checked || $repaired) {
            Log::channel('api_football')->info('repair_zombie_run', compact('checked', 'repaired', 'limit'));
        }
    }
}
