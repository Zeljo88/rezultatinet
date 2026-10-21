<?php

namespace Tests\Feature;

use App\Livewire\LeaguePage;
use Illuminate\Support\Facades\DB;

class HnlCompatibilityTest extends HnlLeaguePageTest
{
    private function fixture(...$args): int
    {
        return (new \ReflectionMethod(HnlLeaguePageTest::class, 'addFixture'))->invoke($this, ...$args);
    }

    public function test_compatibility_populated_snapshot(): void
    {
        foreach ([835, 852, 840, 1] as $league) {
            $this->fixture('2026-10-21 06:00:00', 'FT', 2026, $league, ['home_fulltime' => 2, 'away_fulltime' => 1]);
            $this->fixture('2026-10-21 05:00:00', 'PEN', 2026, $league, ['home_fulltime' => 0, 'away_fulltime' => 0, 'home_extratime' => 1, 'away_extratime' => 1, 'goals_home' => 1, 'goals_away' => 1, 'home_penalties' => 3, 'away_penalties' => 4]);
            $this->fixture('2026-10-22 10:00:00', 'NS', 2026, $league);
            $this->fixture('2027-08-01 10:00:00', 'NS', 2027, $league);
            $this->fixture('2026-10-21 04:00:00', 'FT', 2025, $league, ['home_fulltime' => 1, 'away_fulltime' => 0]);
            $row = (array) DB::table('standings')->where('league_id', 835)->first();
            unset($row['id']);
            $row['league_id'] = $league;
            $row['season'] = 2026;
            DB::table('standings')->insert($row);
        }
        $file = getenv('HNL_EVIDENCE').'/browser-compat.sqlite';
        if (!file_exists($file)) DB::statement('VACUUM INTO '.DB::getPdo()->quote($file));
        $this->assertFileExists($file);
    }

    public function test_compatibility_template_is_pinned_before_forward_and_rollback_replacement(): void
    {
        $path = resource_path('views/livewire/league-page.blade.php');
        $new = file_get_contents($path);
        $old = file_get_contents(dirname(getenv('HNL_EVIDENCE')).'/preimages/resources/views/livewire/league-page.blade.php');
        $this->fixture('2026-10-21 06:00:00', 'FT', 2026, 835, ['home_fulltime' => 2, 'away_fulltime' => 1]);
        $this->fixture('2027-08-01 10:00:00', 'NS', 2027);
        try {
            foreach ([[$old, $new, false], [$new, $old, true]] as [$initial, $replacement, $reviewed]) {
                file_put_contents($path, $initial);
                $component = new LeaguePage;
                $component->mount('hnl');
                file_put_contents($path, $replacement);
                $view = $component->render();
                $html = $view->with(get_object_vars($component))->render();
                $this->assertSame($reviewed, str_contains($html, 'data-hnl-section'));
                $this->assertSame($reviewed ? '2026/27' : '2027/28', $view->getData()['seasonLabel']);
                $this->assertStringNotContainsString('Undefined variable', $html);
                $this->assertStringNotContainsString('Undefined array key', $html);
                if (!$reviewed) $this->assertStringContainsString('Nedavni rezultati', $html);
            }
        } finally {
            file_put_contents($path, $new);
        }
    }

    public function test_compatibility_partial_unknown_and_wrong_league_contracts_use_baseline(): void
    {
        $path = resource_path('views/livewire/league-page.blade.php');
        $new = file_get_contents($path);
        $old = file_get_contents(dirname(getenv('HNL_EVIDENCE')).'/preimages/resources/views/livewire/league-page.blade.php');
        try {
            foreach (['hnl', 'premijer-liga-bih', 'superliga-srbija', 'premier-league'] as $slug) {
                file_put_contents($path, $old);
                $component = new LeaguePage;
                $component->mount($slug);
                $data = $component->render()->getData() + get_object_vars($component);
                file_put_contents($path, $new);
                foreach ([null, [], ['version' => 1], ['version' => 2, 'leagueId' => 835, 'slug' => 'hnl'], ['version' => 1, 'leagueId' => 852, 'slug' => 'hnl', 'data' => $data]] as $contract) {
                    $html = view('livewire.league-page', array_replace($data, ['hnlPage' => $contract]))->render();
                    $this->assertStringNotContainsString('data-hnl-section', $html);
                    $this->assertStringContainsString('Nedavni rezultati', $html);
                }
            }
        } finally {
            file_put_contents($path, $new);
        }
    }
}
