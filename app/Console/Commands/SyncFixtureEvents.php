<?php

namespace App\Console\Commands;

use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\Team;
use App\Services\ApiFootballService;
use Illuminate\Console\Command;

class SyncFixtureEvents extends Command
{
    protected $signature = 'sync:events {fixture_id}';

    protected $description = 'Sync events (goals, cards) for a specific fixture';

    public function handle(ApiFootballService $api): void
    {
        $fixtureId = $this->argument('fixture_id');
        $fixture = Fixture::find($fixtureId);

        if (! $fixture) {
            $this->error("Fixture {$fixtureId} not found.");

            return;
        }

        $this->info("Fetching events for fixture {$fixture->api_fixture_id}...");

        $events = $api->getFixtureEvents((int) $fixture->api_fixture_id, 'SyncFixtureEvents');

        // Delete old events for this fixture
        FixtureEvent::where('fixture_id', $fixture->id)->delete();

        foreach ($events as $event) {
            // Find team
            $team = Team::where('api_team_id', $event['team']['id'])->first();

            FixtureEvent::create([
                'fixture_id' => $fixture->id,
                'team_id' => $team?->id,
                'player_name' => $event['player']['name'] ?? null,
                'assist_name' => $event['assist']['name'] ?? null,
                'type' => $event['type'],
                'detail' => $event['detail'] ?? null,
                'elapsed_minute' => $event['time']['elapsed'] ?? null,
            ]);
        }

        $this->info('Synced '.count($events).' events.');
    }
}
