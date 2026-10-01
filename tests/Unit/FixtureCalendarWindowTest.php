<?php

namespace Tests\Unit;

use App\Support\FixtureCalendarWindow;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FixtureCalendarWindowTest extends TestCase
{
    public function test_utc_windows_have_the_exact_required_dates_and_call_counts(): void
    {
        $now = CarbonImmutable::parse('2026-09-25 23:45:00', 'America/Los_Angeles');

        $this->assertSame(
            ['2026-09-26', '2026-09-27'],
            FixtureCalendarWindow::dates('near', $now),
        );
        $this->assertSame(
            ['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03'],
            FixtureCalendarWindow::dates('week', $now),
        );
        $this->assertCount(23, FixtureCalendarWindow::dates('month', $now));
        $this->assertSame('2026-10-04', FixtureCalendarWindow::dates('month', $now)[0]);
        $this->assertSame('2026-10-26', FixtureCalendarWindow::dates('month', $now)[22]);
    }

    public function test_unknown_window_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FixtureCalendarWindow::dates('all');
    }
}
