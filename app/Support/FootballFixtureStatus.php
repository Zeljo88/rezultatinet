<?php

namespace App\Support;

final class FootballFixtureStatus
{
    /** @var list<string> */
    public const COMPLETED = ['FT', 'AET', 'PEN', 'AWD', 'WO'];

    /** @var list<string> */
    public const CANCELLED = ['CANC', 'ABD'];

    /** @var list<string> */
    public const TERMINAL = [...self::COMPLETED, ...self::CANCELLED];

    /**
     * Statuses the existing zombie repair intentionally leaves alone. Some can
     * change later, so they are excluded from repair without being called terminal.
     *
     * @var list<string>
     */
    public const REPAIR_EXCLUDED = [...self::TERMINAL, 'PST', 'INT', 'SUSP', 'TBD', 'NS'];

    public static function normalize(?string $status): ?string
    {
        $status = strtoupper(trim((string) $status));

        return $status === '' ? null : $status;
    }

    public static function isTerminal(?string $status): bool
    {
        return in_array(self::normalize($status), self::TERMINAL, true);
    }

    public static function isCompleted(?string $status): bool
    {
        return in_array(self::normalize($status), self::COMPLETED, true);
    }

    public static function isCancelled(?string $status): bool
    {
        return in_array(self::normalize($status), self::CANCELLED, true);
    }

    /** Reject stale pre-match/earlier-phase live payloads against the locked row. */
    public static function canPersistLive(?string $current, ?string $incoming, ?int $currentMinute, ?int $incomingMinute): bool
    {
        if (! self::canPersist($current, $incoming)) {
            return false;
        }
        $current = self::normalize($current);
        $incoming = self::normalize($incoming);
        $prematch = ['NS', 'TBD', 'PST'];
        if ($current !== null && ! in_array($current, $prematch, true) && in_array($incoming, $prematch, true)) {
            return false;
        }
        $phase = ['1H' => 1, 'HT' => 2, '2H' => 3, 'ET' => 4, 'BT' => 4, 'P' => 5];
        if (isset($phase[$current], $phase[$incoming]) && $phase[$incoming] < $phase[$current]) {
            return false;
        }

        return self::isTerminal($incoming) || $currentMinute === null
            || ($incomingMinute !== null && $incomingMinute >= $currentMinute);
    }

    public static function canPersist(?string $current, ?string $incoming): bool
    {
        $incoming = self::normalize($incoming);
        if ($incoming === null) {
            return false;
        }

        return ! self::isTerminal($current) || self::isTerminal($incoming);
    }
}
