<?php
namespace App\Console\Commands;

use App\Models\ApiCallLog;
use App\Models\BasketballGame;
use App\Services\ApiBasketballService;
use Illuminate\Console\Command;

class SyncBasketball extends Command
{
    protected $signature = 'sync:basketball {--date= : Date in Y-m-d format}';
    protected $description = 'Sync basketball games from API-Sports (45 req/day budget)';

    const DAILY_LIMIT = 45;
    const ENDPOINT_PREFIX = 'basketball/';

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

    public function handle(ApiBasketballService $api): void
    {
        $date = $this->option('date') ?? now()->format('Y-m-d');

        $used = $this->todayCount();
        if ($used >= self::DAILY_LIMIT) {
            $this->error("Basketball daily API budget reached ({$used}/" . self::DAILY_LIMIT . ").");
            return;
        }

        $synced = 0;

        // Fetch today's games (free plan — no live=all endpoint)
        $this->info("Fetching basketball games for {$date}...");
        $games = $api->getGamesByDate($date);
        $this->logCall("games?date={$date}");

        foreach ($games as $g) {
            $this->upsertGame($g);
            $synced++;
        }
        $this->info("  Games synced: " . count($games));
        $this->info("✓ Basketball sync done. Total upserts: {$synced}");
    }

    protected function upsertGame(array $g): void
    {
        $status = $g['status']['short'] ?? 'NS';
        $scores = $g['scores'] ?? [];

        $homeScore = $scores['home']['total'] ?? null;
        $awayScore = $scores['away']['total'] ?? null;

        $elapsed = null;
        if (!empty($g['status']['timer'])) {
            $elapsed = (int) $g['status']['timer'];
        }

        BasketballGame::updateOrCreate(
            ['api_game_id' => $g['id']],
            [
                'league_name'  => $g['league']['name'] ?? null,
                'country_name' => $g['country']['name'] ?? null,
                'home_team'    => $g['teams']['home']['name'] ?? null,
                'away_team'    => $g['teams']['away']['name'] ?? null,
                'home_score'   => $homeScore,
                'away_score'   => $awayScore,
                'status_short' => $status,
                'elapsed'      => $elapsed,
                'game_date'    => isset($g['date']) ? \Carbon\Carbon::parse($g['date']) : null,
            ]
        );
    }
}
