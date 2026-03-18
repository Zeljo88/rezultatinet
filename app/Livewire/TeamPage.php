<?php
namespace App\Livewire;

use App\Models\Fixture;
use App\Models\Team;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class TeamPage extends Component
{
    public Team $team;
    public array $fixtures = [];
    public array $upcoming = [];
    public string $tab = 'results';

    public function mount(int $id): void
    {
        $this->team = Team::findOrFail($id);
        $this->loadFixtures();
    }

    public function loadFixtures(): void
    {
        $teamId = $this->team->id;

        // Last 10 finished matches
        $this->fixtures = Fixture::with(['homeTeam', 'awayTeam', 'score', 'league'])
            ->where(fn($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->whereIn('status_short', ['FT','AET','PEN'])
            ->orderBy('kick_off', 'desc')
            ->take(10)
            ->get()
            ->map(fn($f) => $this->mapFixture($f))
            ->toArray();

        // Next 5 upcoming matches
        $this->upcoming = Fixture::with(['homeTeam', 'awayTeam', 'league'])
            ->where(fn($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->where('status_short', 'NS')
            ->where('kick_off', '>=', now())
            ->orderBy('kick_off')
            ->take(5)
            ->get()
            ->map(fn($f) => $this->mapFixture($f))
            ->toArray();
    }

    protected function mapFixture(Fixture $f): array
    {
        $isFT = in_array($f->status_short, ['FT','AET','PEN']);
        $isHome = $f->home_team_id === $this->team->id;

        $sh = $isFT ? ($f->score?->home_fulltime ?? $f->score?->goals_home) : null;
        $sa = $isFT ? ($f->score?->away_fulltime ?? $f->score?->goals_away) : null;

        $result = null;
        if ($isFT && $sh !== null && $sa !== null) {
            if ($isHome) $result = $sh > $sa ? 'W' : ($sh === $sa ? 'D' : 'L');
            else $result = $sa > $sh ? 'W' : ($sa === $sh ? 'D' : 'L');
        }

        return [
            'id'             => $f->id,
            'kick_off'       => $f->kick_off,
            'status_short'   => $f->status_short,
            'league_name'    => $f->league?->name,
            'league_logo'    => $f->league?->logo_url,
            'home_team_name' => $f->homeTeam?->name ?? 'N/A',
            'home_team_logo' => $f->homeTeam?->logo_url,
            'away_team_name' => $f->awayTeam?->name ?? 'N/A',
            'away_team_logo' => $f->awayTeam?->logo_url,
            'score_home'     => $sh,
            'score_away'     => $sa,
            'is_home'        => $isHome,
            'result'         => $result,
            'is_ft'          => $isFT,
        ];
    }

    public function setTab(string $tab): void { $this->tab = $tab; }

    public function render() { return view('livewire.team-page'); }
}
