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

class FinalizeFinishedFixtures implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'finalize-finished-fixtures';
    }

    public function handle(ApiFootballService $api, ApiFootballQuotaStore $quota): void
    {
        $limit = max(1, (int) config('api_football.repair.finalizer_per_run', 5));
        $fixtures = Fixture::where(function ($q) {
            $q->whereIn('status_short', ['2H', 'ET'])->where('elapsed_minute', '>=', 88)
                ->where('updated_at', '<', now()->subMinutes(15));
        })->orWhere(function ($q) {
            $q->where('status_short', 'P')->where('updated_at', '<', now()->subMinutes(60));
        })->orWhere(function ($q) {
            $q->whereIn('status_short', ['PEN', 'AET'])->where('updated_at', '<', now()->subHours(2))
                ->where('kick_off', '>=', now()->subHours(6));
        })->orderBy('updated_at')->limit($limit * 4)->get();

        $checked = $updated = 0;
        foreach ($fixtures as $fixture) {
            if ($checked >= $limit || ! $fixture->api_fixture_id || ! $quota->acquireRepair($fixture->id, 'FinalizeFinishedFixtures')) {
                continue;
            }
            $checked++;
            try {
                $data = $api->getFixtureById($fixture->api_fixture_id, 'FinalizeFinishedFixtures');
            } catch (ApiFootballBlocked) {
                $quota->recordRepair($fixture->id, 'quota_blocked');
                break;
            }

            if (empty($data)) {
                $quota->recordRepair($fixture->id, 'empty');

                continue;
            }
            $status = $data['fixture']['status']['short'] ?? null;
            if (! in_array($status, ['FT', 'AET', 'PEN'], true)) {
                $quota->recordRepair($fixture->id, 'still_'.$status);

                continue;
            }

            $fixture->update([
                'status_short' => $status,
                'status_long' => $data['fixture']['status']['long'] ?? null,
                'elapsed_minute' => $data['fixture']['status']['elapsed'] ?? null,
            ]);
            FixtureScore::updateOrCreate(['fixture_id' => $fixture->id], [
                'goals_home' => $data['goals']['home'] ?? null, 'goals_away' => $data['goals']['away'] ?? null,
                'home_halftime' => $data['score']['halftime']['home'] ?? null, 'away_halftime' => $data['score']['halftime']['away'] ?? null,
                'home_fulltime' => $data['score']['fulltime']['home'] ?? null, 'away_fulltime' => $data['score']['fulltime']['away'] ?? null,
                'home_extratime' => $data['score']['extratime']['home'] ?? null, 'away_extratime' => $data['score']['extratime']['away'] ?? null,
                'home_penalties' => $data['score']['penalty']['home'] ?? null, 'away_penalties' => $data['score']['penalty']['away'] ?? null,
            ]);
            $quota->recordRepair($fixture->id, 'finalized_'.$status, true);
            $updated++;
        }
        if ($checked || $updated) {
            Log::channel('api_football')->info('repair_finalizer_run', compact('checked', 'updated', 'limit'));
        }
    }
}
