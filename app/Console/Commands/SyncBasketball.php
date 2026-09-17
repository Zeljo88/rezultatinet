<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncBasketball extends Command
{
    protected $signature = 'sync:basketball {--date= : Date in Y-m-d format}';

    protected $description = 'Basketball provider sync (disabled by football-only allowlist)';

    public function handle(): int
    {
        $this->error('Basketball provider is disabled by the sport allowlist. Existing database data is unchanged.');
        Log::channel('api_football')->notice('disabled_sport_command', ['sport' => 'basketball', 'caller' => 'SyncBasketball']);

        return self::FAILURE;
    }
}
