<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class FixtureCalendarWindow
{
    /** @return list<string> */
    public static function dates(string $window, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now('UTC'))->utc()->startOfDay();
        [$first, $last] = match ($window) {
            'near' => [0, 1],
            'week' => [2, 7],
            'month' => [8, 30],
            default => throw new InvalidArgumentException("Unknown calendar window: {$window}"),
        };

        $dates = [];
        for ($offset = $first; $offset <= $last; $offset++) {
            $dates[] = $today->addDays($offset)->toDateString();
        }

        return $dates;
    }
}
