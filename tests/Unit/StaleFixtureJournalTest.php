<?php

namespace Tests\Unit;

use App\Services\StaleFixtureJournal;
use RuntimeException;
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
}
