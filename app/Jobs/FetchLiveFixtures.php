<?php
namespace App\Jobs;

use App\Events\LiveScoreUpdated;
use App\Models\ApiCallLog;
use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\FixtureLineup;
use App\Models\FixtureScore;
use App\Models\League;
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
        if (ApiCallLog::getTodayCount() >= 7500) {
            Log::warning('API daily budget reached, skipping poll');
            return;
        }

        $fixtures = $api->getLiveFixtures();
        ApiCallLog::create(['endpoint' => '/fixtures?live=all', 'called_date' => today()]);

        foreach ($fixtures as $data) {
            $league = League::where('api_league_id', $data['league']['id'])->first();
            if (!$league) {
                Log::warning('FetchLiveFixtures: unknown league api_id=' . $data['league']['id'] . ', skipping fixture api_id=' . $data['fixture']['id']);
                continue;
            }

            $homeTeam = Team::where('api_team_id', $data['teams']['home']['id'])->first();
            $awayTeam = Team::where('api_team_id', $data['teams']['away']['id'])->first();

            // Guard: skip fixture if team data is missing (API returned incomplete data
            // or team not in our DB). Prevents SQLSTATE[23000] NULL constraint crash.
            if (!$homeTeam || !$awayTeam) {
                Log::warning('FetchLiveFixtures: skipping fixture api_id=' . $data['fixture']['id']
                    . ' league_id=' . $league->id
                    . ' — missing team data (home_api_id=' . ($data['teams']['home']['id'] ?? 'null')
                    . ', away_api_id=' . ($data['teams']['away']['id'] ?? 'null') . ')'
                    . ' home_found=' . ($homeTeam ? 'yes' : 'no')
                    . ' away_found=' . ($awayTeam ? 'yes' : 'no'));
                continue;
            }

            $fixture = Fixture::updateOrCreate(
                ['api_fixture_id' => $data['fixture']['id']],
                [
                    'status_long'    => $data['fixture']['status']['long'] ?? null,
                    'status_short'   => $data['fixture']['status']['short'] ?? null,
                    'elapsed_minute' => $data['fixture']['status']['elapsed'] ?? null,
                    'elapsed_extra'  => $data['fixture']['status']['extra'] ?: null,
                    'season'         => $data['league']['season'],
                    'league_id'      => $league->id,
                    'home_team_id'   => $homeTeam->id,
                    'away_team_id'   => $awayTeam->id,
                    'kick_off'       => $data['fixture']['date'] ?? null,
                ]
            );

            // goals = current live score (includes ET/PEN goals)
            // BUG FIX: also save extratime and penalty scores when available
            FixtureScore::updateOrCreate(
                ['fixture_id' => $fixture->id],
                [
                    'goals_home'      => $data['goals']['home'],
                    'goals_away'      => $data['goals']['away'],
                    'home_halftime'   => $data['score']['halftime']['home'] ?? null,
                    'away_halftime'   => $data['score']['halftime']['away'] ?? null,
                    'home_fulltime'   => $data['score']['fulltime']['home'] ?? null,
                    'away_fulltime'   => $data['score']['fulltime']['away'] ?? null,
                    // Save ET and penalty scores as they become available during live polling
                    'home_extratime'  => $data['score']['extratime']['home'] ?? null,
                    'away_extratime'  => $data['score']['extratime']['away'] ?? null,
                    'home_penalties'  => $data['score']['penalty']['home'] ?? null,
                    'away_penalties'  => $data['score']['penalty']['away'] ?? null,
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
                        'elapsed_extra'  => $event['time']['extra'] ?: null,
                    ]);
                }
            }

            // Dispatch lineup fetch only for top leagues + within 60 min of kickoff (or already live)
            // This prevents burning API quota on lineup fetches for minor leagues / distant matches
            $topLeagueIds = [2, 3, 848, 39, 140, 135, 78, 61, 210, 286, 315];
            $isTopLeague  = in_array($league->api_league_id, $topLeagueIds);

            $isLive       = in_array($fixture->status_short, ['1H', 'HT', '2H', 'ET', 'P', 'BT']);
            $kickoffSoon  = $fixture->kick_off && now()->diffInMinutes($fixture->kick_off, false) <= 60;

            $needsLineups = !$fixture->lineups_fetched_at || !$fixture->lineups_fetched_at->isToday();

            if ($needsLineups && $fixture->api_fixture_id && $isTopLeague && ($isLive || $kickoffSoon)) {
                FetchFixtureLineups::dispatch($fixture->id, $fixture->api_fixture_id);
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
