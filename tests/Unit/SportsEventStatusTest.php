<?php

namespace Tests\Unit;

use App\Support\SportsEventStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SportsEventStatusTest extends TestCase
{
    #[DataProvider('knownStatuses')]
    public function test_it_maps_known_provider_states(string $status, ?string $expected): void
    {
        $kickOff = CarbonImmutable::parse('2026-09-17 18:00:00', 'UTC');
        $now = CarbonImmutable::parse('2026-09-16 09:00:00', 'UTC');

        $this->assertSame($expected, SportsEventStatus::fromFixture($status, $kickOff, $now));
    }

    public static function knownStatuses(): array
    {
        return [
            'scheduled' => ['NS', SportsEventStatus::SCHEDULED],
            'scheduled time TBD' => ['TBD', SportsEventStatus::SCHEDULED],
            'live first half' => ['1H', SportsEventStatus::SCHEDULED],
            'live halftime' => ['HT', SportsEventStatus::SCHEDULED],
            'live second half' => ['2H', SportsEventStatus::SCHEDULED],
            'live extra time' => ['ET', SportsEventStatus::SCHEDULED],
            'finished' => ['FT', SportsEventStatus::COMPLETED],
            'finished after extra time' => ['AET', SportsEventStatus::COMPLETED],
            'finished after penalties' => ['PEN', SportsEventStatus::COMPLETED],
            'postponed' => ['PST', SportsEventStatus::POSTPONED],
            'cancelled' => ['CANC', SportsEventStatus::CANCELLED],
            'abandoned' => ['ABD', SportsEventStatus::CANCELLED],
            'interrupted is conservatively omitted' => ['INT', null],
            'suspended is conservatively omitted' => ['SUSP', null],
            'unknown is conservatively omitted' => ['mystery', null],
        ];
    }

    public function test_stale_scheduled_state_is_not_published_as_current_schema_status(): void
    {
        $kickOff = CarbonImmutable::parse('2026-09-10 18:00:00', 'UTC');
        $now = CarbonImmutable::parse('2026-09-16 09:00:00', 'UTC');

        $this->assertNull(SportsEventStatus::fromFixture('NS', $kickOff, $now));
    }
}
