<?php
namespace App\Livewire;

use App\Models\FixtureEvent;
use App\Models\League;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class TopScorers extends Component
{
    public array $scorers = [];
    public ?League $league = null;
    public int $leagueApiId = 39; // Default: Premier League
    public string $leagueName = 'Premier League';

    protected array $availableLeagues = [
        210 => 'HNL',
        286 => 'Superliga Srbija',
        315 => 'Premijer Liga BiH',
        39  => 'Premier League',
        140 => 'La Liga',
        135 => 'Serie A',
        78  => 'Bundesliga',
        61  => 'Ligue 1',
        2   => 'Champions Liga',
    ];

    public function mount(int $league = 39): void
    {
        $this->leagueApiId = $league;
        $this->leagueName = $this->availableLeagues[$league] ?? 'Strijelci';
        $this->league = League::where('api_league_id', $league)->first();
        $this->loadScorers();
    }

    public function setLeague(int $leagueApiId): void
    {
        $this->leagueApiId = $leagueApiId;
        $this->leagueName = $this->availableLeagues[$leagueApiId] ?? 'Strijelci';
        $this->league = League::where('api_league_id', $leagueApiId)->first();
        $this->loadScorers();
    }

    public function loadScorers(): void
    {
        if (!$this->league) { $this->scorers = []; return; }

        $this->scorers = FixtureEvent::where('type', 'Goal')
            ->where('detail', '!=', 'Own Goal')
            ->whereNotNull('player_name')
            ->where('player_name', '!=', '')
            ->whereHas('fixture', fn($q) => $q->where('league_id', $this->league->id))
            ->select('player_name', 'team_id', DB::raw('count(*) as goals'))
            ->groupBy('player_name', 'team_id')
            ->orderByDesc('goals')
            ->take(20)
            ->with('team')
            ->get()
            ->map(fn($s) => [
                'player_name' => $s->player_name,
                'team_name'   => $s->team?->name ?? 'N/A',
                'team_logo'   => $s->team?->logo_url,
                'goals'       => $s->goals,
            ])->toArray();
    }

    public function render()
    {
        return view('livewire.top-scorers', [
            'availableLeagues' => $this->availableLeagues,
        ]);
    }
}
