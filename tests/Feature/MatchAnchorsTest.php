<?php

namespace Tests\Feature;

use App\Livewire\LiveScores;
use App\Models\Fixture;
use App\Support\MatchRoute;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class MatchAnchorsTest extends MatchTimeUiTest
{
    private function inventory(): void
    {
        foreach (['2026-10-09', '2026-10-10', '2026-10-11'] as $day) {
            foreach (['NS', 'TBD', '1H', 'HT', '2H', 'ET', 'BT', 'P', 'LIVE', 'FT', 'AET', 'PEN', 'PST', 'CANC', 'ABD', 'SUSP', 'INT'] as $i => $status) {
                $team = 2000 + $i;
                DB::table('teams')->insertOrIgnore(['id' => $team, 'api_team_id' => $team, 'name' => 'Home '.$status, 'slug' => 'home-'.strtolower($status)]);
                $id = DB::table('fixtures')->insertGetId(['api_fixture_id' => (int) str_replace('-', '', $day) * 100 + $i, 'league_id' => 1, 'home_team_id' => $team, 'away_team_id' => 1507, 'season' => 2026, 'kick_off' => $day.' 11:30:00', 'status_short' => $status, 'elapsed_minute' => 32]);
                DB::table('fixture_scores')->insert(['fixture_id' => $id, 'goals_home' => 2, 'goals_away' => 1, 'home_fulltime' => 2, 'away_fulltime' => 1]);
            }
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($doc);
    }

    private function checkRows(string $html, int $count): void
    {
        $x = $this->xpath($html);
        $rows = $x->query('//*[@data-status]');
        $this->assertCount($count, $rows);
        $this->assertCount($count, $x->query('//*[@data-status]//a[starts-with(@href,"/utakmica/")]'));
        foreach ($rows as $row) {
            $this->assertFalse($row->hasAttribute('onclick'));
            $a = $x->query('.//a[starts-with(@href,"/utakmica/")]', $row);
            $this->assertCount(1, $a);
            $this->assertMatchesRegularExpression('~^/utakmica/(?:[0-9]+|[a-z0-9-]+-vs-[a-z0-9-]+-[0-9]{2}-[0-9]{2}-[0-9]{4})$~', $a[0]->getAttribute('href'));
            $this->assertNotEmpty($a[0]->getAttribute('aria-label'));
            $this->assertCount(0, $x->query('.//a|.//button|.//input|.//*[@tabindex]', $a[0]));
        }
        $ids = [];
        foreach ($x->query('//*[@id]') as $node) {
            $ids[] = $node->getAttribute('id');
        }
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function test_anchor_initial_routes_all_statuses_and_canonical_destinations(): void
    {
        $this->inventory();
        $out = getenv('ANCHOR_EVIDENCE');
        foreach (['home' => '/', 'today' => '/utakmice-danas', 'tomorrow' => '/sutra', 'yesterday' => '/jucer', 'football' => '/nogomet'] as $name => $path) {
            Livewire::flushState();
            $html = $this->get($path)->assertOk()->getContent();
            $this->checkRows($html, 17);
            $this->assertStringContainsString('G-854YSPE0YX', $html);
            foreach (['kickoffMs:', 'serverMs:', 'meridian-popup', 'adsbygoogle'] as $marker) {
                $this->assertStringNotContainsString($marker, $html);
            }
            if ($out) {
                file_put_contents($out.'/'.$name.'.html', $html);
            }
        }
        foreach (Fixture::with(['homeTeam', 'awayTeam'])->get() as $fixture) {
            $slug = MatchRoute::slugFor($fixture);
            $this->assertSame($fixture->id, MatchRoute::resolve($slug)->id);
        }
    }

    public function test_anchor_local_midnight_fallback_and_escaping(): void
    {
        Carbon::setTestNow('2026-10-09 22:30:00 UTC');
        $id = DB::table('fixtures')->insertGetId(['api_fixture_id' => 991, 'league_id' => 1, 'home_team_id' => 1506, 'away_team_id' => 1507, 'season' => 2026, 'kick_off' => '2026-10-09 22:15:00', 'status_short' => 'TBD']);
        $html = $this->get('/')->assertOk()->getContent();
        $this->checkRows($html, 1);
        $this->assertStringContainsString('href="/utakmica/arsenal-vs-leeds-09-10-2026"', $html);
        $this->assertStringContainsString('00:15', $html);
        DB::table('teams')->where('id', 1506)->update(['slug' => null, 'name' => 'Home " <script>alert(1)</script> & \'']);
        $html = $this->get('/utakmice-danas')->assertOk()->getContent();
        $this->checkRows($html, 1);
        $this->assertStringContainsString('href="/utakmica/'.$id.'"', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertSame('Home " <script>alert(1)</script> & \' — Leeds', $this->xpath($html)->query('//a[contains(@class,"match-row-link")]')[0]->getAttribute('aria-label'));
        $this->assertCount(0, $this->xpath($html)->query('//a[@href="/tim/"]'));
    }

    public function test_anchor_reactive_event_date_change_and_browser_responses(): void
    {
        $this->inventory();
        $out = getenv('ANCHOR_EVIDENCE');
        $component = Livewire::test(LiveScores::class);
        $this->checkRows($component->html(), 17);
        DB::table('fixtures')->where('status_short', 'NS')->update(['status_short' => '1H', 'elapsed_minute' => 3]);
        DB::table('fixture_scores')->where('fixture_id', 18)->update(['goals_home' => 4]);
        $component->dispatch('echo:live-scores,score.updated', data: [])->assertSee('4');
        $this->checkRows($component->html(), 17);
        $component->call('setDate', '2026-10-11')->assertSet('selectedDate', '2026-10-11');
        $this->checkRows($component->html(), 17);
        if (! $out) {
            return;
        }
        $html = $this->get('/')->assertOk()->getContent();
        file_put_contents($out.'/reactive-initial.html', $html);
        $x = $this->xpath($html);
        $snapshot = $x->query('//*[@data-live-scores]')[0]->getAttribute('wire:snapshot');
        $uri = $x->query('//script[@data-update-uri]')[0]->getAttribute('data-update-uri');
        DB::table('fixture_scores')->where('fixture_id', 18)->update(['goals_home' => 5]);
        foreach (['score' => ['handleScoreUpdate', [[]]], 'date' => ['setDate', ['2026-10-11']]] as $name => [$method,$params]) {
            $payload = ['components' => [['snapshot' => $snapshot, 'updates' => (object) [], 'calls' => [['path' => '', 'method' => $method, 'params' => $params]]]]];
            $response = $this->postJson($uri, $payload, ['X-Livewire' => 'true'])->assertOk();
            $data = $response->json();
            $this->checkRows($data['components'][0]['effects']['html'], 17);
            file_put_contents($out.'/'.$name.'-response.json', json_encode($data));
            $snapshot = $data['components'][0]['snapshot'];
        }
    }

    public function test_anchor_nonfootball_rows_remain_noninteractive(): void
    {
        $base = ['id' => 1, 'home_team_name' => 'Home', 'away_team_name' => 'Away', 'kick_off' => '2026-10-10 11:30:00', 'status_short' => 'NS'];
        foreach (['basketball', 'tennis'] as $sport) {
            $row = $base + ['type' => $sport];
            $html = view('livewire.live-scores', ['fixtures' => ['League' => [$row]], 'counts' => ['all' => 1, 'live' => 0, 'upcoming' => 1, 'finished' => 0], 'sport' => $sport, 'sportAvailable' => true, 'selectedDate' => '2026-10-10', 'prevDate' => '2026-10-09', 'nextDate' => '2026-10-11'])->render();
            $this->assertCount(1, $this->xpath($html)->query('//*[@data-status]'));
            $this->assertCount(0, $this->xpath($html)->query('//*[@data-status]//a'));
            $this->assertStringContainsString('13:30', $html);
        }
    }

    public function test_anchor_export_preimage_for_visual_parity(): void
    {
        $out = getenv('ANCHOR_EVIDENCE');
        if (! $out) {
            $this->markTestSkipped('Evidence directory not supplied');
        }
        $this->inventory();
        view()->getFinder()->prependLocation(dirname($out, 2).'/source/resources/views');
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertCount(17, $this->xpath($html)->query('//*[@data-status]'));
        $this->assertCount(0, $this->xpath($html)->query('//a[contains(@class,"match-row-link")]'));
        file_put_contents($out.'/baseline.html', $html);
    }

    public function test_anchor_retained_real_inventories_preserve_all_rows_and_destinations(): void
    {
        $root = getenv('ANCHOR_INPUT');
        $out = getenv('ANCHOR_EVIDENCE');
        if (! $root) {
            $this->markTestSkipped('Retained inventories not supplied');
        }
        foreach (glob($root.'/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            $data['prevDate'] = Carbon::parse($data['selectedDate'])->subDay()->toDateString();
            $data['nextDate'] = Carbon::parse($data['selectedDate'])->addDay()->toDateString();
            $html = view('livewire.live-scores', $data)->render();
            $rows = collect($data['fixtures'])->flatMap(fn ($g) => $g['fixtures'] ?? $g);
            $this->checkRows($html, $rows->count());
            $x = $this->xpath($html);
            $links = $x->query('//a[contains(@class,"match-row-link")]');
            foreach ($rows->values() as $i => $row) {
                $expected = '/utakmica/'.$row['id'];
                if (! empty($row['home_team_slug']) && ! empty($row['away_team_slug']) && ! empty($row['kick_off'])) {
                    $expected = '/utakmica/'.$row['home_team_slug'].'-vs-'.$row['away_team_slug'].'-'.Carbon::parse($row['kick_off'])->format('d-m-Y');
                }
                $this->assertSame($expected,$links[$i]->getAttribute('href'));
            }
            if ($out) {
                file_put_contents($out.'/real-'.basename($file,'.json').'.html',$html);
            }
        }
    }
}
