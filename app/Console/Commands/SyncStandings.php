<?php
namespace App\Console\Commands;

use App\Models\ApiCallLog;
use App\Models\League;
use App\Models\Standing;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncStandings extends Command
{
    protected $signature = 'sync:standings {--league= : API league ID}';
    protected $description = 'Sync standings for leagues from API-Football';

    protected array $leagueIds = [210, 286, 315, 39, 140, 135, 78, 61, 2, 3, 848, 287, 211];

    // Seasons to try in order
    protected array $seasons = [2024, 2025, 2023];

    public function handle(): void
    {
        $specificLeague = $this->option('league');
        $ids = $specificLeague ? [(int)$specificLeague] : $this->leagueIds;

        foreach ($ids as $apiLeagueId) {
            if (ApiCallLog::getTodayCount() >= 85) {
                $this->error('Daily API budget reached.');
                return;
            }

            $league = League::where('api_league_id', $apiLeagueId)->first();
            if (!$league) { $this->warn("League {$apiLeagueId} not found"); continue; }

            $standingsData = [];
            $usedSeason = null;

            foreach ($this->seasons as $season) {
                $response = Http::withHeaders([
                    'X-RapidAPI-Key'  => config('services.api_football.key'),
                    'X-RapidAPI-Host' => 'v3.football.api-sports.io',
                ])->get('https://v3.football.api-sports.io/standings', [
                    'league' => $apiLeagueId,
                    'season' => $season,
                ]);

                ApiCallLog::create(['endpoint' => "/standings?league={$apiLeagueId}&season={$season}", 'called_date' => today()]);

                $data = $response->json('response.0.league.standings.0', []);
                if (!empty($data)) {
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
                        'rank'          => $row['rank'],
                        'played'        => $row['all']['played'],
                        'win'           => $row['all']['win'],
                        'draw'          => $row['all']['draw'],
                        'lose'          => $row['all']['lose'],
                        'goals_for'     => $row['all']['goals']['for'],
                        'goals_against' => $row['all']['goals']['against'],
                        'goal_diff'     => $row['goalsDiff'],
                        'points'        => $row['points'],
                        'form'          => $row['form'] ?? null,
                        'description'   => $row['description'] ?? null,
                    ]
                );
            }

            $this->info("✓ {$league->name} (season {$usedSeason}): " . count($standingsData) . " teams");
        }
    }
}
