<?php
namespace App\Livewire;

use App\Models\Fixture;
use App\Models\League;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class LeaguePage extends Component
{
    public League $league;
    public array $fixtures = [];
    public string $tab = 'today';

    // Slug to api_league_id map
    protected array $slugMap = [
        'hnl'              => 210,
        'superliga-srbija' => 286,
        'premijer-liga-bih'=> 315,
        'champions-liga'   => 2,
        'europa-liga'      => 3,
        'konferencijska-liga' => 848,
        'premier-league'   => 39,
        'la-liga'          => 140,
        'serie-a'          => 135,
        'bundesliga'       => 78,
        'ligue-1'          => 61,
        'prva-liga-srbija' => 287,
        'first-nl-hrvatska'=> 211,
    ];

    public function mount(string $slug): void
    {
        $leagueId = $this->slugMap[$slug] ?? null;
        abort_if(!$leagueId, 404);
        $this->league = League::where('api_league_id', $leagueId)->firstOrFail();
        $this->loadFixtures();
    }

    public function loadFixtures(): void
    {
        $query = Fixture::with(['homeTeam', 'awayTeam', 'score'])
            ->where('league_id', $this->league->id)
            ->orderBy('kick_off', 'desc');

        if ($this->tab === 'today') {
            $query->whereDate('kick_off', today());
        } elseif ($this->tab === 'yesterday') {
            $query->whereDate('kick_off', today()->subDay());
        } elseif ($this->tab === 'tomorrow') {
            $query->whereDate('kick_off', today()->addDay());
        } else {
            // recent - last 7 days + next 3 days
            $query->whereBetween('kick_off', [today()->subDays(7), today()->addDays(3)]);
        }

        $ftStatuses   = ['FT','AET','PEN'];
        $liveStatuses = ['1H','2H','HT','ET','BT','P','LIVE'];

        $this->fixtures = $query->get()->map(function($f) use ($ftStatuses, $liveStatuses) {
            $isFT   = in_array($f->status_short, $ftStatuses);
            $isLive = in_array($f->status_short, $liveStatuses);
            $isHT   = $f->status_short === 'HT';

            if ($isFT) {
                $sh = $f->score?->home_fulltime ?? $f->score?->goals_home;
                $sa = $f->score?->away_fulltime ?? $f->score?->goals_away;
            } elseif ($isLive || $isHT) {
                $sh = $f->score?->goals_home ?? $f->score?->home_fulltime;
                $sa = $f->score?->goals_away ?? $f->score?->away_fulltime;
            } else {
                $sh = null; $sa = null;
            }

            return [
                'id'             => $f->id,
                'status_short'   => $f->status_short,
                'elapsed_minute' => $f->elapsed_minute,
                'kick_off'       => $f->kick_off,
                'home_team_name' => $f->homeTeam?->name ?? 'N/A',
                'away_team_name' => $f->awayTeam?->name ?? 'N/A',
                'score_home'     => $sh,
                'score_away'     => $sa,
                'is_live'        => $isLive || $isHT,
                'is_ft'          => $isFT,
            ];
        })->toArray();
    }

    public function setTab(string $tab): void { $this->tab = $tab; $this->loadFixtures(); }

    public function render()
    {
        return view('livewire.league-page', ['title' => $this->league->name . ' — rezultati.net']);
    }
}
