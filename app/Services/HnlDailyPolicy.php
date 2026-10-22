<?php
namespace App\Services;
final class HnlDailyPolicy {
    public const VERSION = 'hnl-2026-oct22-v1';
    public const ENABLED = false;
    // Unknown authoritative expiry MUST block activation. Never infer from calendar year.
    public const EXPIRES_AT = null;
    public const BASELINE = __DIR__.'/HnlDailyBaseline.json';
    public static function admission(int $now): void {
        if (!self::ENABLED || self::EXPIRES_AT === null) throw new \RuntimeException('hnl_activation_blocked_policy_expiry');
        if ($now >= strtotime(self::EXPIRES_AT)) throw new \RuntimeException('hnl_policy_expired');
    }
}
