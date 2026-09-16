<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FinalizeFinishedFixturesCommand extends Command
{
    protected $signature = 'finalize:finished-fixtures';

    protected $description = 'Manual fixture repair is disabled unless an explicit manual provider budget is configured';

    public function handle(): int
    {
        $this->error('Manual provider repair is disabled. Scheduled quota-safe finalization remains active.');

        return self::FAILURE;
    }
}
