<?php
namespace App\Jobs;

use App\Events\LiveScoreUpdated;
use App\Models\ApiCallLog;
use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\FixtureScore;
use App\Models\Team;
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
        if (ApiCallLog::getTodayCount() >= 7000) {
            Log::warning('API daily budget reached, skipping poll');
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
                    'elapsed_extra'  => $data['fixture']['status']['extra'] ?? null,
                ]
            );

            // goals = current live score, fulltime = final (null during match)
            FixtureScore::updateOrCreate(
                ['fixture_id' => $fixture->id],
                [
                    'goals_home'    => $data['goals']['home'],
                    'goals_away'    => $data['goals']['away'],
                    'home_fulltime' => $data['score']['fulltime']['home'],
                    'away_fulltime' => $data['score']['fulltime']['away'],
                    'home_halftime' => $data['score']['halftime']['home'] ?? null,
                    'away_halftime' => $data['score']['halftime']['away'] ?? null,
                ]
            );

            if (!empty($data['events'])) {
                FixtureEvent::where('fixture_id', $fixture->id)->delete();
                foreach ($data['events'] as $event) {
                    $team = Team::where('api_team_id', $event['team']['id'] ?? 0)->first();
                    FixtureEvent::create([
                        'fixture_id'     => $fixture->id,
                        'team_id'        => $team?->id,
                        'player_name'    => $event['player']['name'] ?? null,
                        'assist_name'    => $event['assist']['name'] ?? null,
                        'type'           => $event['type'] ?? 'Goal',
                        'detail'         => $event['detail'] ?? null,
                        'elapsed_minute' => $event['time']['elapsed'] ?? null,
                        'elapsed_extra'  => $event['time']['extra'] ?? null,
                    ]);
                }
            }

            // Broadcast score update — wrapped in try/catch so a Reverb failure
            // does NOT prevent DB scores from being saved
            try {
                broadcast(new LiveScoreUpdated($fixture->fresh(['score','homeTeam','awayTeam'])));
            } catch (\Throwable $broadcastError) {
                Log::warning('Broadcast failed (scores already saved): ' . $broadcastError->getMessage());
            }
        }
    }
}
