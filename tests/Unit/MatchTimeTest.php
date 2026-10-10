<?php

namespace Tests\Unit;

use App\Support\MatchTime;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class MatchTimeTest extends TestCase
{
    public function test_dst_and_explicit_offsets_preserve_the_instant_without_mutating_input(): void
    {
        $cases = [
            ['2026-10-10 11:30:00', '2026-10-10 13:30 +02:00'],
            ['2026-10-10T11:30:00Z', '2026-10-10 13:30 +02:00'],
            ['2026-10-10T13:30:00+02:00', '2026-10-10 13:30 +02:00'],
            ['2026-10-10T07:30:00-04:00', '2026-10-10 13:30 +02:00'],
            ['2026-01-10 11:30:00', '2026-01-10 12:30 +01:00'],
            ['2026-03-29 00:59:59', '2026-03-29 01:59 +01:00'],
            ['2026-03-29 01:00:00', '2026-03-29 03:00 +02:00'],
            ['2026-10-25 00:59:59', '2026-10-25 02:59 +02:00'],
            ['2026-10-25 01:00:00', '2026-10-25 02:00 +01:00'],
            ['2026-10-09 22:00:00', '2026-10-10 00:00 +02:00'],
        ];
        $originalZone = date_default_timezone_get();
        try {
            foreach (['UTC', 'America/New_York', 'Asia/Tokyo'] as $zone) {
                date_default_timezone_set($zone);
                foreach ($cases as [$input, $expected]) {
                    $this->assertSame($expected, MatchTime::local($input)->format('Y-m-d H:i P'));
                }
            }
        } finally {
            date_default_timezone_set($originalZone);
        }
        $input = Carbon::parse('2026-10-10 11:30:00', 'UTC');
        $this->assertSame($input->timestamp, MatchTime::local($input)->timestamp);
        $this->assertSame('UTC', $input->timezoneName);
        $this->assertSame('11:30', $input->format('H:i'));
    }

    public function test_local_days_map_to_half_open_utc_bounds_including_dst(): void
    {
        foreach ([
            ['2026-10-10', '2026-10-09 22:00:00', '2026-10-10 22:00:00', 24],
            ['2026-01-10', '2026-01-09 23:00:00', '2026-01-10 23:00:00', 24],
            ['2026-03-29', '2026-03-28 23:00:00', '2026-03-29 22:00:00', 23],
            ['2026-10-25', '2026-10-24 22:00:00', '2026-10-25 23:00:00', 25],
        ] as [$date, $from, $to, $hours]) {
            [$start, $end] = MatchTime::dayBounds($date);
            $this->assertSame($from, $start->format('Y-m-d H:i:s'));
            $this->assertSame($to, $end->format('Y-m-d H:i:s'));
            $this->assertSame($hours * 3600, $end->timestamp - $start->timestamp);
            $this->assertSame('UTC', $start->timezoneName);
            $this->assertSame($date, MatchTime::local($start)->toDateString());
            $this->assertSame($date, MatchTime::local($end->subSecond())->toDateString());
            $this->assertNotSame($date, MatchTime::local($end)->toDateString());
        }
    }

    public function test_day_bounds_reject_malformed_or_overflow_dates_instead_of_normalizing(): void
    {
        foreach (['2026-02-30', '2026-02-29', '0000-01-01', '2026-13-01', 'not-a-date',
            '2026-1-01', '2026-01-1', '2026-10-10T00:00:00Z', '2026-10-10 ', "2026-10-10\n", ''] as $date) {
            $this->assertFalse(MatchTime::isValidDate($date));
            try {
                MatchTime::dayBounds($date);
                $this->fail('Invalid day accepted: '.$date);
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Expected a valid canonical YYYY-MM-DD date.', $e->getMessage());
            }
        }
        $this->assertTrue(MatchTime::isValidDate('2028-02-29'));
        $this->assertSame('2028-02-28 23:00:00', MatchTime::dayBounds('2028-02-29')[0]->format('Y-m-d H:i:s'));
    }
}
