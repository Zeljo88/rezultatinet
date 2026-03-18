<?php

namespace App\Console\Commands;

use App\Services\FacebookPostService;
use Illuminate\Console\Command;

class FacebookPreMatch extends Command
{
    protected $signature   = 'facebook:pre-match {--dry-run : Log only, do not post to Facebook}';
    protected $description = 'Post pre-match prediction polls for top matches today';

    public function handle(FacebookPostService $fb): int
    {
        $dryRun   = $this->option('dry-run');
        $fixtures = $fb->getTopFixturesToday(2);

        if ($fixtures->isEmpty()) {
            $this->info('No top fixtures today.');
            return 0;
        }

        foreach ($fixtures as $fixture) {
            $text   = $fb->buildPreMatchText($fixture);
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
