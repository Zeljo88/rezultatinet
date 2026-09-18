<?php

namespace App\Support;

final class SitemapLeaguePolicy
{
    /** @var list<string> */
    public const SLUGS = [
        'hnl',
        'superliga-srbija',
        'premijer-liga-bih',
        'prva-liga-srbija',
        'first-nl-hrvatska',
        'hnl-2',
        'prva-liga-fbih',
        'prva-liga-rs',
        'champions-liga',
        'europa-liga',
        'konferencijska-liga',
        'premier-league',
        'la-liga',
        'serie-a',
        'bundesliga',
        'ligue-1',
        'snl',
        'prva-liga-crne-gore',
        'superliga-kosova',
        'prva-liga-makedonije',
    ];

    public static function routePattern(): string
    {
        return implode('|', array_map(
            static fn (string $slug): string => preg_quote($slug, '/'),
            self::SLUGS,
        ));
    }
}
