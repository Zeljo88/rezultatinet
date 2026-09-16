<?php

namespace Tests\Unit;

use App\Support\BlogContent;
use PHPUnit\Framework\TestCase;

class BlogContentTest extends TestCase
{
    public function test_it_demotes_legacy_body_h1_elements_and_preserves_content(): void
    {
        $html = '<h1 class="lead">Naslov</h1><p>Tekst</p><H1>Drugi naslov</H1>';
        $rendered = BlogContent::withBodyHeadings($html);

        $this->assertSame('<h2 class="lead">Naslov</h2><p>Tekst</p><h2>Drugi naslov</h2>', $rendered);
        $this->assertDoesNotMatchRegularExpression('/<h1\b/i', $rendered);
    }

    public function test_it_handles_null_content(): void
    {
        $this->assertSame('', BlogContent::withBodyHeadings(null));
    }
}
