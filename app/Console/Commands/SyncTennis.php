<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncTennis extends Command
{
    protected $signature = 'sync:tennis {--date= : Date in Y-m-d format}';

    protected $description = 'Tennis provider sync (disabled by football-only allowlist)';

    public function handle(): int
    {
        $this->error('Tennis provider is disabled by the sport allowlist. Existing database data is unchanged.');
        Log::channel('api_football')->notice('disabled_sport_command', ['sport' => 'tennis', 'caller' => 'SyncTennis']);

        return self::FAILURE;
    }
}
