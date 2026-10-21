<?php
namespace Tests\Feature;
use App\Livewire\LeaguePage;
use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
class IndependentHnlReviewTest extends HnlLeaguePageTest {
 private function parentCall(string $method,...$args): mixed { return (new \ReflectionMethod(HnlLeaguePageTest::class,$method))->invoke($this,...$args); }
 public function test_independent_future_season_must_not_displace_active_results(): void {
  $this->parentCall('addFixture','2027-08-01 12:00:00','NS',2027);
  $c=$this->parentCall('leagueComponent'); $v=$c->render()->getData();
  file_put_contents(getenv('HNL_EVIDENCE').'/future-season-repro.json',json_encode(['now'=>now()->toIso8601String(),'label'=>$v['seasonLabel'],'recent_ids'=>array_column($v['seoRecent'],'id'),'upcoming_ids'=>array_column($v['seoUpcoming'],'id')],JSON_PRETTY_PRINT));
  $this->assertSame('2026/27',$v['seasonLabel'],'A next-season NS row must not suppress the five actual current-season results');
 }
 public function test_independent_missing_winning_team_needs_honest_label(): void {
  $id=$this->parentCall('addFixture','2026-10-21 06:00:00','PEN',2026,835,['home_fulltime'=>1,'away_fulltime'=>1,'goals_home'=>1,'goals_away'=>1,'home_penalties'=>4,'away_penalties'=>3]);
  DB::table('fixtures')->where('id',$id)->update(['home_team_id'=>999999]);
  $html=$this->parentCall('html');file_put_contents(getenv('HNL_EVIDENCE').'/missing-winner.html',$html);
  $this->assertDoesNotMatchRegularExpression('/Pobjednik prema evidentiranim jedanaestercima:\s*\./u',$html,'Do not publish an empty winner sentence when the winning team relation is absent');
 }
 public function test_independent_empty_team_name_needs_visible_placeholder(): void {
  DB::table('teams')->where('id',1506)->update(['name'=>'','slug'=>null]);
  $id=$this->parentCall('addFixture','2026-10-21 06:00:00','FT',2026,835,['home_fulltime'=>0,'away_fulltime'=>0]);
  $c=$this->parentCall('leagueComponent');$rows=$c->render()->getData()['seoRecent'];
  $row=collect($rows)->firstWhere('id',$id);file_put_contents(getenv('HNL_EVIDENCE').'/empty-name-repro.json',json_encode($row,JSON_PRETTY_PRINT));
  $this->assertNotSame('',trim($row['home_team_name']),'Missing team name must have honest visible placeholder');
 }
 public function test_independent_hydrated_arrays_are_reloaded(): void {
  Livewire::test(LeaguePage::class,['slug'=>'hnl'])->set('standings',[['team_name'=>'FORGED_TABLE','points'=>999]])->assertDontSee('FORGED_TABLE')->set('fixtures',[['home_team_name'=>'FORGED_FIXTURE']])->assertDontSee('FORGED_FIXTURE')->call('setTab','tomorrow')->assertSee('Posljednjih 5 dostupnih rezultata');
 }
 public function test_independent_extra_score_edge_cases(): void {
  $c=$this->parentCall('leagueComponent');
  foreach([
   ['FT',['home_fulltime'=>-1,'away_fulltime'=>0],null],
   ['FT',['home_fulltime'=>0,'goals_home'=>0,'goals_away'=>0],[0,0]],
   ['AET',['home_extratime'=>1,'away_extratime'=>1,'goals_home'=>1,'goals_away'=>1],null],
   ['PEN',['home_fulltime'=>1,'away_fulltime'=>0,'goals_home'=>1,'goals_away'=>0,'home_penalties'=>4,'away_penalties'=>3],null],
   ['PEN',['home_fulltime'=>0,'away_fulltime'=>0,'goals_home'=>0,'goals_away'=>0,'home_penalties'=>0,'away_penalties'=>1],[0,0]],
  ] as [$status,$score,$expected]) {
   $f=new Fixture(['id'=>123,'kick_off'=>'2026-10-20 10:00:00','status_short'=>$status]);$f->setRelation('homeTeam',Team::find(1506));$f->setRelation('awayTeam',Team::find(1507));$f->setRelation('score',new FixtureScore($score));
   $row=(new \ReflectionMethod(LeaguePage::class,'hnlFixture'))->invoke($c,$f);$this->assertSame($expected,$row['result']);
  }
 }
}
