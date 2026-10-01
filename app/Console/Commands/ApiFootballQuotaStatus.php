<?php

namespace App\Console\Commands;

use App\Contracts\ApiFootballQuotaStore;
use Illuminate\Console\Command;
use Throwable;

class ApiFootballQuotaStatus extends Command
{
    protected $signature = 'api-football:quota-status';

    protected $description = 'Read-only API-Football physical-attempt quota and circuit status';

    public function handle(ApiFootballQuotaStore $quota): int
    {
        try {
            $status = $quota->status();
        } catch (Throwable $e) {
            $this->error('Quota state unavailable (fail-closed).');

            return self::FAILURE;
        }

        $this->table(['UTC day', 'safeguarded count', 'observed physical', 'bootstrap reserve', 'state', 'hard stop', 'reset'], [[
            $status['day'], $status['global'], $status['observed_physical'] ?? $status['global'], $status['bootstrap'] ?? 0,
            $status['threshold_state'], config('api_football.thresholds.hard_stop'), $status['reset_at'],
        ]]);
        $until = (int) ($status['circuit_until'] ?? 0);
        $this->line('Circuit: '.($until > time() ? 'OPEN until '.gmdate(DATE_ATOM, $until) : 'closed').
            (! empty($status['circuit_reason']) ? ' ('.$status['circuit_reason'].')' : ''));
        $this->section('Endpoint classes', $status['classes'] ?? []);
        $this->section('Callers', $status['callers'] ?? []);
        $this->section('Statuses/outcomes', $status['outcomes'] ?? []);

        return self::SUCCESS;
    }

    private function section(string $title, array $values): void
    {
        $this->newLine();
        $this->info($title);
        if (! $values) {
            $this->line('  none');

            return;
        }
        ksort($values);
        $this->table(['key', 'count'], collect($values)->map(fn ($v, $k) => [$k, $v])->values()->all());
    }
}
