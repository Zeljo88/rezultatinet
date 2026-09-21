<?php

namespace App\Support;

use Carbon\CarbonInterface;

final class SportsEventStatus
{
    public const SCHEDULED = 'https://schema.org/EventScheduled';

    public const COMPLETED = 'https://schema.org/EventCompleted';

    public const POSTPONED = 'https://schema.org/EventPostponed';

    public const CANCELLED = 'https://schema.org/EventCancelled';

    public static function fromFixture(
        ?string $status,
        ?CarbonInterface $kickOff,
        ?CarbonInterface $now = null,
    ): ?string {
        $status = strtoupper(trim((string) $status));

        if (FootballFixtureStatus::isCompleted($status)) {
            return self::COMPLETED;
        }

        if ($status === 'PST') {
            return self::POSTPONED;
        }

        if (FootballFixtureStatus::isCancelled($status)) {
            return self::CANCELLED;
        }

        if (in_array($status, ['1H', 'HT', '2H', 'ET', 'BT', 'P', 'LIVE'], true)) {
            return self::SCHEDULED;
        }

        if (in_array($status, ['NS', 'TBD'], true)) {
            if (! $kickOff) {
                return null;
            }

            $now ??= now($kickOff->getTimezone());

            return $kickOff->isFuture($now) ? self::SCHEDULED : null;
        }

        // SUSP, INT and unknown provider states do not have a safe, exact
        // schema.org EventStatusType equivalent.
        return null;
    }
}
