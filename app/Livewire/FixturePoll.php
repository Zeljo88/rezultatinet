<?php

namespace App\Livewire;

use App\Models\Fixture;
use App\Models\FixturePoll as FixturePollModel;
use Livewire\Component;

class FixturePoll extends Component
{
    public Fixture $fixture;
    public ?string $userVote = null;
    public array $results = ['home' => 0, 'draw' => 0, 'away' => 0];
    public int $totalVotes = 0;
    public bool $hasVoted = false;

    public function mount(Fixture $fixture)
    {
        $this->fixture = $fixture;
        $this->loadResults();
        $this->checkIfVoted();
    }

    public function vote(string $choice)
    {
        if ($this->hasVoted) return;
        if (!in_array($choice, ['home', 'draw', 'away'])) return;

        // Ne dozvoli glasanje za završene utakmice
        if (in_array($this->fixture->status_short, ['FT', 'AET', 'PEN'])) return;

        $ip = request()->ip();

        try {
            FixturePollModel::create([
                'fixture_id'    => $this->fixture->id,
                'vote'          => $choice,
                'voter_ip'      => $ip,
                'voter_session' => session()->getId(),
            ]);
            $this->userVote = $choice;
            $this->hasVoted = true;
            $this->loadResults();
        } catch (\Exception $e) {
            // Duplicate — already voted
            $this->hasVoted = true;
        }
    }

    private function loadResults()
    {
        $votes = FixturePollModel::where('fixture_id', $this->fixture->id)
            ->selectRaw('vote, count(*) as cnt')
            ->groupBy('vote')
            ->pluck('cnt', 'vote')
            ->toArray();

        $this->results = [
            'home' => $votes['home'] ?? 0,
            'draw' => $votes['draw'] ?? 0,
            'away' => $votes['away'] ?? 0,
        ];
        $this->totalVotes = array_sum($this->results);
    }

    private function checkIfVoted()
    {
        $ip = request()->ip();
        $existing = FixturePollModel::where('fixture_id', $this->fixture->id)
            ->where('voter_ip', $ip)
            ->first();

        if ($existing) {
            $this->hasVoted = true;
            $this->userVote = $existing->vote;
        }
    }

    public function render()
    {
        return view('livewire.fixture-poll');
    }
}
