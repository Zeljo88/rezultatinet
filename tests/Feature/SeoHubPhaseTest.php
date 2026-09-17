<?php

namespace Tests\Feature;

use App\Models\BasketballGame;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SeoHubPhaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('app.url', 'https://rezultati.net');
        config()->set('seo.enforce_canonical_host', false);
        URL::forceRootUrl('https://rezultati.net');
        URL::forceScheme('https');
        Carbon::setTestNow('2026-09-16 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_canonical_hubs_have_unique_metadata_one_h1_and_internal_hierarchy_links(): void
    {
        $expectations = [
            '/nogomet' => ['Nogomet rezultati uživo i raspored', 'Nogomet rezultati uživo, raspored i lige'],
            '/kosarka' => ['Košarka rezultati uživo i raspored', 'Košarka rezultati uživo, raspored i lige'],
            '/tenis' => ['Tenis rezultati uživo i raspored mečeva', 'Tenis rezultati uživo, raspored i turniri'],
            '/utakmice-danas' => ['Utakmice danas — 16. septembar 2026.', 'Utakmice danas — rezultati uživo i raspored'],
        ];

        foreach ($expectations as $path => [$h1, $title]) {
            $response = $this->get($path)->assertOk();
            $html = $response->getContent();
            $this->assertSame(1, preg_match_all('/<h1\b/i', $html), $path);
            $response->assertSee($h1)->assertSee("<title>{$title} | rezultati.net</title>", false);
            $response->assertSee('<meta name="robots" content="index, follow">', false);
            $response->assertSee('<link rel="canonical" href="https://rezultati.net' . $path . '">', false);
        }

        $this->get('/')
            ->assertOk()
            ->assertSee('href="/nogomet"', false)
            ->assertSee('href="/kosarka"', false)
            ->assertSee('href="/tenis"', false)
            ->assertSee('href="/utakmice-danas"', false);
    }

    public function test_basketball_league_pages_use_actual_records_and_safe_season_wording(): void
    {
        BasketballGame::create($this->basketballGame('Euroleague', 7001, 'Real Madrid', 'Partizan'));
        BasketballGame::create($this->basketballGame('ABA League', 7002, 'Bosna', 'Split'));

        foreach (['/liga/evroliga' => 'Evroliga', '/liga/aba-liga' => 'ABA Liga'] as $path => $name) {
            $response = $this->get($path)->assertOk();
            $html = $response->getContent();
            $this->assertSame(1, preg_match_all('/<h1\b/i', $html));
            $response->assertSee("{$name} Aktuelna sezona — rezultati, raspored i poredak")
                ->assertSee('<link rel="canonical" href="https://rezultati.net' . $path . '">', false)
                ->assertSee('index, follow');
        }

        $this->get('/liga/evroliga')->assertSee('Real Madrid')->assertSee('Partizan');
        $this->get('/liga/aba-liga')->assertSee('Bosna')->assertSee('Split');
    }

    public function test_league_aliases_are_one_hop_permanent_redirects(): void
    {
        $this->get('/liga/superliga-srbije')->assertStatus(301)
            ->assertRedirect('https://rezultati.net/liga/superliga-srbija');
        $this->get('/liga/euroleague')->assertStatus(301)
            ->assertRedirect('https://rezultati.net/liga/evroliga');
        $this->get('/liga/aba-league')->assertStatus(301)
            ->assertRedirect('https://rezultati.net/liga/aba-liga');
    }

    public function test_sitemap_contains_hubs_and_canonical_basketball_leagues_without_aliases(): void
    {
        BasketballGame::create($this->basketballGame('Euroleague', 7101, 'Real Madrid', 'Partizan'));
        BasketballGame::create($this->basketballGame('ABA League', 7102, 'Bosna', 'Split'));
        $content = $this->get('/sitemap-leagues.xml')->assertOk()->getContent();

        foreach (['/nogomet', '/kosarka', '/tenis', '/utakmice-danas', '/liga/evroliga', '/liga/aba-liga'] as $path) {
            $this->assertSame(1, substr_count($content, "<loc>https://rezultati.net{$path}</loc>"));
        }
        $this->assertStringNotContainsString('/liga/superliga-srbije', $content);
        $this->assertStringNotContainsString('/liga/euroleague', $content);
        $this->assertStringNotContainsString('/liga/aba-league', $content);
    }

    public function test_fresh_application_fixture_season_drives_league_metadata_and_links(): void
    {
        [$league, $home, $away] = $this->footballRecords(2025);
        Fixture::create($this->fixture($league, $home, $away, 2026, '2026-09-20 18:00:00', 'NS'));

        $response = $this->get('/liga/hnl')->assertOk();
        $response->assertSee('HNL Rezultati Uživo — Hrvatska Nogometna Liga 2026/27')
            ->assertSee('HNL Tablica 2026/27')
            ->assertDontSee('2025/26')
            ->assertSee('/tim/dinamo-zagreb', false)
            ->assertSee('/tim/hajduk-split', false);
    }

    public function test_stale_application_season_uses_non_stale_fallback(): void
    {
        [$league, $home, $away] = $this->footballRecords(2025);
        Fixture::create($this->fixture($league, $home, $away, 2025, '2026-05-20 18:00:00', 'FT'));

        $response = $this->get('/liga/hnl')->assertOk();
        $response->assertSee('Aktuelna sezona')->assertDontSee('2025/26');
    }

    public function test_sport_hub_links_only_to_valid_match_and_team_destinations(): void
    {
        [$league, $home, $away] = $this->footballRecords(2026);
        Fixture::create($this->fixture($league, $home, $away, 2026, '2026-09-15 18:00:00', 'FT'));

        $this->get('/nogomet')->assertOk()
            ->assertSee('href="https://rezultati.net/utakmica/dinamo-zagreb-vs-hajduk-split-15-09-2026"', false)
            ->assertSee('href="https://rezultati.net/tim/dinamo-zagreb"', false)
            ->assertDontSee('href="/tim/"', false);
    }

    private function basketballGame(string $league, int $apiId, string $home, string $away): array
    {
        return [
            'api_game_id' => $apiId,
            'league_name' => $league,
            'country_name' => 'Europe',
            'home_team' => $home,
            'away_team' => $away,
            'home_score' => 80,
            'away_score' => 75,
            'status_short' => 'FT',
            'game_date' => '2026-09-15 18:00:00',
        ];
    }

    private function footballRecords(int $currentSeason): array
    {
        $league = League::create([
            'api_league_id' => 210,
            'name' => 'HNL',
            'country' => 'Croatia',
            'sport' => 'football',
            'current_season' => $currentSeason,
        ]);
        $home = Team::create(['api_team_id' => 1001, 'name' => 'Dinamo Zagreb', 'slug' => 'dinamo-zagreb']);
        $away = Team::create(['api_team_id' => 1002, 'name' => 'Hajduk Split', 'slug' => 'hajduk-split']);

        return [$league, $home, $away];
    }

    private function fixture(League $league, Team $home, Team $away, int $season, string $kickOff, string $status): array
    {
        return [
            'api_fixture_id' => random_int(100000, 999999),
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'season' => $season,
            'kick_off' => $kickOff,
            'status_short' => $status,
        ];
    }
}
