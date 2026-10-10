<?php

namespace App\Console\Commands;

use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Models\League;
use App\Models\Team;
use App\Services\ApiFootballService;
use App\Support\FootballFixtureStatus;
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

            $existingFixture = Fixture::where('api_fixture_id', $data['fixture']['id'])->first();
            $incomingStatus = FootballFixtureStatus::normalize($data['fixture']['status']['short'] ?? null);
            $acceptProviderState = FootballFixtureStatus::canPersist(
                $existingFixture?->status_short,
                $incomingStatus,
            );
            $fixtureAttributes = [
                'league_id' => $league->id,
                'home_team_id' => $homeTeam->id,
                'away_team_id' => $awayTeam->id,
                'season' => $data['league']['season'],
                'round' => $data['league']['round'] ?? null,
                'kick_off' => date('Y-m-d H:i:s', $data['fixture']['timestamp']),
                'venue_name' => $data['fixture']['venue']['name'] ?? null,
                'referee' => $data['fixture']['referee'] ?? null,
            ];
            if ($acceptProviderState) {
                $fixtureAttributes += [
                    'status_long' => $data['fixture']['status']['long'] ?? null,
                    'status_short' => $incomingStatus,
                    'elapsed_minute' => $data['fixture']['status']['elapsed'] ?? null,
                ];
            }

            $fixture = Fixture::updateOrCreate(
                ['api_fixture_id' => $data['fixture']['id']],
                $fixtureAttributes,
            );

            if ($acceptProviderState) {
                // goals = current live score; fulltime = final score (null during match)
                FixtureScore::updateOrCreate(
                    ['fixture_id' => $fixture->id],
                    [
                        'goals_home' => $data['goals']['home'],
                        'goals_away' => $data['goals']['away'],
                        'home_fulltime' => $data['score']['fulltime']['home'],
                        'away_fulltime' => $data['score']['fulltime']['away'],
                        'home_halftime' => $data['score']['halftime']['home'] ?? 0,
                        'away_halftime' => $data['score']['halftime']['away'] ?? 0,
                    ]
                );
            }

            $count++;
        }

        $this->info("Synced {$count} fixtures for {$date}.");
    }
}
