<?php
namespace App\Livewire;

use App\Models\Fixture;
use App\Models\League;
use App\Models\Standing;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class LeaguePage extends Component
{
    public League $league;
    public array $fixtures = [];
    public array $standings = [];
    public string $tab = 'today';
    public string $view = 'fixtures'; // fixtures | standings

    protected array $slugMap = [
        'hnl'               => 210,
        'superliga-srbija'  => 286,
        'premijer-liga-bih' => 315,
        'champions-liga'    => 2,
        'europa-liga'       => 3,
        'konferencijska-liga'=> 848,
        'premier-league'    => 39,
        'la-liga'           => 140,
        'serie-a'           => 135,
        'bundesliga'        => 78,
        'ligue-1'           => 61,
        'prva-liga-srbija'  => 287,
        'first-nl-hrvatska' => 211,
        'hnl-2'             => 946,
        'prva-liga-fbih'    => 316,
        'prva-liga-rs'      => 317,
        'kup-hrvatska'      => 212,
        'kup-bosna'         => 314,
        'kup-srbija'        => 732,
    ];

    public function mount(string $slug): void
    {
        $leagueId = $this->slugMap[$slug] ?? null;
        abort_if(!$leagueId, 404);
        $this->league = League::where('api_league_id', $leagueId)->firstOrFail();
        $this->loadFixtures();
        $this->loadStandings();
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
            $query->whereBetween("kick_off", [now()->subDays(7), now()])->whereIn("status_short", ["FT","AET","PEN","PST","CANC"]);
        }

        $ftStatuses   = ['FT','AET','PEN'];
        $liveStatuses = ['1H','2H','HT','ET','BT','P','LIVE'];

        $this->fixtures = $query->get()->map(function($f) use ($ftStatuses, $liveStatuses) {
            $isFT   = in_array($f->status_short, $ftStatuses);
            $isLive = in_array($f->status_short, $liveStatuses);
            $isHT   = $f->status_short === 'HT';

            if ($isFT) { $sh = $f->score?->home_fulltime ?? $f->score?->goals_home; $sa = $f->score?->away_fulltime ?? $f->score?->goals_away; }
            elseif ($isLive || $isHT) { $sh = $f->score?->goals_home ?? $f->score?->home_fulltime; $sa = $f->score?->goals_away ?? $f->score?->away_fulltime; }
            else { $sh = null; $sa = null; }

            return [
                'id'             => $f->id,
                'status_short'   => $f->status_short,
                'elapsed_minute' => $f->elapsed_minute,
                'kick_off'       => $f->kick_off,
                'home_team_name' => $f->homeTeam?->name ?? 'N/A',
                'home_team_logo' => $f->homeTeam?->logo_url,
                'away_team_name' => $f->awayTeam?->name ?? 'N/A',
                'away_team_logo' => $f->awayTeam?->logo_url,
                'home_team_id'   => $f->home_team_id,
                'away_team_id'   => $f->away_team_id,
                'home_team_slug' => $f->homeTeam?->slug,
                'away_team_slug' => $f->awayTeam?->slug,
                'score_home'     => $sh,
                'score_away'     => $sa,
                'is_live'        => $isLive || $isHT,
                'is_ft'          => $isFT,
            ];
        })->toArray();
    }

    public function loadStandings(): void
    {
        $this->standings = Standing::with('team')
            ->where('league_id', $this->league->id)
            ->orderBy('rank')
            ->get()
            ->map(fn($s) => [
                'rank'          => $s->rank,
                'team_name'     => $s->team?->name ?? 'N/A',
                'team_logo'     => $s->team?->logo_url,
                'team_slug'     => $s->team?->slug,
                'played'        => $s->played,
                'win'           => $s->win,
                'draw'          => $s->draw,
                'lose'          => $s->lose,
                'goals_for'     => $s->goals_for,
                'goals_against' => $s->goals_against,
                'goal_diff'     => $s->goal_diff,
                'points'        => $s->points,
                'form'          => $s->form,
                'description'   => $s->description,
            ])->toArray();
    }

    public function setTab(string $tab): void { $this->tab = $tab; $this->loadFixtures(); }
    public function setView(string $view): void { $this->view = $view; }

    public function render()
    {
        return view('livewire.league-page');
    }
}
