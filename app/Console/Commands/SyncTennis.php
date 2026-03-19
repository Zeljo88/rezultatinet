<?php
namespace App\Console\Commands;

use App\Models\ApiCallLog;
use App\Models\TennisMatch;
use App\Services\ApiTennisService;
use Illuminate\Console\Command;

class SyncTennis extends Command
{
    protected $signature = 'sync:tennis {--date= : Date in Y-m-d format}';
    protected $description = 'Sync tennis matches from API-Sports (45 req/day budget)';

    const DAILY_LIMIT = 45;
    const ENDPOINT_PREFIX = 'tennis/';

    protected function todayCount(): int
    {
        return ApiCallLog::whereDate('called_date', today())
            ->where('endpoint', 'like', self::ENDPOINT_PREFIX . '%')
            ->count();
    }

    protected function logCall(string $endpoint): void
    {
        ApiCallLog::create([
            'endpoint'    => self::ENDPOINT_PREFIX . $endpoint,
            'called_date' => today(),
        ]);
    }

    public function handle(ApiTennisService $api): void
    {
        $date = $this->option('date') ?? now()->format('Y-m-d');

        $used = $this->todayCount();
        if ($used >= self::DAILY_LIMIT) {
            $this->error("Tennis daily API budget reached ({$used}/" . self::DAILY_LIMIT . ").");
            return;
        }

        $synced = 0;

        // Step 1: Live matches
        $this->info("Fetching live tennis matches...");
        try {
            $liveMatches = $api->getLiveMatches();
            $this->logCall('games/live');
            $used++;

            foreach ($liveMatches as $m) {
                $this->upsertMatch($m);
                $synced++;
            }
            $this->info("  Live matches: " . count($liveMatches));
        } catch (\Exception $e) {
            $this->warn("Tennis live fetch failed: " . $e->getMessage());
        }

        // Step 2: Today's schedule
        if ($used < self::DAILY_LIMIT) {
            $this->info("Fetching tennis matches for {$date}...");
            try {
                $matches = $api->getMatchesByDate($date);
                $this->logCall("games?date={$date}");

                foreach ($matches as $m) {
                    $this->upsertMatch($m);
                    $synced++;
                }
                $this->info("  Scheduled matches: " . count($matches));
            } catch (\Exception $e) {
                $this->warn("Tennis schedule fetch failed: " . $e->getMessage());
            }
        }

        $this->info("✓ Tennis sync done. Total upserts: {$synced}");
    }

    protected function upsertMatch(array $m): void
    {
        $status = $m['status']['long'] ?? ($m['status']['short'] ?? 'NS');

        $scoreStr = null;
        if (!empty($m['scores'])) {
            $sets = [];
            foreach ($m['scores'] as $set) {
                if (isset($set['home'], $set['away'])) {
                    $sets[] = $set['home'] . '-' . $set['away'];
                }
            }
            if ($sets) {
                $scoreStr = implode(', ', $sets);
            }
        }

        TennisMatch::updateOrCreate(
            ['api_match_id' => $m['id']],
            [
                'tournament_name' => $m['tournament']['name'] ?? null,
                'country_name'    => $m['country']['name'] ?? null,
                'player_home'     => $m['players']['home']['name'] ?? null,
                'player_away'     => $m['players']['away']['name'] ?? null,
                'score'           => $scoreStr,
                'status'          => $status,
                'match_date'      => isset($m['date']) ? \Carbon\Carbon::parse($m['date']) : null,
            ]
        );
    }
}
