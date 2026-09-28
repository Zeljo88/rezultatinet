<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CalendarPhase1HarnessIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('The root-owned evidence integration fixture requires an isolated root container.');
        }
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The harness integration suite requires MariaDB.');
        }
        $version = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        if (! str_starts_with($version, '10.11.13-MariaDB')) {
            $this->markTestSkipped('Exact MariaDB 10.11.13 is required; found '.$version);
        }
    }

    #[DataProvider('adversarialScenarios')]
    public function test_real_activation_and_recovery_adversarial_scenarios(string $scenario): void
    {
        $this->runWorker($scenario);
    }

    public static function adversarialScenarios(): array
    {
        return array_map(static fn (string $scenario): array => [$scenario], [
            'normal', 'idempotency', 'tampered-intent', 'tampered-seal', 'tampered-marker', 'symlink-swap',
            'lock-contention', 'concurrent-advancement', 'provider-cap', 'anchor-tamper',
            'authentication-key-mode', 'package-identity', 'unknown-option', 'lock-symlink',
            'evidence-not-fresh',
        ]);
    }

    #[DataProvider('crashBoundaries')]
    public function test_real_process_interruption_at_transaction_and_fsync_boundaries(string $fault): void
    {
        $this->runWorker('crash-boundary', 'kill:'.$fault);
    }

    public static function crashBoundaries(): array
    {
        $boundaries = [
            'before:outer-transaction', 'after:outer-transaction-begin',
            'after:provider-attempt-1', 'after:provider-response-1',
            'after:provider-attempt-2', 'after:provider-response-2',
            'after:provider-attempt-3', 'after:provider-response-3',
            'after:provider-attempt-4', 'after:provider-response-4',
            'after:file-fsync:commit-intent.json', 'after:file-publish:commit-intent.json',
            'after:directory-fsync:commit-intent.json',
            'after:file-fsync:precommit.sha256.json', 'after:file-publish:precommit.sha256.json',
            'after:directory-fsync:precommit.sha256.json',
            'before:outer-commit', 'after:outer-commit',
            'after:file-fsync:COMMITTED.json', 'after:file-publish:COMMITTED.json',
            'after:directory-fsync:COMMITTED.json',
        ];

        return array_map(static fn (string $boundary): array => [$boundary], $boundaries);
    }

    private function runWorker(string $scenario, ?string $fault = null): void
    {
        $environment = $fault === null ? [] : ['PHASE1_TEST_FAULT' => $fault];
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/CalendarPhase1HarnessIntegrationWorker.php'),
            $scenario,
        ], base_path(), $environment, null, 90);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['passed'] ?? false, $process->getOutput());
    }
}
