<?php

namespace Tests\Feature;

use App\Livewire\LiveScores;
use App\Livewire\MatchDetail;
use App\Models\Fixture;
use App\Models\Team;
use App\Support\MatchRoute;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

class MatchAnchorsTest extends MatchTimeUiTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('fixture_polls', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fixture_id');
            $table->string('vote');
            $table->string('voter_ip');
        });
        Schema::create('fixture_lineups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fixture_id');
            $table->string('team_side');
        });
    }

    private function canonical(string $html): string
    {
        $links = $this->xpath($html)->query('//link[@rel="canonical"]');
        $this->assertCount(1, $links);

        return $links[0]->getAttribute('href');
    }

    private function assertTargetCanonical(string $href, int $id): void
    {
        // Exercise the existing HTTP detail route/layout, not a copy of the slug algorithm.
        Livewire::flushState();
        $numeric = $this->get('/utakmica/'.$id)->assertOk()->getContent();
        $this->assertSame(url($href), $this->canonical($numeric));
        Livewire::flushState();
        $target = $this->get($href)->assertOk()->getContent();
        $this->assertSame(url($href), $this->canonical($target));
    }

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
        $keys = [];
        foreach ($rows as $row) {
            $keys[] = $row->getAttribute('wire:key');
            $this->assertStringStartsWith('match-row-', $row->getAttribute('wire:key'));
            $this->assertFalse($row->hasAttribute('onclick'));
            $a = $x->query('.//a[starts-with(@href,"/utakmica/")]', $row);
            $this->assertCount(1, $a);
            $this->assertMatchesRegularExpression('~^/utakmica/(?:[0-9]+|[a-z0-9-]+-vs-[a-z0-9-]+-[0-9]{2}-[0-9]{2}-[0-9]{4})$~', $a[0]->getAttribute('href'));
            $this->assertNotEmpty($a[0]->getAttribute('aria-label'));
            $this->assertCount(0, $x->query('.//a|.//button|.//input|.//*[@tabindex]', $a[0]));
        }
        $this->assertCount($count, array_unique($keys));
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
            foreach ($this->xpath($html)->query('//a[contains(@class,"match-row-link")]') as $link) {
                $href = $link->getAttribute('href');
                $fixture = MatchRoute::resolve(basename($href));
                $this->assertNotNull($fixture);
                $this->assertTargetCanonical($href, $fixture->id);
            }
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
        $detail = new MatchDetail;
        $detail->fixture = Fixture::with(['homeTeam', 'awayTeam'])->findOrFail($id);
        $href = $this->xpath($html)->query('//a[contains(@class,"match-row-link")]')[0]->getAttribute('href');
        $this->assertSame($detail->getCanonicalUrl(), url($href));
        $this->assertNotSame('/utakmica/'.$id, $href);
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

    public function test_missing_stored_slugs_use_existing_name_fallback_or_numeric_contract(): void
    {
        $id = DB::table('fixtures')->insertGetId(['api_fixture_id' => 55842, 'league_id' => 1, 'home_team_id' => 1506, 'away_team_id' => 1507, 'season' => 2026, 'kick_off' => '2026-10-10 11:30:00', 'status_short' => 'NS']);
        foreach ([
            [null, 'Sabah FA', '', 'Slavia Praha', 'sabah-fa-vs-slavia-praha-10-10-2026'],
            ['   ', 'Sabah FA', 'slavia-praha', 'Slavia Praha', 'sabah-fa-vs-slavia-praha-10-10-2026'],
            ['zeljeznicar', 'Željezničar & "Sarajevo"', 'slavia-praha', 'Slavia Praha', 'zeljeznicar-vs-slavia-praha-10-10-2026'],
            [null, '!!!', null, 'Slavia Praha', (string) $id],
        ] as [$homeSlug, $homeName, $awaySlug, $awayName, $destination]) {
            DB::table('teams')->where('id', 1506)->update(['slug' => $homeSlug, 'name' => $homeName]);
            DB::table('teams')->where('id', 1507)->update(['slug' => $awaySlug, 'name' => $awayName]);
            Livewire::flushState();
            $html = $this->get('/')->assertOk()->getContent();
            $a = $this->xpath($html)->query('//a[contains(@class,"match-row-link")]')[0];
            $this->assertTargetCanonical($a->getAttribute('href'), $id);
            $this->assertSame('/utakmica/'.$destination, $a->getAttribute('href'));
            $this->assertSame($homeName.' — '.$awayName, $a->getAttribute('aria-label'));
        }
    }

    public function test_render_is_query_free_at_one_and_many_rows_with_escaped_slugs(): void
    {
        $base = ['id' => 19, 'home_team_name' => 'Željezničar <script>x</script> & "', 'away_team_name' => 'Étoile', 'home_team_slug' => null, 'away_team_slug' => null, 'kick_off' => '2026-10-10 11:30:00', 'status_short' => 'NS'];
        foreach ([1, 200] as $count) {
            foreach ([null, 'team" onmouseover="alert(1)&<x>'] as $slug) {
                $row = array_replace($base, ['home_team_slug' => $slug]);
                DB::flushQueryLog();
                DB::enableQueryLog();
                $html = view('livewire.live-scores', ['fixtures' => ['League' => array_fill(0, $count, $row)], 'counts' => ['all' => $count, 'live' => 0, 'upcoming' => $count, 'finished' => 0], 'sport' => 'football', 'sportAvailable' => true, 'selectedDate' => '2026-10-10', 'prevDate' => '2026-10-09', 'nextDate' => '2026-10-11'])->render();
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                $this->assertSame([], $queries, 'Rendering must never perform per-row slug/relationship lookups');
                $x = $this->xpath($html);
                $this->assertCount($count, $x->query('//a[contains(@class,"match-row-link")]'));
                $this->assertCount(0, $x->query('//*[@onmouseover]|//x'));
                $this->assertStringNotContainsString('<script>x</script>', $html);
                $model = new Fixture(['kick_off' => $row['kick_off']]);
                $model->id = $row['id'];
                $model->setRelation('homeTeam', new Team(['name' => $row['home_team_name'], 'slug' => $slug]));
                $model->setRelation('awayTeam', new Team(['name' => $row['away_team_name'], 'slug' => null]));
                $detail = new MatchDetail;
                $detail->fixture = $model;
                foreach ($x->query('//a[contains(@class,"match-row-link")]') as $a) {
                    $this->assertSame($detail->getCanonicalUrl(), url($a->getAttribute('href')));
                }
            }
        }
    }

    public function test_anchor_export_readonly_browser_fixtures(): void
    {
        $out = getenv('ANCHOR_EVIDENCE');
        if (! $out) {
            $this->markTestSkipped('Evidence directory not supplied');
        }
        $this->inventory();
        // Disposable local SQLite fixtures only; browser later opens them mode=ro/immutable.
        DB::table('teams')->where('id', 2000)->update(['slug' => null]);
        $pdo = DB::connection()->getPdo();
        foreach (['before', 'score', 'reorder'] as $state) {
            if ($state === 'score') {
                DB::table('fixtures')->where('id', 18)->update(['status_short' => '1H', 'elapsed_minute' => 3]);
                DB::table('fixture_scores')->where('fixture_id', 18)->update(['goals_home' => 5]);
            }
            if ($state === 'reorder') {
                DB::table('fixtures')->where('id', 19)->update(['kick_off' => '2026-10-10 10:30:00']);
            }
            $file = $out.'/browser-'.$state.'.sqlite';
            if (is_file($file)) {
                rename($file, $file.'.previous-'.uniqid());
            }
            $pdo->exec('VACUUM INTO '.$pdo->quote($file));
            $this->assertFileExists($file);
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

    public function test_anchor_retained_real_inventories_match_detail_canonical_for_every_row(): void
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
                $model = new Fixture;
                $model->id = $row['id'];
                $model->kick_off = $row['kick_off'];
                foreach (['home', 'away'] as $side) {
                    $team = new Team;
                    $team->name = $row[$side.'_team_name'];
                    $team->slug = $row[$side.'_team_slug'];
                    $model->setRelation($side.'Team', $team);
                }
                $detail = new MatchDetail;
                $detail->fixture = $model;
                $this->assertSame($detail->getCanonicalUrl(), url($links[$i]->getAttribute('href')));
            }
            if ($out) {
                file_put_contents($out.'/real-'.basename($file, '.json').'.html', $html);
            }
        }
    }
}
