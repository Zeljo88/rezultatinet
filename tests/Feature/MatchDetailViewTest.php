<?php

namespace Tests\Feature;

use App\Models\Fixture;
use App\Models\League;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MatchDetailViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://rezultati.net');
        URL::forceRootUrl('https://rezultati.net');
        URL::forceScheme('https');
    }

    public function test_match_header_has_one_meaningful_h1_and_two_routable_team_links(): void
    {
        $html = $this->renderHeader('dinamo-zagreb', 'hajduk-split');

        $this->assertSame(1, preg_match_all('/<h1\b/i', $html));
        $this->assertStringContainsString('Dinamo Zagreb', $html);
        $this->assertStringContainsString('Hajduk Split', $html);
        $this->assertStringContainsString('href="https://rezultati.net/tim/dinamo-zagreb"', $html);
        $this->assertStringContainsString('href="https://rezultati.net/tim/hajduk-split"', $html);
    }

    public function test_match_header_uses_text_fallback_for_team_without_a_slug(): void
    {
        $html = $this->renderHeader('dinamo-zagreb', null);

        $this->assertSame(1, preg_match_all('/<h1\b/i', $html));
        $this->assertStringContainsString('href="https://rezultati.net/tim/dinamo-zagreb"', $html);
        $this->assertStringNotContainsString('href="https://rezultati.net/tim/"', $html);
        $this->assertStringContainsString('Hajduk Split', $html);
    }

    private function renderHeader(?string $homeSlug, ?string $awaySlug): string
    {
        $home = new Team(['name' => 'Dinamo Zagreb', 'slug' => $homeSlug]);
        $away = new Team(['name' => 'Hajduk Split', 'slug' => $awaySlug]);
        $league = new League(['name' => 'HNL']);
        $fixture = new Fixture([
            'kick_off' => '2026-09-17 18:00:00',
            'status_short' => 'CANC',
            'round' => '1. kolo',
        ]);
        $fixture->id = 123;
        $fixture->setRelation('homeTeam', $home);
        $fixture->setRelation('awayTeam', $away);
        $fixture->setRelation('league', $league);
        $fixture->setRelation('score', null);
        $fixture->setRelation('events', new Collection());

        return view()->file(dirname(__DIR__, 2) . '/resources/views/livewire/partials/match-header.blade.php', [
            'fixture' => $fixture,
            'activeTab' => 'events',
            'homeLineup' => null,
            'awayLineup' => null,
            'h2h' => [],
            'hasScore' => false,
            'isLive' => false,
            'isHT' => false,
            'isFT' => false,
            'score' => null,
            'scoreHome' => 0,
            'scoreAway' => 0,
        ])->render();
    }
}
