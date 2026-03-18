<?php
namespace App\Livewire;

use App\Models\Player;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class BalkanPlayers extends Component
{
    public string $nationality = 'all';
    public string $position = 'all';
    public string $league = 'all';

    public function render()
    {
        $query = Player::where('is_active', true)->orderByDesc('is_featured')->orderBy('name');

        if ($this->nationality !== 'all') $query->where('nationality', $this->nationality);
        if ($this->position !== 'all') $query->where('position', $this->position);
        if ($this->league !== 'all') $query->where('current_league', 'like', '%' . $this->league . '%');

        $players = $query->get();
        $leagues = Player::where('is_active', true)->distinct()->pluck('current_league')->sort()->values();

        return view('livewire.balkan-players', compact('players', 'leagues'));
    }
}
