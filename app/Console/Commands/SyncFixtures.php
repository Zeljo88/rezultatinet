<?php

namespace App\Console\Commands;

use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Models\League;
use App\Models\Team;
use App\Services\ApiFootballService;
use Illuminate\Console\Command;

class SyncFixtures extends Command
{
    protected $signature = 'sync:fixtures {--date= : Date in Y-m-d format}';

    protected $description = 'Sync fixtures from API-Football for a given date';

    public function handle(ApiFootballService $api): void
    {
        $date = $this->option('date') ?? now()->format('Y-m-d');
        $this->info("Fetching fixtures for {$date}...");

        $fixtures = $api->getFixturesByDate($date, 'SyncFixtures');
        $count = 0;

        foreach ($fixtures as $data) {
            $league = League::where('api_league_id', $data['league']['id'])->first();
            if (! $league) {
                continue;
            }

            $homeTeam = Team::updateOrCreate(
                ['api_team_id' => $data['teams']['home']['id']],
                ['name' => $data['teams']['home']['name'], 'logo_url' => $data['teams']['home']['logo'] ?? null]
            );

            $awayTeam = Team::updateOrCreate(
                ['api_team_id' => $data['teams']['away']['id']],
                ['name' => $data['teams']['away']['name'], 'logo_url' => $data['teams']['away']['logo'] ?? null]
            );

            $fixture = Fixture::updateOrCreate(
                ['api_fixture_id' => $data['fixture']['id']],
                [
                    'league_id' => $league->id,
                    'home_team_id' => $homeTeam->id,
                    'away_team_id' => $awayTeam->id,
                    'season' => $data['league']['season'],
                    'round' => $data['league']['round'] ?? null,
                    'kick_off' => date('Y-m-d H:i:s', $data['fixture']['timestamp']),
                    'status_long' => $data['fixture']['status']['long'] ?? null,
                    'status_short' => $data['fixture']['status']['short'] ?? null,
                    'elapsed_minute' => $data['fixture']['status']['elapsed'] ?? null,
                    'venue_name' => $data['fixture']['venue']['name'] ?? null,
                    'referee' => $data['fixture']['referee'] ?? null,
                ]
            );

            // goals = current live score; fulltime = final score (null during match)
            $goalsHome = $data['goals']['home'];
            $goalsAway = $data['goals']['away'];
            $fulltimeHome = $data['score']['fulltime']['home'];
            $fulltimeAway = $data['score']['fulltime']['away'];
            $halftimeHome = $data['score']['halftime']['home'] ?? 0;
            $halftimeAway = $data['score']['halftime']['away'] ?? 0;

            FixtureScore::updateOrCreate(
                ['fixture_id' => $fixture->id],
                [
                    'goals_home' => $goalsHome,
                    'goals_away' => $goalsAway,
                    'home_fulltime' => $fulltimeHome,
                    'away_fulltime' => $fulltimeAway,
                    'home_halftime' => $halftimeHome,
                    'away_halftime' => $halftimeAway,
                ]
            );

            $count++;
        }

        $this->info("Synced {$count} fixtures for {$date}.");
    }
}
