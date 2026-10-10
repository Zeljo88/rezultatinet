<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/** Presentation only: persisted/provider timestamps and canonical URLs stay UTC. */
final class MatchTime
{
    public const ZONE = 'Europe/Zagreb';

    public static function local(DateTimeInterface|string $instant): CarbonImmutable
    {
        // Naive database DATETIME means UTC; explicit ISO offsets are respected.
        return CarbonImmutable::parse($instant, 'UTC')->setTimezone(self::ZONE);
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONE)->startOfDay();
    }

    public static function isValidDate(string $date): bool
    {
        return preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) === 1
            && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
    }

    /** @return array{CarbonImmutable, CarbonImmutable} Half-open UTC bounds. */
    public static function dayBounds(string $date): array
    {
        if (! self::isValidDate($date)) {
            throw new \InvalidArgumentException('Expected a valid canonical YYYY-MM-DD date.');
        }

        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::ZONE);

        return [$start->utc(), $start->addDay()->utc()];
    }

    public static function onDay(Builder $query, string $column, string $date): Builder
    {
        [$start, $end] = self::dayBounds($date);

        return $query->where($column, '>=', $start)->where($column, '<', $end);
    }
}
