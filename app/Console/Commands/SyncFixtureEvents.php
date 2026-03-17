<?php
namespace App\Console\Commands;

use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\Team;
use App\Models\ApiCallLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncFixtureEvents extends Command
{
    protected $signature = 'sync:events {fixture_id}';
    protected $description = 'Sync events (goals, cards) for a specific fixture';

    public function handle(): void
    {
        $fixtureId = $this->argument('fixture_id');
        $fixture = Fixture::find($fixtureId);

        if (!$fixture) {
            $this->error("Fixture {$fixtureId} not found.");
            return;
        }

        if (ApiCallLog::getTodayCount() >= 80) {
            $this->error('Daily API budget reached.');
            return;
        }

        $this->info("Fetching events for fixture {$fixture->api_fixture_id}...");

        $response = Http::withHeaders([
            'X-RapidAPI-Key'  => config('services.api_football.key'),
            'X-RapidAPI-Host' => 'v3.football.api-sports.io',
        ])->get('https://v3.football.api-sports.io/fixtures/events', [
            'fixture' => $fixture->api_fixture_id
        ]);

        if (!$response->successful()) {
            $this->error('API failed: ' . $response->status());
            return;
        }

        ApiCallLog::create(['endpoint' => '/fixtures/events', 'called_date' => today()]);

        $events = $response->json('response', []);

        // Delete old events for this fixture
        FixtureEvent::where('fixture_id', $fixture->id)->delete();

        foreach ($events as $event) {
            // Find team
            $team = Team::where('api_team_id', $event['team']['id'])->first();

            FixtureEvent::create([
                'fixture_id'      => $fixture->id,
                'team_id'         => $team?->id,
                'player_name'     => $event['player']['name'] ?? null,
                'assist_name'     => $event['assist']['name'] ?? null,
                'type'            => $event['type'],
                'detail'          => $event['detail'] ?? null,
                'elapsed_minute'  => $event['time']['elapsed'] ?? null,
            ]);
        }

        $this->info("Synced " . count($events) . " events.");
    }
}
