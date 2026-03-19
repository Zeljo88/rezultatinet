<?php
namespace App\Livewire;

use App\Models\League;
use App\Models\PlayerStat;
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
        2   => 'Champions Liga',
        3   => 'Europa Liga',
        39  => 'Premier League',
        140 => 'La Liga',
        135 => 'Serie A',
        78  => 'Bundesliga',
        61  => 'Ligue 1',
        210 => 'HNL',
        286 => 'Superliga Srbija',
        315 => 'Premijer Liga BiH',
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
        if (!$this->league) {
            $this->scorers = [];
            return;
        }

        $this->scorers = PlayerStat::with('player')
            ->where('league_id', $this->league->id)
            ->where('season', '2024')
            ->orderByDesc('goals')
            ->limit(20)
            ->get()
            ->map(fn($stat) => [
                'player_name' => $stat->player?->name ?? 'Unknown',
                'player_photo'=> $stat->player?->photo_url,
                'team_name'   => $stat->player?->current_club ?? 'N/A',
                'team_logo'   => $stat->player?->current_club_logo,
                'nationality' => $stat->player?->nationality,
                'goals'       => $stat->goals,
                'assists'     => $stat->assists,
                'appearances' => $stat->appearances,
            ])->toArray();
    }

    public function render()
    {
        return view('livewire.top-scorers', [
            'availableLeagues' => $this->availableLeagues,
        ]);
    }
}
