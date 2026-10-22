<?php

namespace App\Services;

final class HnlDailyPolicy
{
    public const VERSION = 'hnl-2026-oct22-v2';

    public const ENABLED = false;

    // Conservative OPERATIONAL cutoff, NOT an asserted official season-end.
    // Activation review must approve this bound; 2027/28 needs a new reviewed baseline/roster.
    public const EXPIRES_AT = '2027-06-01T00:00:00Z';

    public const BASELINE = __DIR__.'/HnlDailyBaseline.json';

    public static function admission(int $now): void
    {
        if (! self::ENABLED) {
            throw new \RuntimeException('hnl_activation_blocked_policy_expiry');
        }
        self::assertWindow($now);
    }

    public static function assertWindow(int $now): void
    {
        if ($now < strtotime('2026-10-22T12:50:29Z') || $now >= strtotime(self::EXPIRES_AT)) {
            throw new \RuntimeException('hnl_policy_expired');
        }
    }
}
