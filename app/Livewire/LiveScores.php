<?php
namespace App\Livewire;

use App\Models\Fixture;
use App\Models\League;
use Livewire\Component;
use Livewire\Attributes\On;

class LiveScores extends Component
{
    public array $fixtures = [];
    public string $tab = 'live';

    // Priority league IDs - shown first
    protected array $priorityLeagues = [2, 3, 848, 39, 140, 135, 78, 61, 197, 206, 168];

    public function mount(): void { $this->loadFixtures(); }

    public function loadFixtures(): void
    {
        $query = Fixture::with(['homeTeam', 'awayTeam', 'score', 'league'])
            ->join('leagues', 'fixtures.league_id', '=', 'leagues.id')
            ->select('fixtures.*')
            ->orderByRaw('FIELD(leagues.api_league_id, ' . implode(',', $this->priorityLeagues) . ') DESC')
            ->orderBy('fixtures.kick_off');

        if ($this->tab === 'live') {
            $query->whereIn('fixtures.status_short', ['1H','2H','HT','ET','BT','P','SUSP','INT','LIVE']);
        } elseif ($this->tab === 'today') {
            $query->whereDate('fixtures.kick_off', today());
        } else {
            $query->whereDate('fixtures.kick_off', today()->addDay());
        }

        $raw = $query->get();
        $grouped = [];
        foreach ($raw as $fixture) {
            $leagueName = $fixture->league?->name ?? 'Ostale lige';
            $grouped[$leagueName][] = $fixture->toArray();
        }
        $this->fixtures = $grouped;
    }

    public function setTab(string $tab): void { $this->tab = $tab; $this->loadFixtures(); }

    #[On('echo:live-scores,score.updated')]
    public function handleScoreUpdate(array $data): void { $this->loadFixtures(); }

    public function render() { return view('livewire.live-scores'); }
}
