<?php
namespace App\Livewire;

use App\Models\Fixture;
use Livewire\Component;
use Livewire\Attributes\On;

class LiveScores extends Component
{
    public array $fixtures = [];
    public string $tab = 'live';
    public string $sport = 'football';
    public bool $sportAvailable = true;

    protected array $priorityLeagues = [2, 3, 848, 39, 140, 135, 78, 61, 197, 206, 168, 210, 286, 315];

    public function mount(string $initialTab = 'live', string $sport = 'football'): void
    {
        $this->tab   = $initialTab;
        $this->sport = $sport;
        // Basketball and tennis not yet synced from API
        $this->sportAvailable = ($sport === 'football');
        if ($this->sportAvailable) {
            $this->loadFixtures();
        }
    }

    public function loadFixtures(): void
    {
        $query = Fixture::with(['homeTeam', 'awayTeam', 'score', 'league'])
            ->join('leagues', 'fixtures.league_id', '=', 'leagues.id')
            ->select('fixtures.*')
            ->orderByRaw('FIELD(leagues.api_league_id, ' . implode(',', $this->priorityLeagues) . ') DESC')
            ->orderBy('fixtures.kick_off');

        // All leagues in DB are football for now
        if ($this->tab === 'live') {
            $query->whereIn('fixtures.status_short', ['1H','2H','HT','ET','BT','P','SUSP','INT','LIVE']);
        } elseif ($this->tab === 'yesterday') {
            $query->whereDate('fixtures.kick_off', today()->subDay());
        } elseif ($this->tab === 'tomorrow') {
            $query->whereDate('fixtures.kick_off', today()->addDay());
        } else {
            $query->whereDate('fixtures.kick_off', today());
        }

        $liveStatuses = ['1H','2H','ET','BT','P','LIVE','HT'];
        $ftStatuses   = ['FT','AET','PEN'];

        $grouped = [];
        foreach ($query->get() as $fixture) {
            $isLiveOrHT = in_array($fixture->status_short, $liveStatuses);
            $isFT       = in_array($fixture->status_short, $ftStatuses);

            if ($isLiveOrHT) {
                $scoreHome = $fixture->score?->goals_home ?? $fixture->score?->home_fulltime;
                $scoreAway = $fixture->score?->goals_away ?? $fixture->score?->away_fulltime;
            } elseif ($isFT) {
                $scoreHome = $fixture->score?->home_fulltime ?? $fixture->score?->goals_home;
                $scoreAway = $fixture->score?->away_fulltime ?? $fixture->score?->goals_away;
            } else {
                $scoreHome = null;
                $scoreAway = null;
            }

            $leagueName = $fixture->league?->name ?? 'Ostale lige';
            $grouped[$leagueName][] = [
                'id'             => $fixture->id,
                'status_short'   => $fixture->status_short,
                'elapsed_minute' => $fixture->elapsed_minute,
                'kick_off'       => $fixture->kick_off,
                'home_team_name' => $fixture->homeTeam?->name ?? 'N/A',
                'away_team_name' => $fixture->awayTeam?->name ?? 'N/A',
                'home_team_id'   => $fixture->home_team_id,
                'away_team_id'   => $fixture->away_team_id,
                'score_home'     => $scoreHome,
                'score_away'     => $scoreAway,
            ];
        }
        $this->fixtures = $grouped;
    }

    public function setTab(string $tab): void { $this->tab = $tab; $this->loadFixtures(); }

    #[On('echo:live-scores,score.updated')]
    public function handleScoreUpdate(array $data): void { $this->loadFixtures(); }

    public function render() { return view('livewire.live-scores'); }
}
