<?php

namespace Tests\Feature;

use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Services\StaleFixturePolicy;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** Real MariaDB row-lock contention, not mocked interleaving; NEVER auto-run. */
class StaleFixtureMariaDbConcurrencyTest extends StaleFixturePersistenceTest
{
    public function test_reconciler_commit_wins_against_an_already_waiting_stale_live_writer(): void
    {
        $snapshot = $this->snapshot();
        $facts = (new StaleFixturePolicy)->response([$this->retained()], $this->identity);
        DB::beginTransaction();
        Fixture::whereKey($this->identity['id'])->lockForUpdate()->firstOrFail();
        [$worker, $path] = $this->startWorker(['mode' => 'live', 'row' => $this->liveRow()]);
        try {
            $this->assertRealLockWait($worker, $path);
            $this->assertSame('updated_FT', $this->reconciler()->apply($this->identity, $snapshot, $facts, hrtime(true) / 1e9, $this->journal));
            DB::commit();
            $this->assertSame('FT', $this->finish($worker)['outcome']);
            $this->assertSame('FT', Fixture::findOrFail($this->identity['id'])->status_short);
            $this->assertEquals(2, FixtureScore::where('fixture_id', $this->identity['id'])->value('goals_away'));
        } finally {
            $this->cleanup($worker, $path);
        }
    }

    public function test_live_commit_wins_against_reconciler_with_old_selected_snapshot(): void
    {
        $snapshot = $this->snapshot();
        $facts = (new StaleFixturePolicy)->response([$this->retained()], $this->identity);
        DB::beginTransaction();
        $this->live($this->liveRow());
        $this->assertSame([], $this->broadcasts);
        [$worker, $path] = $this->startWorker(['mode' => 'reconcile', 'snapshot' => $snapshot, 'facts' => $facts]);
        try {
            $this->assertRealLockWait($worker, $path);
            DB::commit();
            $this->assertCount(1, $this->broadcasts);
            $this->assertSame('concurrent_change', $this->finish($worker)['outcome']);
            $this->assertSame('2H', Fixture::findOrFail($this->identity['id'])->status_short);
            $this->assertEquals(0, FixtureScore::where('fixture_id', $this->identity['id'])->value('goals_away'));
        } finally {
            $this->cleanup($worker, $path);
        }
    }

    private function startWorker(array $input): array
    {
        $path = sys_get_temp_dir().'/stale-concurrency-'.bin2hex(random_bytes(8));
        file_put_contents($path, json_encode($input + ['identity' => $this->identity], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/Support/StaleFixtureConcurrencyWorker.php'), $path], base_path(), ['APP_ENV' => 'testing'], timeout: 15);
        $worker->start();

        return [$worker, $path];
    }

    private function assertRealLockWait(Process $worker, string $path): void
    {
        $deadline = microtime(true) + 2;
        do {
            if (file_exists($path.'.ready')) {
                $id = (int) file_get_contents($path.'.ready');
                // Dedicated local test account must have PROCESS visibility.
                // This assertion proves actual engine contention, not just timing.
                $waiter = DB::selectOne("SELECT trx_state FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = ? AND trx_state = 'LOCK WAIT'", [$id]);
                if ($waiter) {
                    $this->assertTrue($worker->isRunning());

                    return;
                }
            }
            usleep(20000);
        } while ($worker->isRunning() && microtime(true) < $deadline);
        $this->fail('Actual MariaDB LOCK WAIT was not observed: '.$worker->getErrorOutput());
    }

    private function finish(Process $worker): array
    {
        $worker->wait();
        $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());

        return json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    }

    private function cleanup(Process $worker, string $path): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop(1);
        }
        foreach ([$path, $path.'.ready', $path.'.audit'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}
