<?php
namespace App\Console\Commands;

use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Models\League;
use App\Models\Team;
use App\Models\ApiCallLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncFixtures extends Command
{
    protected $signature = 'sync:fixtures {--date= : Date in Y-m-d format}';
    protected $description = 'Sync fixtures from API-Football for a given date';

    public function handle(): void
    {
        $date = $this->option('date') ?? now()->format('Y-m-d');
        $this->info("Fetching fixtures for {$date}...");

        if (ApiCallLog::getTodayCount() >= 80) {
            $this->error('Daily API budget reached (80 calls).');
            return;
        }

        $response = Http::withHeaders([
            'X-RapidAPI-Key'  => config('services.api_football.key'),
            'X-RapidAPI-Host' => 'v3.football.api-sports.io',
        ])->get('https://v3.football.api-sports.io/fixtures', ['date' => $date]);

        if (!$response->successful()) {
            $this->error('API request failed: ' . $response->status());
            return;
        }

        ApiCallLog::create(['endpoint' => '/fixtures?date=' . $date, 'called_date' => today()]);

        $fixtures = $response->json('response', []);
        $count = 0;

        foreach ($fixtures as $data) {
            // Find or skip if league not in our DB
            $league = League::where('api_league_id', $data['league']['id'])->first();
            if (!$league) continue;

            // Upsert home team
            $homeTeam = Team::updateOrCreate(
                ['api_team_id' => $data['teams']['home']['id']],
                [
                    'name'    => $data['teams']['home']['name'],
                    'logo_url'=> $data['teams']['home']['logo'] ?? null,
                ]
            );

            // Upsert away team
            $awayTeam = Team::updateOrCreate(
                ['api_team_id' => $data['teams']['away']['id']],
                [
                    'name'    => $data['teams']['away']['name'],
                    'logo_url'=> $data['teams']['away']['logo'] ?? null,
                ]
            );

            // Upsert fixture
            $fixture = Fixture::updateOrCreate(
                ['api_fixture_id' => $data['fixture']['id']],
                [
                    'league_id'      => $league->id,
                    'home_team_id'   => $homeTeam->id,
                    'away_team_id'   => $awayTeam->id,
                    'season'         => $data['league']['season'],
                    'round'          => $data['league']['round'] ?? null,
                    'kick_off'       => date('Y-m-d H:i:s', $data['fixture']['timestamp']),
                    'status_long'    => $data['fixture']['status']['long'] ?? null,
                    'status_short'   => $data['fixture']['status']['short'] ?? null,
                    'elapsed_minute' => $data['fixture']['status']['elapsed'] ?? null,
                    'venue_name'     => $data['fixture']['venue']['name'] ?? null,
                ]
            );

            // Upsert score
            FixtureScore::updateOrCreate(
                ['fixture_id' => $fixture->id],
                [
                    'home_fulltime' => $data['score']['fulltime']['home'] ?? 0,
                    'away_fulltime' => $data['score']['fulltime']['away'] ?? 0,
                    'home_halftime' => $data['score']['halftime']['home'] ?? 0,
                    'away_halftime' => $data['score']['halftime']['away'] ?? 0,
                ]
            );

            $count++;
        }

        $this->info("Synced {$count} fixtures for {$date}.");
    }
}
