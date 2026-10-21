<?php

namespace App\Console\Commands;

use App\Services\StaleFixtureJournal;
use App\Services\StaleFixturePolicy;
use App\Services\StaleFixtureReconciler;
use Illuminate\Console\Command;
use Throwable;

final class ReconcileStaleFixtures extends Command
{
    protected $signature = 'fixtures:reconcile-stale {--apply : Explicitly perform bounded provider lookup and guarded persistence} {--initialize-only : Create the command-owned audit once, without provider or DB access}';

    protected $description = 'Manual-only sealed Oct1 NS/PST cohort reconciliation (five physical attempts maximum)';

    public function handle(StaleFixtureReconciler $reconciler, StaleFixturePolicy $policy): int
    {
        if (! $this->option('apply') && ! $this->option('initialize-only')) {
            $this->error('Disabled by default. Review and explicit run authority are required.');

            return self::FAILURE;
        }
        if ($this->option('apply') && $this->option('initialize-only')) {
            return self::FAILURE;
        }
        try {
            $policy->cohort();
            $journal = new StaleFixtureJournal(storage_path('app/stale-fixture-reconciliation-v1.jsonl'), (bool) $this->option('initialize-only'));
            if ($this->option('initialize-only')) {
                $this->info('Audit initialized; no provider lookup or fixture write.');

                return self::SUCCESS;
            }
            $summary = $reconciler->run($journal);
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

            return $summary['stop'] === null ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Reconciliation stopped: inspect the command audit/source/state. Do not reset state or retry blindly.');

            return self::FAILURE;
        }
    }
}
