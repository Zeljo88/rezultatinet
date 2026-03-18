<?php
namespace App\Livewire;

use App\Models\Player;
use App\Models\Fixture;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class PlayerProfile extends Component
{
    public Player $player;

    public function mount(string $slug): void
    {
        $this->player = Player::where('slug', $slug)->where('is_active', true)->firstOrFail();
    }

    public function render()
    {
        return view('livewire.player-profile');
    }
}
