<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class CalendarPhase1HarnessIntegrationWorkerBootstrapTest extends TestCase
{
    public function test_standalone_worker_loads_composer_before_declaring_test_doubles(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $process = new Process([
            PHP_BINARY,
            $projectRoot.'/tests/Support/CalendarPhase1HarnessIntegrationWorker.php',
            'normal',
        ], $projectRoot, [
            'PHASE1_INTEGRATION_ROOT' => $projectRoot.'/missing-calendar-phase1-integration-root',
        ]);

        $process->run();

        $output = $process->getErrorOutput().$process->getOutput();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString('Interface "App\\Contracts\\ApiFootballQuotaStore" not found', $output);
        $this->assertMatchesRegularExpression(
            '/integration_(?:worker_requires_root|root_missing)/',
            $output,
        );
    }
}
