<?php
namespace App\Livewire;

use App\Models\Team;
use App\Models\League;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

#[Layout('layouts.app')]
class Search extends Component
{
    #[Url]
    public string $q = '';
    public array $teams = [];
    public array $leagues = [];

    public function mount(): void
    {
        if (strlen($this->q) >= 2) $this->doSearch();
    }

    public function updated(string $field): void
    {
        if ($field === 'q' && strlen($this->q) >= 2) $this->doSearch();
        if ($field === 'q' && strlen($this->q) < 2) {
            $this->teams = [];
            $this->leagues = [];
        }
    }

    public function doSearch(): void
    {
        $q = $this->q;

        $this->teams = Team::where('name', 'like', "%{$q}%")
            ->orderBy('name')
            ->take(10)
            ->get(['id','name','slug','logo_url'])
            ->map(fn($t) => [
                'name' => $t->name,
                'slug' => $t->slug,
                'logo' => $t->logo_url,
            ])->toArray();

        $this->leagues = League::where('name', 'like', "%{$q}%")
            ->orderBy('name')
            ->take(8)
            ->get(['id','name','logo_url'])
            ->map(fn($l) => [
                'name'    => $l->name,
                'country' => $l->country ?? '',
                'logo'    => $l->logo_url,
            ])->toArray();
    }

    public function render() { return view('livewire.search'); }
}
