<?php

namespace App\Console\Commands;

use App\Services\HnlDailyRefresh;
use Illuminate\Console\Command;

final class RefreshHnlDaily extends Command
{
    protected $signature = 'hnl:standings-refresh {--status} {--execute}';

    protected $description = 'HNL-only durable daily status; execution disabled pending release review';

    public function handle(HnlDailyRefresh $refresh): int
    {
        try {
            $result = $this->option('execute') ? $refresh->run() : $refresh->status();
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return in_array($result['outcome'] ?? 'status', ['status', 'committed', 'slot_consumed'], true) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            $this->error('HNL daily unavailable or admission refused; inspect durable ledger and reviewed policy.');

            return self::FAILURE;
        }
    }
}
