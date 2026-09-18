<?php

namespace Tests\Feature;

use App\Support\SitemapLeaguePolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SitemapTablicaCleanupTest extends TestCase
{
    /** @var list<string> */
    private array $createdTables = [];

    private const API_LEAGUE_IDS = [
        'hnl' => 210,
        'superliga-srbija' => 286,
        'premijer-liga-bih' => 315,
        'prva-liga-srbija' => 287,
        'first-nl-hrvatska' => 211,
        'hnl-2' => 946,
        'prva-liga-fbih' => 316,
        'prva-liga-rs' => 317,
        'champions-liga' => 2,
        'europa-liga' => 3,
        'konferencijska-liga' => 848,
        'premier-league' => 39,
        'la-liga' => 140,
        'serie-a' => 135,
        'bundesliga' => 78,
        'ligue-1' => 61,
        'snl' => 172,
        'prva-liga-crne-gore' => 394,
        'superliga-kosova' => 351,
        'prva-liga-makedonije' => 183,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('app.url', 'https://rezultati.net');
        config()->set('app.key', str_repeat('a', 32));
        URL::forceRootUrl('https://rezultati.net');
        URL::forceScheme('https');

        $this->createMinimalLeaguePageSchema();

        foreach (self::API_LEAGUE_IDS as $slug => $apiLeagueId) {
            DB::table('leagues')->insert([
                'api_league_id' => $apiLeagueId,
                'name' => $slug,
                'current_season' => 2026,
                'logo_url' => null,
            ]);
        }
    }

    protected function tearDown(): void
    {
        if (! in_array('leagues', $this->createdTables, true)) {
            DB::table('leagues')->whereIn('api_league_id', array_values(self::API_LEAGUE_IDS))->delete();
        }

        foreach (array_reverse($this->createdTables) as $table) {
            Schema::drop($table);
        }

        parent::tearDown();
    }

    public function test_all_audited_legacy_urls_redirect_once_to_valid_self_canonical_league_pages(): void
    {
        $this->assertSame(array_keys(self::API_LEAGUE_IDS), SitemapLeaguePolicy::SLUGS);

        foreach (SitemapLeaguePolicy::SLUGS as $slug) {
            $canonical = "https://rezultati.net/liga/{$slug}";

            $this->get("/liga/{$slug}/tablica?source=legacy")
                ->assertStatus(301)
                ->assertHeader('Location', $canonical);

            $this->get("/tablica/{$slug}?source=legacy")
                ->assertStatus(301)
                ->assertHeader('Location', $canonical);

            $this->get("/liga/{$slug}")
                ->assertOk()
                ->assertSee('<link rel="canonical" href="'.$canonical.'">', false);
        }
    }

    public function test_unknown_legacy_slug_remains_not_found(): void
    {
        $this->get('/liga/nepoznata-liga/tablica')->assertNotFound();
        $this->get('/tablica/nepoznata-liga')->assertNotFound();
    }

    public function test_league_sitemap_is_valid_unique_provider_free_and_has_no_tablica_urls(): void
    {
        $response = $this->get('/sitemap-leagues.xml')->assertOk();
        $xml = simplexml_load_string($response->getContent());

        $this->assertNotFalse($xml);
        $locations = [];
        foreach ($xml->url as $url) {
            $locations[] = (string) $url->loc;
        }

        $this->assertCount(64, $locations);
        $this->assertCount(count(array_unique($locations)), $locations);
        $this->assertSame([], array_values(array_filter(
            $locations,
            static fn (string $location): bool => preg_match('#/tablica(?:/|$)#', $location) === 1,
        )));

        foreach (['https://rezultati.net', 'https://rezultati.net/blog', 'https://rezultati.net/strijelci', 'https://rezultati.net/igraci/balkan'] as $hub) {
            $this->assertContains($hub, $locations);
        }

        foreach (SitemapLeaguePolicy::SLUGS as $slug) {
            $this->assertContains("https://rezultati.net/liga/{$slug}", $locations);
            $this->assertContains("https://rezultati.net/liga/{$slug}/raspored", $locations);
            $this->assertContains("https://rezultati.net/liga/{$slug}/strijelci", $locations);
        }

        foreach ($locations as $location) {
            $parts = parse_url($location);
            $this->assertIsArray($parts);
            $this->assertSame('https', $parts['scheme'] ?? null);
            $this->assertSame('rezultati.net', $parts['host'] ?? null);
            $this->assertDoesNotMatchRegularExpression('#/(?:liga|tim)/[0-9]+(?:/|$)#', $parts['path'] ?? '');
        }
    }

    public function test_sitemap_index_child_structure_is_unchanged(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();
        $xml = simplexml_load_string($response->getContent());

        $this->assertNotFalse($xml);
        $locations = [];
        foreach ($xml->sitemap as $sitemap) {
            $locations[] = (string) $sitemap->loc;
        }

        $this->assertSame([
            'https://rezultati.net/sitemap-leagues.xml',
            'https://rezultati.net/sitemap-teams.xml',
            'https://rezultati.net/sitemap-blog.xml',
            'https://rezultati.net/sitemap-matches.xml',
        ], $locations);
    }

    private function createMinimalLeaguePageSchema(): void
    {
        if (! Schema::hasTable('leagues')) {
            Schema::create('leagues', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('api_league_id')->unique();
                $table->string('name');
                $table->unsignedInteger('current_season')->nullable();
                $table->string('logo_url')->nullable();
            });
            $this->createdTables[] = 'leagues';
        }

        if (! Schema::hasTable('fixtures')) {
            Schema::create('fixtures', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('league_id');
                $table->dateTime('kick_off')->nullable();
                $table->string('status_short')->nullable();
            });
            $this->createdTables[] = 'fixtures';
        }

        if (! Schema::hasTable('standings')) {
            Schema::create('standings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('league_id');
                $table->unsignedInteger('rank')->nullable();
            });
            $this->createdTables[] = 'standings';
        }

        if (! Schema::hasTable('player_stats')) {
            Schema::create('player_stats', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('league_id');
                $table->unsignedInteger('goals')->default(0);
            });
            $this->createdTables[] = 'player_stats';
        }
    }
}
