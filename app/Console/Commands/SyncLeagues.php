<?php

namespace App\Console\Commands;

use App\Models\League;
use App\Services\ApiFootballService;
use Illuminate\Console\Command;

class SyncLeagues extends Command
{
    protected $signature = 'sync:leagues';

    protected $description = 'Sync leagues from API-Football';

    public function handle(ApiFootballService $api): void
    {
        $this->info('Fetching leagues...');

        $leagues = $api->getLeagues('SyncLeagues');
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
            172, // PrvaLiga Slovenia (SNL)
            394, // Prva CFL liga Montenegro
            351, // Superliga Kosovo
        ];

        foreach ($leagues as $item) {
            League::updateOrCreate(
                ['api_league_id' => $item['league']['id']],
                [
                    'name' => $item['league']['name'],
                    'country' => $item['country']['name'] ?? null,
                    'logo_url' => $item['league']['logo'] ?? null,
                    'sport' => 'football',
                    'is_active' => in_array($item['league']['id'], $priorityIds),
                    'current_season' => $item['seasons'][0]['year'] ?? null,
                ]
            );
            $count++;
        }

        $this->info("Synced {$count} leagues.");
    }
}
