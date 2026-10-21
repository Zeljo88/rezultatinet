<?php

namespace Tests\Feature;

use App\Livewire\LeaguePage;
use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Models\Team;
use App\Support\MatchRoute;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class HnlLeaguePageTest extends TestCase
{
    private array $columns = [];
    private int $serial = 900000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('app.key', str_repeat('a', 32));
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-21 07:16:00 UTC');
        $this->assertSame(base_path('app/Livewire/LeaguePage.php'), (new \ReflectionClass(LeaguePage::class))->getFileName());
        $metadata = json_decode(file_get_contents(getenv('HNL_AUDIT').'/db-metadata.json'), true);
        foreach ($metadata['tables'] as $name => $table) {
            $this->columns[$name] = array_column($table['columns'], 'Field');
            Schema::create($name, function (Blueprint $t) use ($table): void {
                foreach ($table['columns'] as $c) {
                    if ($c['Field'] === 'id') { $t->id(); continue; }
                    $field = str_contains($c['Type'], 'int') ? $t->integer($c['Field']) : $t->text($c['Field']);
                    $field->nullable();
                }
                $indexes = [];
                foreach ($table['indexes'] as $i) {
                    if ($i['Key_name'] !== 'PRIMARY') $indexes[$i['Key_name']][] = $i['Column_name'];
                }
                foreach ($indexes as $key => $cols) $t->index($cols, $key);
            });
        }
        foreach (['fixture_events', 'fixture_lineups', 'fixture_polls'] as $name) {
            Schema::create($name, function (Blueprint $t): void {
                $t->id(); $t->integer('fixture_id'); $t->text('team_side')->nullable();
                $t->text('vote')->nullable(); $t->text('voter_ip')->nullable();
            });
        }
        Schema::create('player_stats', function (Blueprint $t): void {
            $t->id(); $t->integer('league_id'); $t->integer('goals'); $t->integer('player_id')->nullable();
        });
        $data = json_decode(file_get_contents(getenv('HNL_AUDIT').'/db.json'), true);
        foreach ($data['leagues'] as $row) $this->insert('leagues', $row + ['logo_url' => 'https://media.api-sports.io/football/leagues/'.$row['api_league_id'].'.png']);
        foreach ($data['teams'] as $row) $this->insert('teams', $row + ['logo_url' => 'https://media.api-sports.io/football/teams/'.$row['api_team_id'].'.png']);
        foreach ($data['data'] as $league) {
            foreach ($league['standings'] as $row) $this->insert('standings', $row);
            foreach ($league['fixtures_2026'] as $row) {
                $this->insert('fixtures', $row);
                $score = array_intersect_key($row, array_flip($this->columns['fixture_scores']));
                unset($score['id'], $score['created_at']);
                $this->insert('fixture_scores', array_merge($score, ['fixture_id' => $row['id'], 'updated_at' => $row['score_updated_at']]));
            }
        }
        $this->insert('leagues', ['id' => 1, 'api_league_id' => 39, 'name' => 'Premier League', 'current_season' => 2025]);
        $this->addFixture('2026-10-18 10:00:00', 'FT', 2026, 1, ['home_fulltime'=>0,'away_fulltime'=>0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); Http::assertNothingSent(); parent::tearDown();
    }

    private function insert(string $table, array $row): void
    {
        DB::table($table)->insert(array_intersect_key($row, array_flip($this->columns[$table])));
    }

    private function addFixture(?string $date, string $status = 'NS', int $season = 2026, int $league = 835, array $score = []): int
    {
        $id = ++$this->serial;
        $this->insert('fixtures', ['id'=>$id,'api_fixture_id'=>$id,'league_id'=>$league,'home_team_id'=>1506,'away_team_id'=>1507,'season'=>$season,'kick_off'=>$date,'status_short'=>$status]);
        if ($score) $this->insert('fixture_scores', $score + ['fixture_id'=>$id]);
        return $id;
    }

    private function leagueComponent(string $slug = 'hnl'): LeaguePage
    {
        $c = new LeaguePage; $c->mount($slug); return $c;
    }

    private function invoke(LeaguePage $c, string $method, ...$args): mixed
    {
        return (new \ReflectionMethod($c, $method))->invoke($c, ...$args);
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument; @$doc->loadHTML('<?xml encoding="UTF-8">'.$html); return new DOMXPath($doc);
    }

    private function html(string $slug = 'hnl'): string
    {
        Livewire::flushState(); return $this->get('/liga/'.$slug)->assertOk()->getContent();
    }

    public function test_fixed_baseline_season_empty_states_and_ten_component_selects(): void
    {
        Cache::put('standings:835', [['points'=>999]], 300);
        DB::enableQueryLog(); DB::flushQueryLog();
        $c = $this->leagueComponent(); $v = $c->render();
        $queries = DB::getQueryLog();
        $this->assertCount(10, $queries);
        $this->assertSame('2026/27', $v->getData()['seasonLabel']);
        $this->assertSame([], $c->standings);
        $this->assertSame([['points'=>999]], Cache::get('standings:835'));
        $this->assertFalse(Cache::has('top_scorers:835'));
        $this->assertSame([59497,58993,58085,57428,56577], array_column($v->getData()['seoRecent'],'id'));
        $this->assertSame([], $v->getData()['seoUpcoming']);
        $before = count(DB::getQueryLog()); $v->with(get_object_vars($c))->render();
        $this->assertCount($before, DB::getQueryLog());
        $html = $this->html(); $x = $this->xpath($html);
        $this->assertCount(5, $x->query('//*[@data-hnl-section="recent"]//*[@data-hnl-match]'));
        $this->assertCount(1, $x->query('//*[@id="tablica-2026-27" and @tabindex="-1"]'));
        $this->assertCount(0, $x->query('//main//a//a'));
        foreach (['Tablica za sezonu 2026/27 još nije dostupna.', 'Trenutačno nemamo evidentirane naredne utakmice za sezonu 2026/27.', '18.10.2026.', 'Druge sezone nisu uključene.', 'Vrijeme zadnje izmjene nije dostupno.'] as $text) $this->assertStringContainsString($text,$html);
        foreach (['/tim/rudes','/liga/hnl/tablica','Ekipe u poretku','adsbygoogle','meridian-popup','kickoffMs:'] as $text) $this->assertStringNotContainsString($text,$html);
        $this->assertStringContainsString('rudes-vs-hnk-rijeka-16-10-2026',$html);
        file_put_contents(getenv('HNL_EVIDENCE').'/baseline-queries.json',json_encode($queries,JSON_PRETTY_PRINT));
    }

    public function test_strict_future_ns_last_five_statuses_ties_and_mixed_seasons(): void
    {
        $now = now()->format('Y-m-d H:i:s');
        foreach (['NS','TBD','PST','CANC','ABD','AWD','WO','SUSP','INT','1H','2H','HT','ET','BT','P','LIVE','FT','AET','PEN'] as $status) {
            $this->addFixture('2026-10-22 12:00:00', $status);
            $this->addFixture('2026-10-20 12:00:00', $status);
        }
        $this->addFixture($now,'NS'); $this->addFixture(null,'NS');
        $this->addFixture('2026-10-22 10:00:00','NS',2025);
        $this->addFixture('2026-10-21 06:00:00','FT',2025);
        $expected=[];
        for($i=0;$i<8;$i++) $expected[]=$this->addFixture('2026-10-22 11:00:00');
        $recent=[];
        foreach(['FT','AET','PEN','FT','PEN','AET'] as $status) $recent[]=$this->addFixture('2026-10-21 06:00:00',$status);
        $c=$this->leagueComponent();
        $this->assertSame(array_slice($expected,0,5),array_column($this->invoke($c,'getUpcomingFixtures'),'id'));
        $this->assertSame(array_slice(array_reverse($recent),0,5),array_column($this->invoke($c,'getRecentFixtures'),'id'));
        // No calendar window: a retained confirmed fixture months away is eligible.
        DB::table('fixtures')->where('league_id',835)->where('status_short','NS')->delete();
        $far=$this->addFixture('2027-02-01 11:00:00');
        $this->assertSame([$far],array_column($this->invoke($this->leagueComponent(),'getUpcomingFixtures'),'id'));
    }

    public function test_no_fixtures_unknown_season_does_not_roll_over_or_show_archived_points(): void
    {
        DB::table('fixtures')->where('league_id',835)->delete();
        $this->addFixture('2026-05-01 10:00:00','FT',2025);
        foreach (['2026-10-21 07:16:00','2027-01-01 07:16:00'] as $now) {
            Carbon::setTestNow($now.' UTC');
            $html=$this->html();
            $this->assertStringContainsString('Aktuelna sezona nije potvrđena',$html);
            $this->assertStringContainsString('tablica-dostupnost',$html);
            $this->assertStringNotContainsString('2027/28',$html);
            $this->assertCount(0,$this->xpath($html)->query('//main//table'));
        }
        // An evidence-backed archived start year is displayed as that year, not rewritten annually.
        Carbon::setTestNow('2026-05-10 10:00:00 UTC');
        $html=$this->html();
        $this->assertStringContainsString('Sezona prema evidentiranim utakmicama: 2025/26',$html);
        $this->assertCount(10,$this->xpath($html)->query('//main//table/tbody/tr'));
    }

    public function test_standings_exact_season_null_fields_and_actual_modification_range(): void
    {
        $row=DB::table('standings')->where('league_id',835)->first();
        $row=(array)$row; unset($row['id']);
        $row['season']=2026; $row['updated_at']='2026-10-19 10:00:00'; $row['points']=0;
        $this->insert('standings',$row);
        $row['team_id']=2743;$row['rank']=2;$row['updated_at']='2026-10-20 10:00:00';$row['played']=null;
        $this->insert('standings',$row);
        $html=$this->html(); $x=$this->xpath($html);
        $this->assertCount(2,$x->query('//main//table/tbody/tr'));
        foreach(['Podaci možda nisu ažurni','19.10.2026 12:00 CEST','20.10.2026 12:00 CEST','Potpunost i točnost tablice nisu neovisno potvrđene.'] as $t) $this->assertStringContainsString($t,$html);
        $this->assertStringNotContainsString('/tim/rudes',$html);
        DB::table('standings')->where('season',2026)->update(['updated_at'=>null]);
        $this->assertStringContainsString('Vrijeme zadnje izmjene nije dostupno za sve zapise.',$this->html());
    }

    public function test_maximum_eighteen_queries_no_row_or_blade_n_plus_one_and_indexed_plans(): void
    {
        for($i=0;$i<10;$i++) $this->addFixture('2026-10-21 16:00:00');
        $row=(array)DB::table('standings')->where('league_id',835)->first();unset($row['id']);$row['season']=2026;$this->insert('standings',$row);
        DB::enableQueryLog();DB::flushQueryLog(); $c=$this->leagueComponent();$v=$c->render();
        $queries=DB::getQueryLog();$this->assertCount(18,$queries);
        $plans=[];
        foreach($queries as $q) {
            $this->assertStringStartsWith('select',strtolower($q['query']));
            $plans[]=['query'=>$q,'plan'=>DB::select('EXPLAIN QUERY PLAN '.$q['query'],$q['bindings'])];
        }
        foreach([1,5,10] as $n) {
            $fixtures=Fixture::with(['homeTeam','awayTeam','score'])->where('league_id',835)->limit($n)->get();
            DB::flushQueryLog();foreach($fixtures as $f) $this->invoke($c,'hnlFixture',$f);
            $v->with(get_object_vars($c))->render();$this->assertSame([],DB::getQueryLog());
        }
        file_put_contents(getenv('HNL_EVIDENCE').'/populated-query-plans.json',json_encode($plans,JSON_PRETTY_PRINT));
    }

    public function test_score_semantics_ft_aet_pen_null_zero_partial_conflict_and_winner(): void
    {
        $cases=[
            ['FT',[],null,null,null],
            ['FT',['home_fulltime'=>2,'away_fulltime'=>1,'home_extratime'=>0],null,null,null],
            ['AET',['home_extratime'=>2,'away_extratime'=>1,'goals_home'=>2,'goals_away'=>1,'home_penalties'=>1],null,null,null],
            ['AET',['home_fulltime'=>3,'home_extratime'=>2,'away_extratime'=>1,'goals_home'=>2,'goals_away'=>1],null,null,null],
            ['FT',['home_fulltime'=>0,'away_fulltime'=>0],[0,0],null,null],
            ['FT',['goals_home'=>2,'goals_away'=>1],[2,1],null,null],
            ['FT',['home_fulltime'=>2,'away_fulltime'=>1,'goals_home'=>3,'goals_away'=>1],null,null,null],
            ['FT',['home_fulltime'=>2,'goals_away'=>1],null,null,null],
            ['FT',['home_fulltime'=>2,'goals_home'=>3,'goals_away'=>1],null,null,null],
            ['AET',['home_fulltime'=>1,'away_fulltime'=>1],null,null,null],
            ['AET',['home_fulltime'=>1,'away_fulltime'=>1,'home_extratime'=>2,'away_extratime'=>1,'goals_home'=>2,'goals_away'=>1],[2,1],null,null],
            ['AET',['home_extratime'=>2,'away_extratime'=>1],null,null,null],
            ['AET',['home_extratime'=>1,'away_extratime'=>0,'goals_home'=>2,'goals_away'=>1],null,null,null],
            ['AET',['home_fulltime'=>3,'away_fulltime'=>1,'home_extratime'=>2,'away_extratime'=>1,'goals_home'=>2,'goals_away'=>1],null,null,null],
            ['PEN',['home_fulltime'=>0,'away_fulltime'=>0,'home_extratime'=>1,'away_extratime'=>1,'goals_home'=>1,'goals_away'=>1,'home_penalties'=>3,'away_penalties'=>4],[1,1],[3,4],'HNK Hajduk Split'],
            ['PEN',['home_fulltime'=>0,'away_fulltime'=>0,'home_extratime'=>1,'away_extratime'=>1,'goals_home'=>1,'goals_away'=>1,'home_penalties'=>4,'away_penalties'=>3],[1,1],[4,3],'Dinamo Zagreb'],
            ['PEN',['home_fulltime'=>0,'away_fulltime'=>0,'goals_home'=>0,'goals_away'=>0,'home_penalties'=>4,'away_penalties'=>3],[0,0],[4,3],'Dinamo Zagreb'],
            ['PEN',['home_fulltime'=>0,'away_fulltime'=>0,'goals_home'=>0,'goals_away'=>0,'home_penalties'=>4,'away_penalties'=>4],null,null,null],
            ['PEN',['home_fulltime'=>0,'away_fulltime'=>0,'goals_home'=>0,'goals_away'=>0,'home_penalties'=>4],null,null,null],
            ['PEN',['home_fulltime'=>0,'away_fulltime'=>0,'home_extratime'=>1,'goals_home'=>0,'goals_away'=>0,'home_penalties'=>4,'away_penalties'=>3],null,null,null],
        ];
        $c=$this->leagueComponent();
        foreach($cases as [$status,$score,$result,$pen,$winner]) {
            $f=new Fixture(['id'=>11,'kick_off'=>'2026-10-18 10:00:00','status_short'=>$status]);
            $f->setRelation('homeTeam',Team::find(1506));$f->setRelation('awayTeam',Team::find(1507));$f->setRelation('score',new FixtureScore($score));
            $mapped=$this->invoke($c,'hnlFixture',$f);
            $this->assertSame($result,$mapped['result'],json_encode([$status,$score]));
            $this->assertSame($pen,$mapped['penalties']);$this->assertSame($winner,$mapped['winner']);
            if($status==='AET') $this->assertStringContainsString('nakon produžetaka',$mapped['status_label']);
            if($status==='PEN') $this->assertStringContainsString('nakon jedanaesteraca',$mapped['status_label']);
        }
        $f->setRelation('homeTeam',new Team(['name'=>'','slug'=>null]));
        $this->assertNull($this->invoke($c,'hnlFixture',$f)['match_url']);
        $this->addFixture('2026-10-21 06:00:00','PEN',2026,835,$cases[14][1]);
        $html=$this->html();$this->assertStringContainsString('Igra 1–1; jedanaesterci 3–4.',$html);
        $this->assertStringNotContainsString('Igra 4–5',$html);
    }

    public function test_zagreb_midnight_spring_and_autumn_dst_tabs_and_utc_canonical_date(): void
    {
        foreach([
            ['2026-10-21','2026-10-20 22:00:00','2026-10-21 22:00:00'],
            ['2026-03-29','2026-03-28 23:00:00','2026-03-29 22:00:00'],
            ['2026-10-25','2026-10-24 22:00:00','2026-10-25 23:00:00'],
        ] as [$day,$start,$end]) {
            DB::table('fixtures')->where('league_id',835)->delete();Carbon::setTestNow($day.' 10:00:00 UTC');
            $this->addFixture(Carbon::parse($start)->subSecond()->format('Y-m-d H:i:s'));
            $a=$this->addFixture($start);$b=$this->addFixture(Carbon::parse($end)->subSecond()->format('Y-m-d H:i:s'));$this->addFixture($end);
            $this->addFixture($start,'NS',2025);
            $c=$this->leagueComponent();$this->assertSame([$b,$a],array_column($c->fixtures,'id'));
            $this->assertStringEndsWith(Carbon::parse($start)->format('d-m-Y'),$c->fixtures[1]['match_url']);
            $html=$this->html();$this->assertStringContainsString('00:00',$html);
            $c->setTab('yesterday');$this->assertCount(1,$c->fixtures);
            $c->setTab('tomorrow');$this->assertCount(1,$c->fixtures);
        }
    }

    public function test_all_baseline_team_and_match_destinations_are_self_canonical(): void
    {
        $html=$this->html();$x=$this->xpath($html);$hrefs=[];
        foreach($x->query('//main//a[contains(@href,"/utakmica/") or starts-with(@href,"/tim/")]') as $a) $hrefs[$a->getAttribute('href')]=true;
        $this->assertCount(14,$hrefs); // five matches, nine stored team slugs; Rudes stays plain text.
        foreach(array_keys($hrefs) as $href) {
            Livewire::flushState();$response=$this->get($href)->assertOk();
            $canon=$this->xpath($response->getContent())->query('//link[@rel="canonical"]');
            $this->assertCount(1,$canon);$this->assertSame(url($href),$canon[0]->getAttribute('href'));
        }
        foreach(Fixture::with(['homeTeam','awayTeam'])->where('league_id',835)->get() as $f) $this->assertSame($f->id,MatchRoute::resolve(MatchRoute::slugFor($f))->id);
    }

    public function test_livewire_tabs_keep_stable_modules_and_recompute_season_per_request(): void
    {
        $c=Livewire::test(LeaguePage::class,['slug'=>'hnl']);
        foreach(['yesterday','tomorrow','recent','today'] as $tab) $c->call('setTab',$tab)->assertSee('Posljednjih 5 dostupnih rezultata')->assertSee('Tablica za sezonu 2026/27 još nije dostupna.')->assertSee('rudes-vs-hnk-rijeka-16-10-2026',false);
        DB::table('fixtures')->where('league_id',835)->delete();
        $c->call('setView','standings')->assertSee('Aktuelna sezona nije potvrđena')->assertDontSee('Tablica za sezonu 2026/27');
    }

    public function test_populated_ssr_partial_data_and_today_results_never_invent_zero(): void
    {
        // New synthetic current rows; never relabel the retained May archived table.
        foreach([1506,1507,1508,1509,1510,1511,1512,1513,1447,2743] as $i=>$team) {
            $this->insert('standings',['league_id'=>835,'team_id'=>$team,'season'=>2026,'rank'=>$i+1,
                'played'=>2,'win'=>1,'draw'=>1,'lose'=>0,'goals_for'=>3,'goals_against'=>1,
                'goal_diff'=>2,'points'=>4,'updated_at'=>'2026-10-20 06:00:00']);
        }
        $aet=['home_fulltime'=>1,'away_fulltime'=>1,'home_extratime'=>2,'away_extratime'=>1,'goals_home'=>2,'goals_away'=>1];
        $pen=['home_fulltime'=>0,'away_fulltime'=>0,'home_extratime'=>1,'away_extratime'=>1,'goals_home'=>1,'goals_away'=>1,'home_penalties'=>3,'away_penalties'=>4];
        $ids=[];
        $ids[]=$this->addFixture('2026-10-21 01:00:00','AET',2026,835,$aet);
        $ids[]=$this->addFixture('2026-10-21 02:00:00','PEN',2026,835,$pen);
        $missing=$this->addFixture('2026-10-21 03:00:00','FT');
        $ids[]=$missing;
        foreach($ids as $i=>$id) DB::table('fixtures')->where('id',$id)->update(['home_team_id'=>[1509,1511,2743][$i]]);
        for($i=0;$i<7;$i++) $this->addFixture('2026-10-'.(22+$i).' 10:00:00');
        $html=$this->html();$x=$this->xpath($html);
        $this->assertCount(3,$x->query('//*[@data-hnl-section="selected"]//*[@data-hnl-match]'));
        $this->assertCount(5,$x->query('//*[@data-hnl-section="recent"]//*[@data-hnl-match]'));
        $this->assertCount(5,$x->query('//*[@data-hnl-section="upcoming"]//*[@data-hnl-match]'));
        $this->assertCount(10,$x->query('//main//table/tbody/tr'));
        foreach($x->query('//*[@data-hnl-match="'.$missing.'"]') as $row) {
            $this->assertStringContainsString('Rezultat nije dostupan',$row->textContent);
            $this->assertStringNotContainsString('0–0',$row->textContent);
        }
        $this->assertStringContainsString('AET — nakon produžetaka',$html);
        $this->assertStringContainsString('PEN — nakon jedanaesteraca',$html);
        $this->assertCount(0,$x->query('//main//a//a'));
        // All synthetic next5 hrefs exercise the existing resolver, with UTC dates.
        $v=$this->leagueComponent()->render()->getData();
        foreach($v['seoUpcoming'] as $row) {
            $resolved=MatchRoute::resolve(basename($row['match_url']));
            $this->assertNotNull($resolved);
            $this->assertSame($row['id'],$resolved->id);
            $this->assertSame($row['match_url'],url('/utakmica/'.MatchRoute::slugFor($resolved)));
        }
        $file=getenv('HNL_EVIDENCE').'/browser-populated.sqlite';
        if(!file_exists($file)) DB::statement('VACUUM INTO '.DB::getPdo()->quote($file));
    }

    public function test_capture_fixed_render_for_comparison(): void
    {
        $variant=getenv('HNL_VARIANT') ?: 'after';$out=getenv('HNL_EVIDENCE');
        $receipts=[];
        foreach(['hnl','premijer-liga-bih','superliga-srbija','premier-league'] as $slug) {
            Cache::flush(); DB::enableQueryLog();DB::flushQueryLog();$start=hrtime(true);$html=$this->html($slug);$elapsed=(hrtime(true)-$start)/1e6;
            $queries=DB::getQueryLog();DB::disableQueryLog();
            file_put_contents($out.'/'.$variant.'-'.$slug.'.html',$html);
            $receipts[$slug]=['decoded'=>strlen($html),'gzip9'=>strlen(gzencode($html,9)),'http_render_ms'=>$elapsed,'http_selects'=>count($queries),'queries'=>$queries];
        }
        file_put_contents($out.'/'.$variant.'-render.json',json_encode($receipts,JSON_PRETTY_PRINT));
        $file=$out.'/browser.sqlite';
        if(!file_exists($file)) DB::statement('VACUUM INTO '.DB::getPdo()->quote($file));
        $this->assertTrue(file_exists($file));
    }
}
