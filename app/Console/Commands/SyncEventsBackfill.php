<?php
namespace App\Console\Commands;

use App\Models\ApiCallLog;
use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\Team;
use App\Services\ApiFootballService;
use Illuminate\Console\Command;

class SyncEventsBackfill extends Command
{
    protected $signature = 'sync:events-backfill {--days=2 : How many days back to backfill}';
    protected $description = 'Backfill missing events for finished matches';

    public function handle(ApiFootballService $api): void
    {
        $days = $this->option('days');

        $fixtures = Fixture::whereIn('status_short', ['FT', 'AET', 'PEN', 'HT', 'BT', '1H', '2H', 'ET'])
            ->where('kick_off', '>=', now()->subDays($days))
            ->whereDoesntHave('events')
            ->get();

        $this->info("Found {$fixtures->count()} fixtures missing events");

        foreach ($fixtures as $fixture) {
            if (ApiCallLog::getTodayCount() >= 7400) {
                $this->warn('API budget limit reached, stopping');
                break;
            }

            $response = $api->getFixtureById($fixture->api_fixture_id);
            ApiCallLog::create(['endpoint' => "/fixtures?id={$fixture->api_fixture_id}", 'called_date' => today()]);

            if (empty($response['events'])) {
                continue;
            }

            FixtureEvent::where('fixture_id', $fixture->id)->delete();
            foreach ($response['events'] as $event) {
                $team = Team::where('api_team_id', $event['team']['id'] ?? 0)->first();
                FixtureEvent::create([
                    'fixture_id'     => $fixture->id,
                    'team_id'        => $team?->id,
                    'player_name'    => $event['player']['name'] ?? null,
                    'assist_name'    => $event['assist']['name'] ?? null,
                    'type'           => $event['type'] ?? 'Goal',
                    'detail'         => $event['detail'] ?? null,
                    'elapsed_minute' => $event['time']['elapsed'] ?? null,
                ]);
            }

            $this->info("Synced events for fixture {$fixture->id} ({$fixture->api_fixture_id})");
        }

        $this->info('Done');
    }
}
