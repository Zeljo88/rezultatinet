<?php

namespace Tests\Unit;

use App\Support\BlogMetaTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BlogMetaTitleTest extends TestCase
{
    #[DataProvider('titles')]
    public function test_it_formats_blog_titles_without_cutting_words_or_duplicating_the_brand(
        string $rawTitle,
        string $expected,
    ): void {
        $title = BlogMetaTitle::format($rawTitle);

        $this->assertSame($expected, $title);
        $this->assertLessThanOrEqual(60, mb_strlen($title));
        $this->assertSame(1, substr_count(mb_strtolower($title), 'rezultati.net'));
    }

    public static function titles(): array
    {
        return [
            'long title stops at a complete word' => [
                'Manchester United sprema ljetnu revoluciju: Carrick na prekretnici',
                'Manchester United sprema ljetnu revoluciju: | rezultati.net',
            ],
            'multibyte title uses character length' => [
                'Željezničar priprema veliko iznenađenje za evropsku utakmicu',
                'Željezničar priprema veliko iznenađenje za | rezultati.net',
            ],
            'existing brand suffix is not duplicated' => [
                'Gdje gledati HNL 2025/26 | rezultati.net',
                'Gdje gledati HNL 2025/26 | rezultati.net',
            ],
        ];
    }
}
