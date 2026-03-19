<?php
namespace App\Livewire;

use App\Models\Fixture;
use App\Models\BasketballGame;
use App\Models\TennisMatch;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\Attributes\On;

class LiveScores extends Component
{
    public array $fixtures = [];
    public string $tab = 'today';
    public string $sport = 'football';
    public bool $sportAvailable = true;
    public string $selectedDate;
    public string $filter = 'sve';

    protected array $priorityLeagues = [210, 286, 315, 211, 287, 316, 317, 946, 2, 3, 848, 39, 140, 135, 78, 61];

    public function mount(string $initialTab = 'today', string $sport = 'football'): void
    {
        $this->tab          = $initialTab;
        $this->sport        = $sport;
        $this->selectedDate = today()->toDateString();
        $this->sportAvailable = true;
        $this->loadFixtures();
    }

    // ─── Date helpers ────────────────────────────────────────────────────────

    public function getPrevDate(): string
    {
        return Carbon::parse($this->selectedDate)->subDay()->toDateString();
    }

    public function getNextDate(): string
    {
        return Carbon::parse($this->selectedDate)->addDay()->toDateString();
    }

    public function setDate(string $date): void
    {
        $d = Carbon::parse($date);
        $min = today()->subDays(3);
        $max = today()->addDays(7);
        if ($d->between($min, $max)) {
            $this->selectedDate = $date;
            $this->loadFixtures();
        }
    }

    // ─── Filter ──────────────────────────────────────────────────────────────

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->loadFixtures();
    }

    // ─── Base query (all fixtures for selected date, no status filter) ───────

    protected function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Fixture::with(['homeTeam', 'awayTeam', 'score', 'league'])
            ->join('leagues', 'fixtures.league_id', '=', 'leagues.id')
            ->select('fixtures.*')
            ->whereDate('fixtures.kick_off', $this->selectedDate)
            ->orderByRaw('FIELD(leagues.api_league_id, ' . implode(',', $this->priorityLeagues) . ') DESC')
            ->orderBy('fixtures.kick_off');
    }

    // ─── Counts for pills ────────────────────────────────────────────────────

    public function getCounts(): array
    {
        if ($this->sport === 'basketball') {
            return $this->getBasketballCounts();
        }
        if ($this->sport === 'tennis') {
            return $this->getTennisCounts();
        }

        $all = $this->baseQuery()->get();
        return [
            'all'      => $all->count(),
            'live'     => $all->whereIn('status_short', ['1H','2H','HT','ET','BT','P'])->count(),
            'upcoming' => $all->where('status_short', 'NS')->count(),
            'finished' => $all->whereIn('status_short', ['FT','AET','PEN'])->count(),
        ];
    }

    protected function getBasketballCounts(): array
    {
        $liveStatuses = ['Q1','Q2','Q3','Q4','HT','OT','LIVE','BP','BT'];
        $all = BasketballGame::whereDate('game_date', $this->selectedDate)->get();
        return [
            'all'      => $all->count(),
            'live'     => $all->whereIn('status_short', $liveStatuses)->count(),
            'upcoming' => $all->where('status_short', 'NS')->count(),
            'finished' => $all->whereIn('status_short', ['FT','AOT'])->count(),
        ];
    }

    protected function getTennisCounts(): array
    {
        $liveStatuses = ['In Play','1st Set','2nd Set','3rd Set','4th Set','5th Set','Break Time'];
        $all = TennisMatch::whereDate('match_date', $this->selectedDate)->get();
        return [
            'all'      => $all->count(),
            'live'     => $all->filter(fn($m) => in_array($m->status, $liveStatuses))->count(),
            'upcoming' => $all->whereIn('status', ['Not Started','NS'])->count(),
            'finished' => $all->whereIn('status', ['Finished','Retired','Walkover','Default','FT'])->count(),
        ];
    }

    // ─── Main fixture loader ──────────────────────────────────────────────────

    public function loadFixtures(): void
    {
        if ($this->sport === 'basketball') {
            $this->loadBasketballGames();
            return;
        }
        if ($this->sport === 'tennis') {
            $this->loadTennisMatches();
            return;
        }

        $query = $this->baseQuery();

        match ($this->filter) {
            'uzivo'    => $query->whereIn('fixtures.status_short', ['1H','2H','HT','ET','BT','P']),
            'zakazano' => $query->where('fixtures.status_short', 'NS'),
            'zavrseno' => $query->whereIn('fixtures.status_short', ['FT','AET','PEN']),
            default    => null,
        };

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

            $country = $fixture->league?->country ?? '';
            $leagueName = ($country ? $country . ' — ' : '') . ($fixture->league?->name ?? 'Ostale lige');
            $grouped[$leagueName][] = [
                'id'             => $fixture->id,
                'status_short'   => $fixture->status_short,
                'elapsed_minute' => $fixture->elapsed_minute,
                'kick_off'       => $fixture->kick_off,
                'home_team_name' => $fixture->homeTeam?->name ?? 'N/A',
                'away_team_name' => $fixture->awayTeam?->name ?? 'N/A',
                'home_team_id'   => $fixture->home_team_id,
                'away_team_id'   => $fixture->away_team_id,
                'home_team_slug' => $fixture->homeTeam?->slug,
                'away_team_slug' => $fixture->awayTeam?->slug,
                'score_home'     => $scoreHome,
                'score_away'     => $scoreAway,
            ];
        }
        $this->fixtures = $grouped;
    }

    protected function loadBasketballGames(): void
    {
        $liveStatuses = ['Q1','Q2','Q3','Q4','HT','OT','LIVE','BP','BT'];
        $ftStatuses   = ['FT','AOT'];

        $query = BasketballGame::whereDate('game_date', $this->selectedDate)
            ->orderBy('game_date');

        match ($this->filter) {
            'uzivo'    => $query->whereIn('status_short', $liveStatuses),
            'zakazano' => $query->where('status_short', 'NS'),
            'zavrseno' => $query->whereIn('status_short', $ftStatuses),
            default    => null,
        };

        $grouped = [];
        foreach ($query->get() as $game) {
            $isLive   = in_array($game->status_short, $liveStatuses);
            $isFT     = in_array($game->status_short, $ftStatuses);
            $hasScore = $isLive || $isFT;

            $league = ($game->country_name ? $game->country_name . ' — ' : '') . ($game->league_name ?? 'Košarka');
            $grouped[$league][] = [
                'type'           => 'basketball',
                'id'             => $game->id,
                'status_short'   => $game->status_short,
                'elapsed_minute' => $game->elapsed,
                'kick_off'       => $game->game_date,
                'home_team_name' => $game->home_team ?? 'N/A',
                'away_team_name' => $game->away_team ?? 'N/A',
                'score_home'     => $hasScore ? $game->home_score : null,
                'score_away'     => $hasScore ? $game->away_score : null,
                'status_label'   => $game->statusLabel(),
            ];
        }
        $this->fixtures = $grouped;
    }

    protected function loadTennisMatches(): void
    {
        $liveStatuses     = ['In Play','1st Set','2nd Set','3rd Set','4th Set','5th Set','Break Time'];
        $finishedStatuses = ['Finished','Retired','Walkover','Default','FT'];

        $query = TennisMatch::whereDate('match_date', $this->selectedDate)
            ->orderBy('match_date');

        match ($this->filter) {
            'uzivo'    => $query->whereIn('status', $liveStatuses),
            'zakazano' => $query->whereIn('status', ['Not Started','NS']),
            'zavrseno' => $query->whereIn('status', $finishedStatuses),
            default    => null,
        };

        $grouped = [];
        foreach ($query->get() as $match) {
            $isLive   = in_array($match->status, $liveStatuses);
            $isFT     = in_array($match->status, $finishedStatuses);
            $hasScore = ($isLive || $isFT) && !empty($match->score);

            $tournament = ($match->country_name ? $match->country_name . ' — ' : '') . ($match->tournament_name ?? 'Tenis');
            $grouped[$tournament][] = [
                'type'           => 'tennis',
                'id'             => $match->id,
                'status_short'   => $isLive ? 'LIVE' : ($isFT ? 'FT' : 'NS'),
                'elapsed_minute' => null,
                'kick_off'       => $match->match_date,
                'home_team_name' => $match->player_home ?? 'N/A',
                'away_team_name' => $match->player_away ?? 'N/A',
                'score_home'     => null,
                'score_away'     => null,
                'score_sets'     => $hasScore ? $match->score : null,
                'status_label'   => $match->status,
                'is_live'        => $isLive,
            ];
        }
        $this->fixtures = $grouped;
    }

    // ─── Legacy tab support ───────────────────────────────────────────────────

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->loadFixtures();
    }

    // ─── Real-time score updates ──────────────────────────────────────────────

    #[On('echo:live-scores,score.updated')]
    public function handleScoreUpdate(array $data): void
    {
        $this->loadFixtures();
    }

    // ─── Render ──────────────────────────────────────────────────────────────

    public function render()
    {
        return view('livewire.live-scores', [
            'prevDate' => $this->getPrevDate(),
            'nextDate' => $this->getNextDate(),
            'counts'   => $this->getCounts(),
        ]);
    }
}
