<?php
namespace App\Livewire;

use App\Models\Fixture;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class RefereeStats extends Component
{
    public string $slug = '';
    public ?array $referee = null;
    public array $referees = [];
    public array $recentMatches = [];

    public function mount(string $slug = ''): void
    {
        $this->slug = $slug;
        if ($slug) {
            $this->loadReferee($slug);
        } else {
            $this->loadList();
        }
    }

    public function loadList(): void
    {
        $this->referees = DB::table('fixtures')
            ->whereNotNull('referee')
            ->where('referee', '!=', '')
            ->whereIn('status_short', ['FT','AET','PEN'])
            ->select(
                'referee',
                DB::raw('COUNT(*) as matches'),
                DB::raw('SUM((SELECT COUNT(*) FROM fixture_events WHERE fixture_events.fixture_id = fixtures.id AND type="Card" AND detail="Yellow Card")) as yellow_cards'),
                DB::raw('SUM((SELECT COUNT(*) FROM fixture_events WHERE fixture_events.fixture_id = fixtures.id AND type="Card" AND detail="Red Card")) as red_cards')
            )
            ->groupBy('referee')
            ->orderByDesc('matches')
            ->limit(30)
            ->get()
            ->map(function($r) {
                $slug = \Illuminate\Support\Str::slug($r->referee);
                return [
                    'name'         => $r->referee,
                    'slug'         => $slug,
                    'matches'      => $r->matches,
                    'yellow_cards' => $r->yellow_cards ?? 0,
                    'red_cards'    => $r->red_cards ?? 0,
                    'yellows_per_match' => $r->matches > 0 ? round(($r->yellow_cards ?? 0) / $r->matches, 1) : 0,
                    'reds_per_match'    => $r->matches > 0 ? round(($r->red_cards ?? 0) / $r->matches, 2) : 0,
                ];
            })->toArray();
    }

    public function loadReferee(string $slug): void
    {
        $fixtures = Fixture::with(['homeTeam','awayTeam','league','events'])
            ->whereNotNull('referee')
            ->whereIn('status_short', ['FT','AET','PEN'])
            ->get()
            ->filter(fn($f) => \Illuminate\Support\Str::slug($f->referee) === $slug);

        if ($fixtures->isEmpty()) { return; }

        $refName = $fixtures->first()->referee;
        $totalMatches = $fixtures->count();
        $totalYellow = 0; $totalRed = 0;

        $this->recentMatches = $fixtures->sortByDesc('kick_off')->take(10)->map(function($f) use (&$totalYellow, &$totalRed) {
            $yellows = $f->events->where('type','Card')->where('detail','Yellow Card')->count();
            $reds = $f->events->where('type','Card')->where('detail','Red Card')->count();
            $totalYellow += $yellows;
            $totalRed += $reds;
            $sh = $f->score?->home_fulltime ?? $f->score?->goals_home;
            $sa = $f->score?->away_fulltime ?? $f->score?->goals_away;
            return [
                'id'             => $f->id,
                'kick_off'       => $f->kick_off,
                'league_name'    => $f->league?->name,
                'home_team_name' => $f->homeTeam?->name ?? 'N/A',
                'away_team_name' => $f->awayTeam?->name ?? 'N/A',
                'score_home'     => $sh,
                'score_away'     => $sa,
                'yellow_cards'   => $yellows,
                'red_cards'      => $reds,
            ];
        })->values()->toArray();

        $this->referee = [
            'name'              => $refName,
            'matches'           => $totalMatches,
            'yellow_cards'      => $totalYellow,
            'red_cards'         => $totalRed,
            'yellows_per_match' => $totalMatches > 0 ? round($totalYellow / $totalMatches, 1) : 0,
            'reds_per_match'    => $totalMatches > 0 ? round($totalRed / $totalMatches, 2) : 0,
        ];
    }

    public function render() { return view('livewire.referee-stats'); }
}
