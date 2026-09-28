<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (! defined('PHASE1_LIBRARY_ONLY')) {
    define('PHASE1_LIBRARY_ONLY', true);
}

require_once dirname(__DIR__, 2).'/tools/calendar-phase1/phase1_harness.php';

class CalendarPhase1HarnessUtcBoundaryTest extends TestCase
{
    #[DataProvider('utcBoundaries')]
    public function test_d0_d1_are_derived_from_one_utc_instant(string $instant, array $expected): void
    {
        $this->assertSame(
            $expected,
            phase1_utc_date_scope(new DateTimeImmutable($instant, new DateTimeZone('UTC'))),
        );
    }

    public static function utcBoundaries(): array
    {
        return [
            'last second of day' => ['2026-09-28 23:59:59.999999 UTC', ['2026-09-28', '2026-09-29']],
            'first second of next day' => ['2026-09-29 00:00:00.000000 UTC', ['2026-09-29', '2026-09-30']],
            'non-UTC input normalizes first' => ['2026-09-29 01:30:00+02:00', ['2026-09-28', '2026-09-29']],
        ];
    }
}
