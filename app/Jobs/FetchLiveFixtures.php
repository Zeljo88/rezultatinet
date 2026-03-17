<?php
namespace App\Jobs;

use App\Events\LiveScoreUpdated;
use App\Models\ApiCallLog;
use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Services\ApiFootballService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchLiveFixtures implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ApiFootballService $api): void
    {
        if (ApiCallLog::getTodayCount() >= 80) {
            Log::warning('API daily budget reached (80), skipping poll');
            return;
        }

        $fixtures = $api->getLiveFixtures();
        ApiCallLog::create(['endpoint' => '/fixtures?live=all', 'called_date' => today()]);

        foreach ($fixtures as $data) {
            $fixture = Fixture::updateOrCreate(
                ['api_fixture_id' => $data['fixture']['id']],
                [
                    'status_long'    => $data['fixture']['status']['long'] ?? null,
                    'status_short'   => $data['fixture']['status']['short'] ?? null,
                    'elapsed_minute' => $data['fixture']['status']['elapsed'] ?? null,
                ]
            );

            FixtureScore::updateOrCreate(
                ['fixture_id' => $fixture->id],
                [
                    'home_fulltime' => $data['score']['fulltime']['home'] ?? 0,
                    'away_fulltime' => $data['score']['fulltime']['away'] ?? 0,
                    'home_halftime' => $data['score']['halftime']['home'] ?? 0,
                    'away_halftime' => $data['score']['halftime']['away'] ?? 0,
                ]
            );

            broadcast(new LiveScoreUpdated($fixture->fresh(['score','homeTeam','awayTeam'])));
        }
    }
}
