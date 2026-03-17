<?php
namespace App\Console\Commands;

use App\Models\League;
use App\Models\ApiCallLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncLeagues extends Command
{
    protected $signature = 'sync:leagues';
    protected $description = 'Sync leagues from API-Football';

    public function handle(): void
    {
        $this->info('Fetching leagues...');

        $response = Http::withHeaders([
            'X-RapidAPI-Key'  => config('services.api_football.key'),
            'X-RapidAPI-Host' => 'v3.football.api-sports.io',
        ])->get('https://v3.football.api-sports.io/leagues', ['current' => 'true']);

        if (!$response->successful()) {
            $this->error('API request failed: ' . $response->status());
            return;
        }

        ApiCallLog::create(['endpoint' => '/leagues', 'called_date' => today()]);

        $leagues = $response->json('response', []);
        $count = 0;

        // Priority leagues to mark active
        $priorityIds = [
            39,  // Premier League
            140, // La Liga
            135, // Serie A
            78,  // Bundesliga
            61,  // Ligue 1
            2,   // Champions League
            3,   // Europa League
            848, // Conference League
            197, // HNL Croatia
            206, // Premijer liga BiH
            168, // SuperLiga Serbia
            183, // First League North Macedonia
        ];

        foreach ($leagues as $item) {
            League::updateOrCreate(
                ['api_league_id' => $item['league']['id']],
                [
                    'name'           => $item['league']['name'],
                    'country'        => $item['country']['name'] ?? null,
                    'logo_url'       => $item['league']['logo'] ?? null,
                    'sport'          => 'football',
                    'is_active'      => in_array($item['league']['id'], $priorityIds),
                    'current_season' => $item['seasons'][0]['year'] ?? null,
                ]
            );
            $count++;
        }

        $this->info("Synced {$count} leagues.");
    }
}
