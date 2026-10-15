<?php

namespace Tests\Feature;

use App\Livewire\LiveScores;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Mechanisms\ExtendBlade\ExtendBlade;

class HtmlReductionTest extends MatchTimeUiTest
{
    public function test_fixed_snapshots_render_with_livewire_markers_without_queries(): void
    {
        $input = getenv('ANCHOR_INPUT');
        $out = getenv('ANCHOR_EVIDENCE');
        if (! $input || ! $out) {
            $this->markTestSkipped('Immutable review fixtures required');
        }
        foreach (glob($input.'/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            Carbon::setTestNow($data['selectedDate'].' 12:00:00 UTC');
            $component = new LiveScores;
            $component->setId('fixedreviewcomponent');
            foreach ($data as $key => $value) {
                $component->$key = $value;
            }
            $blade = app(ExtendBlade::class);
            $blade->startLivewireRendering($component);
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $html = $component->render()->with($data)->with('_instance', $component)->render();
                $this->assertSame([], DB::getQueryLog());
                $this->assertStringContainsString('<!--[if BLOCK]><![endif]-->', $html);
                file_put_contents($out.'/markers-'.basename($file, '.json').'.html', $html);
            } finally {
                DB::disableQueryLog();
                $blade->endLivewireRendering();
            }
        }
    }

    public function test_nonfootball_scoring_branches_remain_equivalent(): void
    {
        $out = getenv('ANCHOR_EVIDENCE');
        if (! $out) {
            $this->markTestSkipped('Review evidence directory required');
        }
        foreach (['basketball' => ['NS', 'Q1', 'Q2', 'Q3', 'Q4', 'OT', 'HT', 'FT', 'AOT'], 'tennis' => ['NS', 'LIVE', 'FT']] as $sport => $statuses) {
            $rows = [];
            foreach ($statuses as $id => $status) {
                $rows[] = ['id' => $id + 1, 'type' => $sport, 'home_team_name' => 'Čilić & Home', 'away_team_name' => 'Away <Test>', 'kick_off' => '2026-10-10 11:30:00', 'status_short' => $status, 'status_label' => $status, 'score_home' => 80, 'score_away' => 79, 'score_sets' => $status === 'NS' ? '' : '6–4  7–5', 'is_live' => $status === 'LIVE'];
            }
            $data = ['fixtures' => ['League' => $rows], 'counts' => ['all' => count($rows), 'live' => 1, 'upcoming' => 1, 'finished' => 1], 'sport' => $sport, 'sportAvailable' => true, 'selectedDate' => '2026-10-10', 'prevDate' => '2026-10-09', 'nextDate' => '2026-10-11'];
            $html = view('livewire.live-scores', $data)->render();
            $this->assertStringNotContainsString('class="match-row-link ', $html);
            $this->assertStringContainsString('13:30', $html);
            $this->assertStringNotContainsString('Away <Test>', $html);
            file_put_contents($out.'/sports-'.$sport.'.html', $html);
        }
    }
}
