<?php

namespace App\Support;

final class BlogContent
{
    /**
     * The post template owns the page's single H1. Stored article content may
     * contain legacy H1 elements, so demote them without discarding their text.
     */
    public static function withBodyHeadings(?string $html): string
    {
        $html ??= '';

        return preg_replace_callback(
            '/<(\/?)h1\b([^>]*)>/i',
            static fn (array $matches): string => '<' . $matches[1] . 'h2' . $matches[2] . '>',
            $html,
        ) ?? $html;
    }
}
