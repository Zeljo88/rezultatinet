<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class TechnicalSeoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('app.url', 'https://rezultati.net');
        URL::forceRootUrl('https://rezultati.net');
        URL::forceScheme('https');
    }

    public function test_www_request_redirects_directly_to_https_apex_with_path_and_query(): void
    {
        config()->set('seo.enforce_canonical_host', true);

        $response = $this->withServerVariables([
            'HTTP_HOST' => 'www.rezultati.net',
            'HTTPS' => 'off',
        ])->get('/provjera/putanje?foo=bar&baz=1');

        $response->assertStatus(301);
        $response->assertRedirect('https://rezultati.net/provjera/putanje?foo=bar&baz=1');
    }

    public function test_legacy_table_url_redirects_to_canonical_route(): void
    {
        config()->set('seo.enforce_canonical_host', false);

        $this->get('/tablica/hnl')
            ->assertStatus(301)
            ->assertRedirect('https://rezultati.net/liga/hnl/tablica');
    }

    public function test_404_is_noindex_without_canonical_or_hreflang(): void
    {
        config()->set('seo.enforce_canonical_host', false);

        $response = $this->get('/nepostojeca-seo-stranica');

        $response->assertNotFound();
        $response->assertSee('<meta name="robots" content="noindex, follow">', false);
        $response->assertDontSee('rel="canonical"', false);
        $response->assertDontSee('hreflang=', false);
    }

    public function test_sitemap_index_uses_apex_urls_without_volatile_lastmod(): void
    {
        config()->set('seo.enforce_canonical_host', false);

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertSee('<loc>https://rezultati.net/sitemap-leagues.xml</loc>', false);
        $response->assertDontSee('www.rezultati.net', false);
        $response->assertDontSee('<lastmod>', false);
    }

    public function test_league_sitemap_contains_only_unique_canonical_table_urls(): void
    {
        config()->set('seo.enforce_canonical_host', false);

        $response = $this->get('/sitemap-leagues.xml');
        $content = $response->getContent();

        $response->assertOk();
        $this->assertSame(84, substr_count($content, '<loc>'));
        $this->assertSame(0, substr_count($content, 'https://rezultati.net/tablica/'));
        $this->assertSame(
            1,
            substr_count($content, '<loc>https://rezultati.net/liga/prva-liga-fbih/tablica</loc>'),
        );
    }

}
