<?php

namespace App\Console\Commands;

use App\Models\Fixture;
use App\Services\FacebookPostService;
use Illuminate\Console\Command;

class FacebookPostMatch extends Command
{
    protected $signature   = 'facebook:post-match {--dry-run : Log only, do not post to Facebook}';
    protected $description = 'Post post-match result + prediction stats for top finished matches today';

    public function handle(FacebookPostService $fb): int
    {
        $dryRun    = $this->option('dry-run');
        $leagueIds = array_keys(FacebookPostService::LEAGUE_PRIORITY);

        $fixtures = Fixture::with(['homeTeam', 'awayTeam', 'league', 'score'])
            ->whereDate('kick_off', today())
            ->whereIn('league_id', $leagueIds)
            ->whereIn('status_short', ['FT', 'AET', 'PEN'])
            ->get()
            ->sortByDesc(fn($f) => FacebookPostService::LEAGUE_PRIORITY[$f->league_id] ?? 0)
            ->take(2);

        if ($fixtures->isEmpty()) {
            $this->info('No finished top fixtures today.');
            return 0;
        }

        foreach ($fixtures as $fixture) {
            $text = $fb->buildPostMatchText($fixture);

            if (!$text) {
                $this->info("Skipping {$fixture->id} — no votes or score missing.");
                continue;
            }

            $result = $fb->postToPage($text, $dryRun);
            $prefix = $dryRun ? '[DRY RUN] ' : '';

            if (isset($result['dry_run'])) {
                $this->info("{$prefix}Would post:\n{$text}\n");
            } elseif ($result['success'] ?? false) {
                $this->info("{$prefix}Posted: {$result['id']}");
            } else {
                $this->error("{$prefix}Failed: " . ($result['error'] ?? 'unknown'));
            }
        }

        return 0;
    }
}
