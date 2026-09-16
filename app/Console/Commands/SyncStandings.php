<?php

namespace App\Console\Commands;

use App\Models\League;
use App\Models\Standing;
use App\Models\Team;
use App\Services\ApiFootballService;
use Illuminate\Console\Command;

class SyncStandings extends Command
{
    protected $signature = 'sync:standings {--league= : API league ID}';

    protected $description = 'Sync standings for leagues from API-Football';

    protected array $leagueIds = [210, 286, 315, 316, 39, 140, 135, 78, 61, 2, 3, 848, 287, 211];

    // Seasons to try in order
    protected array $seasons = [2025, 2024, 2026];

    public function handle(ApiFootballService $api): void
    {
        $specificLeague = $this->option('league');
        $ids = $specificLeague ? [(int) $specificLeague] : $this->leagueIds;

        foreach ($ids as $apiLeagueId) {

            $league = League::where('api_league_id', $apiLeagueId)->first();
            if (! $league) {
                $this->warn("League {$apiLeagueId} not found");

                continue;
            }

            $standingsData = [];
            $usedSeason = null;

            foreach ($this->seasons as $season) {
                $provider = $api->getStandings($apiLeagueId, $season, 'SyncStandings');
                $data = $provider[0]['league']['standings'][0] ?? [];
                if (! empty($data)) {
                    $standingsData = $data;
                    $usedSeason = $season;
                    break;
                }
            }

            if (empty($standingsData)) {
                $this->warn("No standings found for {$league->name}");

                continue;
            }

            foreach ($standingsData as $row) {
                $team = Team::updateOrCreate(
                    ['api_team_id' => $row['team']['id']],
                    ['name' => $row['team']['name'], 'logo_url' => $row['team']['logo'] ?? null]
                );

                Standing::updateOrCreate(
                    ['league_id' => $league->id, 'team_id' => $team->id, 'season' => $usedSeason],
                    [
                        'rank' => $row['rank'],
                        'played' => $row['all']['played'],
                        'win' => $row['all']['win'],
                        'draw' => $row['all']['draw'],
                        'lose' => $row['all']['lose'],
                        'goals_for' => $row['all']['goals']['for'],
                        'goals_against' => $row['all']['goals']['against'],
                        'goal_diff' => $row['goalsDiff'],
                        'points' => $row['points'],
                        'form' => $row['form'] ?? null,
                        'description' => $row['description'] ?? null,
                        'home_played' => $row['home']['played'] ?? 0,
                        'home_win' => $row['home']['win'] ?? 0,
                        'home_draw' => $row['home']['draw'] ?? 0,
                        'home_lose' => $row['home']['lose'] ?? 0,
                        'home_goals_for' => $row['home']['goals']['for'] ?? 0,
                        'home_goals_against' => $row['home']['goals']['against'] ?? 0,
                        'away_played' => $row['away']['played'] ?? 0,
                        'away_win' => $row['away']['win'] ?? 0,
                        'away_draw' => $row['away']['draw'] ?? 0,
                        'away_lose' => $row['away']['lose'] ?? 0,
                        'away_goals_for' => $row['away']['goals']['for'] ?? 0,
                        'away_goals_against' => $row['away']['goals']['against'] ?? 0,
                    ]
                );
            }

            // Remove standings from other seasons for this league
            Standing::where('league_id', $league->id)
                ->where('season', '!=', $usedSeason)
                ->delete();

            $this->info("✓ {$league->name} (season {$usedSeason}): ".count($standingsData).' teams');
        }
    }
}
