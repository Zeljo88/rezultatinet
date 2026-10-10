<?php

namespace App\Support;

final class BlogMetaTitle
{
    private const MAX_LENGTH = 60;

    private const SUFFIX = ' | rezultati.net';

    public static function format(string $title): string
    {
        $title = trim($title);

        if (str_ends_with(mb_strtolower($title), mb_strtolower(self::SUFFIX))) {
            $title = rtrim(mb_substr($title, 0, -mb_strlen(self::SUFFIX)));
        }

        $availableLength = self::MAX_LENGTH - mb_strlen(self::SUFFIX);

        if (mb_strlen($title) > $availableLength) {
            $truncated = mb_substr($title, 0, $availableLength);
            $nextCharacter = mb_substr($title, $availableLength, 1);
            $endsAtWordBoundary = preg_match('/\s$/u', $truncated) === 1
                || preg_match('/^\s/u', $nextCharacter) === 1;

            if (! $endsAtWordBoundary) {
                $lastSpace = mb_strrpos($truncated, ' ');
                $truncated = $lastSpace === false
                    ? $truncated
                    : mb_substr($truncated, 0, $lastSpace);
            }

            $title = rtrim($truncated);
        }

        return $title.self::SUFFIX;
    }
}
