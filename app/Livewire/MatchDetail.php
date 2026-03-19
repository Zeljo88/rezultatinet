<?php
namespace App\Livewire;

use App\Models\Fixture;
use App\Models\FixtureLineup;
use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

#[Layout('layouts.app')]
class MatchDetail extends Component
{
    public Fixture $fixture;
    public string $pageTitle = 'Detalji utakmice';
    public array $h2h = [];
    public string $activeTab = 'events'; // events | lineups | h2h
    public ?FixtureLineup $homeLineup = null;
    public ?FixtureLineup $awayLineup = null;

    public function mount(int $id): void
    {
        $this->fixture = Fixture::with([
            'homeTeam', 'awayTeam', 'score', 'league', 'events'
        ])->findOrFail($id);

        $this->pageTitle = $this->fixture->homeTeam->name . ' vs ' . $this->fixture->awayTeam->name;
        $this->loadH2H();
        $this->loadLineups();
    }

    public function loadLineups(): void
    {
        $this->homeLineup = FixtureLineup::where('fixture_id', $this->fixture->id)
            ->where('team_side', 'home')
            ->first();
        $this->awayLineup = FixtureLineup::where('fixture_id', $this->fixture->id)
            ->where('team_side', 'away')
            ->first();
    }

    public function loadH2H(): void
    {
        $homeId = $this->fixture->home_team_id;
        $awayId = $this->fixture->away_team_id;

        $this->h2h = Fixture::with(['homeTeam', 'awayTeam', 'score', 'league'])
            ->where(function($q) use ($homeId, $awayId) {
                $q->where(function($q2) use ($homeId, $awayId) {
                    $q2->where('home_team_id', $homeId)->where('away_team_id', $awayId);
                })->orWhere(function($q2) use ($homeId, $awayId) {
                    $q2->where('home_team_id', $awayId)->where('away_team_id', $homeId);
                });
            })
            ->whereIn('status_short', ['FT','AET','PEN'])
            ->where('id', '!=', $this->fixture->id)
            ->orderBy('kick_off', 'desc')
            ->take(6)
            ->get()
            ->map(function($f) {
                return [
                    'id'             => $f->id,
                    'kick_off'       => $f->kick_off,
                    'league_name'    => $f->league?->name,
                    'home_team_name' => $f->homeTeam?->name,
                    'home_team_logo' => $f->homeTeam?->logo_url,
                    'away_team_name' => $f->awayTeam?->name,
                    'away_team_logo' => $f->awayTeam?->logo_url,
                    'score_home'     => $f->score?->home_fulltime ?? $f->score?->goals_home,
                    'score_away'     => $f->score?->away_fulltime ?? $f->score?->goals_away,
                    'status'         => $f->status_short,
                ];
            })->toArray();
    }

    public function setTab(string $tab): void { $this->activeTab = $tab; }

    #[On('echo:fixture.{fixture.id},score.updated')]
    public function refresh(): void
    {
        $this->fixture = $this->fixture->fresh(['homeTeam','awayTeam','score','league','events']);
        $this->loadLineups();
        $this->dispatch('scoreUpdated',
            home: $this->fixture->score?->goals_home ?? 0,
            away: $this->fixture->score?->goals_away ?? 0
        );
    }

    public function render()
    {
        return view('livewire.match-detail');
    }
}
