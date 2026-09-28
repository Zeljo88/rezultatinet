<?php

declare(strict_types=1);

namespace Tests\Unit;

use Phase1Abort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (! defined('PHASE1_LIBRARY_ONLY')) {
    define('PHASE1_LIBRARY_ONLY', true);
}

require_once dirname(__DIR__, 2).'/tools/calendar-phase1/phase1_harness.php';

class CalendarPhase1HarnessCanonicalIdentityTest extends TestCase
{
    private const EXPECTED = [
        'sha256' => PHASE1_DEPLOYED_PREIMAGE_SHA256,
        'uid' => 10004,
        'gid' => 1003,
        'mode' => 0644,
        'size' => 13839,
    ];

    public function test_reordered_equal_preimage_has_the_same_canonical_identity(): void
    {
        $reordered = [
            'uid' => 10004,
            'size' => 13839,
            'sha256' => PHASE1_DEPLOYED_PREIMAGE_SHA256,
            'mode' => 0644,
            'gid' => 1003,
        ];

        assert_canonical_identity($reordered, self::EXPECTED, 'deployment_preimage_identity_failed');

        $this->addToAssertionCount(1);
    }

    #[DataProvider('nonIdenticalPreimages')]
    public function test_non_identical_preimages_are_rejected(array $actual): void
    {
        $this->expectException(Phase1Abort::class);
        $this->expectExceptionMessage('deployment_preimage_identity_failed');

        assert_canonical_identity($actual, self::EXPECTED, 'deployment_preimage_identity_failed');
    }

    public static function nonIdenticalPreimages(): array
    {
        return [
            'missing key' => [array_diff_key(self::EXPECTED, ['size' => true])],
            'extra key' => [self::EXPECTED + ['unexpected' => true]],
            'changed type' => [array_replace(self::EXPECTED, ['uid' => '10004'])],
            'changed value' => [array_replace(self::EXPECTED, ['size' => 13840])],
        ];
    }
}
