<?php
namespace App\Livewire;

use App\Models\Fixture;
use Livewire\Component;
use Livewire\Attributes\On;

class MatchDetail extends Component
{
    public Fixture $fixture;

    public function mount(int $id): void
    {
        $this->fixture = Fixture::with([
            'homeTeam', 'awayTeam', 'score', 'league', 'events'
        ])->findOrFail($id);
    }

    #[On('echo:fixture.{fixture.id},score.updated')]
    public function refresh(): void
    {
        $this->fixture = $this->fixture->fresh(['homeTeam','awayTeam','score','league','events']);
    }

    public function render()
    {
        return view('livewire.match-detail')->layout('layouts.app', [
            'title' => $this->fixture->homeTeam->name . ' vs ' . $this->fixture->awayTeam->name
        ]);
    }
}
