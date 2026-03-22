<?php
namespace App\Console\Commands;

use App\Models\ApiCallLog;
use App\Models\League;
use App\Models\Player;
use App\Models\PlayerStat;
use App\Services\ApiFootballService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SyncTopScorers extends Command
{
    protected $signature = 'sync:top-scorers';
    protected $description = 'Sync top scorers for key leagues from API-Sports';

    protected array $leagueApiIds = [
        2,   // Champions League
        3,   // Europa League
        39,  // Premier League
        140, // La Liga
        135, // Serie A
        78,  // Bundesliga
        61,  // Ligue 1
        210, // HNL Croatia
        286, // SuperLiga Serbia
        315, // Premijer Liga BiH
    ];

    public function handle(ApiFootballService $api): void
    {
        foreach ($this->leagueApiIds as $apiLeagueId) {
            if (ApiCallLog::getTodayCount() >= 7400) {
                $this->warn('API budget reached, stopping');
                break;
            }

            $league = League::where('api_league_id', $apiLeagueId)->first();
            if (!$league) {
                $this->warn("League {$apiLeagueId} not found in DB");
                continue;
            }

            $response = $api->getTopScorers($apiLeagueId, 2024);
            ApiCallLog::create(['endpoint' => "/players/topscorers?league={$apiLeagueId}", 'called_date' => today()]);

            if (empty($response)) {
                $this->warn("No data for league {$apiLeagueId}");
                continue;
            }

            $count = 0;
            foreach ($response as $item) {
                $playerData = $item['player'] ?? [];
                $stats      = $item['statistics'][0] ?? [];

                if (empty($playerData['id'])) continue;

                $teamName = $stats['team']['name'] ?? null;
                $teamLogo = $stats['team']['logo'] ?? null;
                $name     = $playerData['name'] ?? 'Unknown';

                // Generate unique slug: name-apiId
                $slug = Str::slug($name) . '-' . $playerData['id'];

                $player = Player::updateOrCreate(
                    ['api_player_id' => $playerData['id']],
                    [
                        'name'              => $name,
                        'slug'              => $slug,
                        'photo_url'         => $playerData['photo'] ?? null,
                        'nationality'       => $playerData['nationality'] ?? null,
                        'age'               => $playerData['age'] ?? null,
                        'current_club'      => $teamName,
                        'current_club_logo' => $teamLogo,
                        'current_league'    => $league->name,
                    ]
                );

                PlayerStat::updateOrCreate(
                    ['player_id' => $player->id, 'league_id' => $league->id, 'season' => '2024'],
                    [
                        'goals'          => $stats['goals']['total'] ?? 0,
                        'assists'        => $stats['goals']['assists'] ?? 0,
                        'appearances'    => $stats['games']['appearences'] ?? 0,
                        'yellow_cards'   => $stats['cards']['yellow'] ?? 0,
                        'red_cards'      => $stats['cards']['red'] ?? 0,
                        'minutes_played' => $stats['games']['minutes'] ?? 0,
                        'rating'         => $stats['games']['rating'] ?? null,
                    ]
                );
                $count++;
            }

            $this->info("Synced {$count} scorers for league {$apiLeagueId} ({$league->name})");
        }

        $this->info('Done');
    }
}
