<?php

namespace Tests\Feature;

use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Services\StaleFixtureJournal;
use App\Services\StaleFixturePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_engine_identity_and_schema_are_exact_and_isolated(): void
    {
        $identity = DB::selectOne('SELECT VERSION() version, @@tx_isolation isolation_level, CURRENT_USER() account, @@skip_networking no_tcp');
        $this->assertStringStartsWith('10.11.13-MariaDB', $identity->version);
        $this->assertSame('ci@localhost', $identity->account);
        $this->assertSame(1, (int) $identity->no_tcp);
        $engines = DB::select('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $this->assertCount(4, $engines);
        foreach ($engines as $row) {
            $this->assertSame('InnoDB', $row->ENGINE);
        }
        $this->assertCount(2, DB::select('SHOW GRANTS'));
        $this->receipt('engine', ['identity' => $identity, 'tables' => $engines, 'grants' => DB::select('SHOW GRANTS')]);
    }

    public function test_second_connection_score_and_identity_commits_are_rechecked(): void
    {
        foreach (['score', 'fixture', 'league', 'team'] as $change) {
            $before = $this->snapshot();
            $other = new \PDO('mysql:unix_socket='.getenv('STALE_TEST_SOCKET').';dbname='.getenv('STALE_TEST_DATABASE'), posix_getpwuid(posix_geteuid())['name']);
            $other->beginTransaction();
            $sql = match ($change) {
                'score' => 'UPDATE fixture_scores SET goals_home=0 WHERE fixture_id='.$this->identity['id'],
                'fixture' => 'UPDATE fixtures SET season=season+1 WHERE id='.$this->identity['id'],
                'league' => 'UPDATE leagues SET api_league_id=api_league_id+100000 WHERE id='.$this->identity['league_id'],
                'team' => 'UPDATE teams SET api_team_id=api_team_id+100000 WHERE id='.$this->identity['home_team_id'],
            };
            $other->exec($sql);
            $other->commit();
            $changed = $this->snapshot();
            $facts = (new StaleFixturePolicy)->response([$this->retained()], $this->identity);
            $outcome = $this->reconciler()->apply($this->identity, $before, $facts, hrtime(true) / 1e9, $this->journal);
            $this->assertSame(in_array($change, ['score', 'fixture']) ? 'concurrent_change' : 'relationship_identity_changed', $outcome);
            $this->assertSame($changed, $this->snapshot());
            $this->receipt('second_connection_'.$change, ['outcome' => $outcome]);
            DB::table('fixture_scores')->where('fixture_id', $this->identity['id'])->update(['goals_home' => null]);
            DB::table('fixtures')->where('id', $this->identity['id'])->update(['season' => $this->identity['season']]);
            DB::table('leagues')->where('id', $this->identity['league_id'])->update(['api_league_id' => $this->identity['api_league_id']]);
            DB::table('teams')->where('id', $this->identity['home_team_id'])->update(['api_team_id' => $this->identity['home_api_id']]);
        }
    }

    public function test_fixture_score_and_related_lock_timeouts_leave_no_partial_writes(): void
    {
        foreach (['fixtures' => $this->identity['id'], 'fixture_scores' => $this->identity['score_id'], 'leagues' => $this->identity['league_id'], 'teams' => $this->identity['home_team_id']] as $table => $id) {
            $before = $this->snapshot();
            DB::beginTransaction();
            DB::table($table)->where('id', $id)->lockForUpdate()->first();
            [$worker, $path] = $this->startWorker(['mode' => 'reconcile', 'snapshot' => $before, 'facts' => (new StaleFixturePolicy)->response([$this->retained()], $this->identity), 'lock_timeout' => 1]);
            try {
                $this->assertRealLockWait($worker, $path);
                $worker->wait();
                $result = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(1, $worker->getExitCode());
                $this->assertSame(1205, $result['driver_code']);
                $this->assertSame(3, $result['attempts']);
                DB::rollBack();
                $this->assertSame($before, $this->snapshot());
                $this->receipt('timeout_'.$table, $result);
            } finally {
                $this->cleanup($worker, $path);
            }
        }
    }

    public function test_related_identity_wait_ages_response_past_final_write_boundary(): void
    {
        $before = $this->snapshot();
        DB::beginTransaction();
        DB::table('leagues')->where('id', $this->identity['league_id'])->lockForUpdate()->first();
        [$worker, $path] = $this->startWorker(['mode' => 'reconcile', 'snapshot' => $before, 'facts' => (new StaleFixturePolicy)->response([$this->retained()], $this->identity), 'age' => 119]);
        try {
            $this->assertRealLockWait($worker, $path);
            usleep(1300000);
            DB::commit();
            $result = $this->finish($worker);
            $this->assertSame('response_expired', $result['outcome']);
            $this->assertSame(1, $result['attempts']);
            $this->assertSame($before, $this->snapshot());
            $this->receipt('related_lock_response_aging', $result);
        } finally {
            $this->cleanup($worker, $path);
        }
    }

    public function test_real_deadlock_retry_and_three_attempt_exhaustion_for_reconciliation_and_live_writer(): void
    {
        Schema::create('deadlock_weight', function ($table): void {
            $table->integer('id')->primary();
            $table->integer('v');
        });
        DB::table('deadlock_weight')->insert(array_map(fn ($id) => ['id' => $id, 'v' => 0], range(1, 128)));
        try {
            foreach (['run', 'live'] as $mode) {
                foreach ([1, 3] as $deadlocks) {
                    $before = $this->snapshot();
                    $countBefore = (int) DB::selectOne("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'")->Value;
                    [$worker, $path] = $this->startWorker(['mode' => $mode, 'row' => $mode === 'live' ? $this->liveRow() : $this->retained(), 'deadlock' => true, 'lock_timeout' => 10]);
                    try {
                        for ($attempt = 1; $attempt <= $deadlocks; $attempt++) {
                            $this->barrier($worker, $path.'.attempt'.$attempt);
                            DB::beginTransaction();
                            DB::table('deadlock_weight')->increment('v');
                            DB::table('fixture_scores')->where('fixture_id', $this->identity['id'])->lockForUpdate()->first();
                            file_put_contents($path.'.attempt'.$attempt.'.go', 'go');
                            $this->assertRealLockWait($worker, $path);
                            // Genuine opposite lock ordering. Heavy parent stays alive;
                            // InnoDB chooses the small actual writer as the deadlock victim.
                            DB::table('fixtures')->where('id', $this->identity['id'])->lockForUpdate()->first();
                            DB::rollBack();
                        }
                        if ($deadlocks === 1) {
                            $this->barrier($worker, $path.'.attempt2');
                            file_put_contents($path.'.attempt2.go', 'go');
                        }
                        $worker->wait();
                        $result = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                        $countAfter = (int) DB::selectOne("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'")->Value;
                        $this->assertSame($deadlocks, $countAfter - $countBefore);
                        $this->assertSame($deadlocks === 1 ? 2 : 3, $result['attempts']);
                        $this->assertSame(1, $result['provider_fake_calls']);
                        if ($deadlocks === 1) {
                            $this->assertSame(0, $worker->getExitCode());
                            $this->assertSame($mode === 'live' ? '2H' : 'FT', $this->snapshot()['fixture']['status_short']);
                            $this->assertSame($mode === 'live' ? 1 : 0, $result['effects']);
                        } else {
                            $this->assertSame($before, $this->snapshot());
                            $this->assertSame(0, $result['effects']);
                            if ($mode === 'run') {
                                $this->assertSame('validation_audit_or_persistence_failure', $result['outcome']['stop']);
                                $this->assertSame(1, $result['outcome']['reserved_attempts']);
                            } else {
                                $this->assertSame(1213, $result['driver_code']);
                            }
                        }
                        $this->receipt('deadlock_'.$mode.'_'.$deadlocks, $result + ['engine_deadlocks' => $countAfter - $countBefore]);
                    } finally {
                        $this->cleanup($worker, $path);
                    }
                    DB::table('fixtures')->where('id', $this->identity['id'])->update($before['fixture']);
                    DB::table('fixture_scores')->where('fixture_id', $this->identity['id'])->update($before['score']);
                }
            }
        } finally {
            Schema::drop('deadlock_weight');
        }
    }

    public function test_process_death_before_commit_and_before_outcome_preserves_atomic_state_and_audit(): void
    {
        foreach (['before_commit', 'after_commit'] as $point) {
            $before = $this->snapshot();
            [$worker, $path] = $this->startWorker(['mode' => 'run', 'row' => $this->retained(), 'crash' => $point]);
            try {
                $this->barrier($worker, $path.'.crashready');
                $events = array_map(fn ($line) => json_decode($line, true)['event']['type'], file($path.'.audit', FILE_IGNORE_NEW_LINES));
                $this->assertContains('intent', $events);
                $this->assertNotContains('outcome', $events);
                $worker->signal(9);
                $worker->wait();
                // Lock acquisition also waits for engine cleanup after process death.
                DB::transaction(fn () => Fixture::whereKey($this->identity['id'])->lockForUpdate()->firstOrFail());
                $journal = new StaleFixtureJournal($path.'.audit');
                $this->assertSame(1, array_sum($journal->state['days']));
                $this->assertGreaterThan(time(), $journal->state['rows'][$this->identity['id']]['next_at']);
                $this->assertSame(0, $this->reconciler()->run($journal)['reserved_attempts']);
                if ($point === 'before_commit') {
                    $this->assertSame($before, $this->snapshot());
                } else {
                    $this->assertSame('FT', $this->snapshot()['fixture']['status_short']);
                    $this->assertSame(2, (int) $this->snapshot()['score']['goals_away']);
                    $this->assertSame('concurrent_change', $this->reconciler()->apply($this->identity, $before, (new StaleFixturePolicy)->response([$this->retained()], $this->identity), hrtime(true) / 1e9, $journal));
                }
                $this->receipt('sigkill_'.$point, ['reserved_attempts' => 1, 'intent_without_outcome' => true, 'reopened_provider_calls' => 0, 'status' => $this->snapshot()['fixture']['status_short']]);
                unset($journal);
            } finally {
                $this->cleanup($worker, $path);
            }
        }
    }

    private function barrier(Process $worker, string $marker): void
    {
        $deadline = microtime(true) + 5;
        while (! file_exists($marker) && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($marker, $worker->getOutput().$worker->getErrorOutput());
    }

    private function receipt(string $case, array $data): void
    {
        if ($path = getenv('STALE_CI_RECEIPTS')) {
            file_put_contents($path, json_encode(['case' => $case, 'data' => $data], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function startWorker(array $input): array
    {
        $path = sys_get_temp_dir().'/stale-concurrency-'.bin2hex(random_bytes(8));
        file_put_contents($path, json_encode($input + ['identity' => $this->identity], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/Support/StaleFixtureConcurrencyWorker.php'), $path], base_path(), ['APP_ENV' => 'testing'], timeout: 45);
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
                    $this->receipt('actual_lock_wait', ['connection_id' => $id, 'state' => $waiter->trx_state]);

                    return;
                }
            }
            usleep(20000);
        } while ($worker->isRunning() && microtime(true) < $deadline);
        $diagnostic = ['output' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(), 'running' => $worker->isRunning(), 'markers' => array_map('basename', glob($path.'.*')), 'transactions' => DB::select('SELECT trx_id, trx_state, trx_mysql_thread_id FROM information_schema.INNODB_TRX')];
        $this->receipt('lock_wait_failure', $diagnostic);
        $this->fail('Actual MariaDB LOCK WAIT was not observed: '.json_encode($diagnostic));
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
        foreach (array_merge([$path], glob($path.'.*')) as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}
