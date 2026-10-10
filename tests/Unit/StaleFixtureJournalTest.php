<?php

namespace Tests\Unit;

use App\Services\StaleFixtureJournal;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StaleFixtureJournalTest extends TestCase
{
    public function test_five_run_twenty_daily_crash_conservative_restart_and_cursor(): void
    {
        $path = sys_get_temp_dir().'/stale-journal-'.bin2hex(random_bytes(8));
        $journal = new StaleFixtureJournal($path, true);
        for ($run = 0; $run < 4; $run++) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $this->assertTrue($journal->claim(1));
            }
            $this->assertFalse($journal->claim(1));
            $journal->append(['type' => 'inspect', 'id' => 1, 'cursor' => $run + 1]);
            unset($journal);
            $journal = new StaleFixtureJournal($path);
            $this->assertSame($run + 1, $journal->state['cursor']);
        }
        $this->assertFalse($journal->claim(2));
        $this->assertGreaterThan(time(), $journal->state['rows'][1]['next_at']);
        unset($journal);
        unlink($path);
    }

    public function test_single_flight_torn_tail_and_manifest_corruption_fail_closed(): void
    {
        $path = sys_get_temp_dir().'/stale-journal-'.bin2hex(random_bytes(8));
        $journal = new StaleFixtureJournal($path, true);
        try {
            new StaleFixtureJournal($path);
            $this->fail('Concurrent journal lock acquired');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        unset($journal);
        file_put_contents($path, '{', FILE_APPEND);
        try {
            new StaleFixtureJournal($path);
            $this->fail('Torn journal accepted');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        unlink($path);
    }

    public function test_real_process_death_keeps_fsynced_attempt_and_releases_os_lock(): void
    {
        $path = sys_get_temp_dir().'/stale-journal-crash-'.bin2hex(random_bytes(8));
        $script = $path.'.php';
        file_put_contents($script, '<?php require $argv[1]; $j = new \App\Services\StaleFixtureJournal($argv[2], true); $j->claim(7); $j->append(["type" => "inspect", "cursor" => 103]); echo "ready"; fflush(STDOUT); while (true) { usleep(10000); }');
        $process = new Process([PHP_BINARY, $script, base_path('vendor/autoload.php'), $path]);
        try {
            $process->start();
            $deadline = microtime(true) + 2;
            while ($process->isRunning() && $process->getOutput() !== 'ready' && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertSame('ready', $process->getOutput(), $process->getErrorOutput());
            try {
                new StaleFixtureJournal($path);
                $this->fail('Another process acquired the audit lock');
            } catch (RuntimeException $e) {
                $this->assertSame('journal_missing_or_locked', $e->getMessage());
            }
            $process->signal(9);
            $process->wait();
            $journal = new StaleFixtureJournal($path);
            $this->assertSame(1, array_sum($journal->state['days']));
            $this->assertSame(103, $journal->state['cursor']);
            $this->assertGreaterThan(time(), $journal->state['rows'][7]['next_at']);
            unset($journal);
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
            foreach ([$script, $path] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_replaced_identity_and_modified_record_fail_closed(): void
    {
        $path = sys_get_temp_dir().'/stale-journal-'.bin2hex(random_bytes(8));
        $journal = new StaleFixtureJournal($path, true);
        rename($path, $path.'.old');
        file_put_contents($path, file_get_contents($path.'.old'));
        try {
            $journal->claim(1);
            $this->fail('Replaced journal admitted');
        } catch (RuntimeException $e) {
            $this->assertSame('journal_run_deadline', $e->getMessage());
        }
        unset($journal);
        $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $record['event']['manifest'] = 'changed';
        file_put_contents($path, json_encode($record)."\n");
        try {
            new StaleFixtureJournal($path);
            $this->fail('Modified record admitted');
        } catch (RuntimeException $e) {
            $this->assertSame('journal_integrity', $e->getMessage());
        }
        unlink($path);
        unlink($path.'.old');
    }
}
