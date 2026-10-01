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

    public static function canPersist(?string $current, ?string $incoming): bool
    {
        $incoming = self::normalize($incoming);
        if ($incoming === null) {
            return false;
        }

        return ! self::isTerminal($current) || self::isTerminal($incoming);
    }
}
