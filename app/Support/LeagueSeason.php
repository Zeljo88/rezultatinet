<?php

namespace App\Support;

use App\Models\Fixture;
use App\Models\League;

class LeagueSeason
{
    public const FALLBACK = 'Aktuelna sezona';

    public static function label(League $league): string
    {
        $season = Fixture::query()
            ->where('league_id', $league->id)
            ->where('kick_off', '>=', now()->subDays(45))
            ->orderByDesc('season')
            ->value('season');

        if (!$season) {
            return self::FALLBACK;
        }

        $start = (int) $season;

        return $start . '/' . substr((string) ($start + 1), -2);
    }
}
