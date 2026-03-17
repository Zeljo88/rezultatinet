<?php
namespace App\Livewire;

use App\Models\Fixture;
use Livewire\Component;
use Livewire\Attributes\On;

class LiveScores extends Component
{
    public array $fixtures = [];
    public string $tab = 'live';

    public function mount(): void { $this->loadFixtures(); }

    public function loadFixtures(): void
    {
        $query = Fixture::with(['homeTeam', 'awayTeam', 'score', 'league'])->orderBy('kick_off');
        if ($this->tab === 'live') {
            $query->whereIn('status_short', ['1H','2H','HT','ET','BT','P','SUSP','INT','LIVE']);
        } elseif ($this->tab === 'today') {
            $query->whereDate('kick_off', today());
        } else {
            $query->whereDate('kick_off', today()->addDay());
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
