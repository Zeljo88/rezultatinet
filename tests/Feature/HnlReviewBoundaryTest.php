<?php
namespace Tests\Feature;

use App\Livewire\LeaguePage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class HnlReviewBoundaryTest extends HnlLeaguePageTest
{
    private function callParent(string $method, ...$args): mixed
    {
        return (new \ReflectionMethod(HnlLeaguePageTest::class, $method))->invoke($this, ...$args);
    }

    public function test_revision_future_only_and_no_evidence_do_not_claim_current_season(): void
    {
        DB::table('fixtures')->where('league_id', 835)->delete();
        foreach ([null, '2026-10-21 07:16:01', '2027-08-01 12:00:00'] as $date) {
            $this->callParent('addFixture', $date, 'NS', 2027);
        }
        $this->callParent('addFixture', '2026-05-01 12:00:00', 'FT', 2025);
        $c = $this->callParent('leagueComponent');
        $data = $c->render()->getData();
        $this->assertSame('Aktuelna sezona', $data['seasonLabel']);
        $this->assertSame([], $data['seoRecent']);
        $this->assertSame([], $data['seoUpcoming']);
        $this->assertSame([], $c->standings);
        $html = $this->callParent('html');
        $this->assertStringContainsString('Samo budući ili stariji zapisi nisu potvrda aktuelne sezone', $html);
        $this->assertStringNotContainsString('Tablica HNL — 2027/28', $html);
        DB::table('fixtures')->where('league_id', 835)->delete();
        $this->assertStringContainsString('Aktuelna sezona nije potvrđena', $this->callParent('html'));
    }

    public function test_revision_current_past_future_seasons_preserve_results_and_current_table(): void
    {
        $this->callParent('addFixture', '2027-08-01 12:00:00', 'NS', 2027);
        $this->callParent('addFixture', '2026-10-21 06:00:00', 'FT', 2025);
        $next = $this->callParent('addFixture', '2027-02-01 12:00:00', 'NS', 2026);
        DB::table('standings')->insert(['league_id'=>835, 'team_id'=>1506, 'season'=>2026, 'rank'=>1, 'points'=>4]);
        DB::table('standings')->insert(['league_id'=>835, 'team_id'=>1507, 'season'=>2027, 'rank'=>1, 'points'=>999]);
        $c = $this->callParent('leagueComponent');
        $v = $c->render()->getData();
        $this->assertSame('2026/27', $v['seasonLabel']);
        $this->assertSame([59497,58993,58085,57428,56577], array_column($v['seoRecent'], 'id'));
        $this->assertSame([$next], array_column($v['seoUpcoming'], 'id'));
        $this->assertCount(1, $c->standings);
        $this->assertSame(2026, $c->standings[0]['season']);
        $this->assertSame(4, $c->standings[0]['points']);
    }

    public function test_revision_transition_is_evidence_driven_and_recomputed_on_hydration(): void
    {
        DB::table('fixtures')->where('league_id', 835)->delete();
        Carbon::setTestNow('2027-08-01 11:59:59 UTC');
        $this->callParent('addFixture', '2027-07-20 12:00:00', 'FT', 2026);
        $next = $this->callParent('addFixture', '2027-08-01 12:00:00', 'NS', 2027);
        $c = Livewire::test(LeaguePage::class, ['slug'=>'hnl']);
        $c->assertSee('Sezona prema evidentiranim utakmicama: 2026/27');
        Carbon::setTestNow('2027-08-01 12:00:00 UTC');
        $c->call('setTab', 'today')->assertSee('Sezona prema evidentiranim utakmicama: 2027/28')
            ->assertSee('ne potvrda službene aktuelne sezone');
        DB::table('fixtures')->where('id', $next)->update(['status_short'=>'FT']);
        $v = $this->callParent('leagueComponent')->render()->getData();
        $this->assertSame([$next], array_column($v['seoRecent'], 'id'));
        $this->assertSame([], $v['seoUpcoming']);
        Carbon::setTestNow('2028-01-01 00:00:00 UTC');
        $c->call('setTab', 'today')->assertSee('Aktuelna sezona nije potvrđena')->assertDontSee('Tablica HNL — 2028/29');
    }

    public function test_revision_exact_evidence_window_and_dst_are_utc_instants(): void
    {
        foreach (['2026-03-29 01:00:00', '2026-10-25 01:00:00'] as $now) {
            Carbon::setTestNow($now.' UTC');
            DB::table('fixtures')->where('league_id', 835)->delete();
            $id = $this->callParent('addFixture', now()->subDays(45)->format('Y-m-d H:i:s'), 'FT', 2025);
            $this->callParent('addFixture', now()->addSecond()->format('Y-m-d H:i:s'), 'NS', 2026);
            $v = $this->callParent('leagueComponent')->render()->getData();
            $this->assertSame('2025/26', $v['seasonLabel']);
            $this->assertSame([$id], array_column($v['seoRecent'], 'id'));
            $html = $this->callParent('html');
            $this->assertStringContainsString('ne potvrda službene aktuelne sezone', $html);
            $this->assertStringNotContainsString('Arhivske sezone nisu uključene.', $html);
            DB::table('fixtures')->where('id', $id)->update(['kick_off'=>now()->subDays(45)->subSecond()->format('Y-m-d H:i:s')]);
            $this->assertSame('Aktuelna sezona', $this->callParent('leagueComponent')->render()->getData()['seasonLabel']);
            Carbon::setTestNow(Carbon::parse($now.' UTC')->addSecond());
            $this->assertSame('2026/27', $this->callParent('leagueComponent')->render()->getData()['seasonLabel']);
        }
    }

    public function test_revision_blank_null_whitespace_names_both_penalty_winners_and_ssr(): void
    {
        foreach (['home'=>1506, 'away'=>1507] as $side=>$teamId) {
            foreach (['', " \t\n", "\u{00a0}", null, 'missing relation'] as $name) {
                DB::table('fixtures')->where('league_id', 835)->delete();
                // Restore the opponent, then vary the winning participant only.
                DB::table('teams')->where('id', 1506)->update(['name'=>'Dinamo Zagreb', 'slug'=>'dinamo-zagreb']);
                DB::table('teams')->where('id', 1507)->update(['name'=>'HNK Hajduk Split', 'slug'=>'hnk-hajduk-split']);
                $score = ['home_fulltime'=>1, 'away_fulltime'=>1, 'goals_home'=>1, 'goals_away'=>1,
                    'home_penalties'=>$side === 'home' ? 4 : 3, 'away_penalties'=>$side === 'away' ? 4 : 3];
                $id = $this->callParent('addFixture', '2026-10-21 06:00:00', 'PEN', 2026, 835, $score);
                if ($name === 'missing relation') {
                    DB::table('fixtures')->where('id', $id)->update([$side.'_team_id'=>999999]);
                } else {
                    DB::table('teams')->where('id', $teamId)->update(['name'=>$name, 'slug'=>null]);
                }
                $row = $this->callParent('leagueComponent')->render()->getData()['seoRecent'][0];
                $label = $side === 'home' ? 'Naziv domaće ekipe nije dostupan' : 'Naziv gostujuće ekipe nije dostupan';
                $this->assertSame($label, $row[$side.'_team_name']);
                $this->assertSame($label, $row['winner']);
                $this->assertSame([1,1], $row['result']);
                $this->assertSame([$score['home_penalties'], $score['away_penalties']], $row['penalties']);
                $this->assertNull($row['match_url']);
                $this->assertNull($row[$side.'_team_slug']);
                $html = $this->callParent('html');
                $this->assertStringContainsString('Pobjednik prema evidentiranim jedanaestercima: '.$label.'.', $html);
                $this->assertDoesNotMatchRegularExpression('/Pobjednik prema evidentiranim jedanaestercima:\s*\./u', $html);
                $this->assertStringNotContainsString('href="/tim/"', $html);
            }
        }
    }

    public function test_revision_no_penalty_winner_is_invented_for_missing_or_tied_scores(): void
    {
        foreach ([[], ['home_penalties'=>4], ['home_penalties'=>4, 'away_penalties'=>4]] as $pen) {
            DB::table('fixtures')->where('league_id', 835)->delete();
            $this->callParent('addFixture', '2026-10-21 06:00:00', 'PEN', 2026, 835,
                $pen + ['home_fulltime'=>1, 'away_fulltime'=>1, 'goals_home'=>1, 'goals_away'=>1]);
            $row = $this->callParent('leagueComponent')->render()->getData()['seoRecent'][0];
            $this->assertNull($row['winner']);
            $this->assertNull($row['penalties']);
            $this->assertStringNotContainsString('Pobjednik prema evidentiranim jedanaestercima:', $this->callParent('html'));
        }
    }

    public function test_revision_blank_standings_identity_is_explicit_and_unlinked(): void
    {
        DB::table('standings')->insert(['league_id'=>835, 'team_id'=>1506, 'season'=>2026, 'rank'=>1]);
        DB::table('teams')->where('id', 1506)->update(['name'=>" \t", 'slug'=>'dinamo-zagreb']);
        $c = $this->callParent('leagueComponent'); $c->render();
        $this->assertSame('Naziv ekipe nije dostupan', $c->standings[0]['team_name']);
        $this->assertNull($c->standings[0]['team_slug']);
        $this->assertStringContainsString('Naziv ekipe nije dostupan', $this->callParent('html'));
    }
}
