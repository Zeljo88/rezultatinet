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
    public array $counts = [];
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

    // ─── Filter — client-side only, no server round-trip ─────────────────────

    public function setFilter(string $filter): void
    {
        // Only update the property so the blade template reflects active pill styling.
        // Actual filtering is done client-side via Alpine.js — no loadFixtures() here.
        $this->filter = $filter;
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

    // ─── Counts for pills (DB-level COUNT — no PHP collection loading) ────────

    public function getCounts(): array
    {
        if ($this->sport === 'basketball') {
            return $this->getBasketballCounts();
        }
        if ($this->sport === 'tennis') {
            return $this->getTennisCounts();
        }

        $base = $this->baseQuery();
        return [
            'all'      => (clone $base)->count(),
            'live'     => (clone $base)->whereIn('fixtures.status_short', ['1H','2H','HT','ET','BT','P'])->count(),
            'upcoming' => (clone $base)->where('fixtures.status_short', 'NS')->count(),
            'finished' => (clone $base)->whereIn('fixtures.status_short', ['FT','AET','PEN'])->count(),
        ];
    }

    protected function getBasketballCounts(): array
    {
        $liveStatuses = ['Q1','Q2','Q3','Q4','HT','OT','LIVE','BP','BT'];
        $base = BasketballGame::whereDate('game_date', $this->selectedDate);
        return [
            'all'      => (clone $base)->count(),
            'live'     => (clone $base)->whereIn('status_short', $liveStatuses)->count(),
            'upcoming' => (clone $base)->where('status_short', 'NS')->count(),
            'finished' => (clone $base)->whereIn('status_short', ['FT','AOT'])->count(),
        ];
    }

    protected function getTennisCounts(): array
    {
        $liveStatuses = ['In Play','1st Set','2nd Set','3rd Set','4th Set','5th Set','Break Time'];
        $base = TennisMatch::whereDate('match_date', $this->selectedDate);
        return [
            'all'      => (clone $base)->count(),
            'live'     => (clone $base)->whereIn('status', $liveStatuses)->count(),
            'upcoming' => (clone $base)->whereIn('status', ['Not Started','NS'])->count(),
            'finished' => (clone $base)->whereIn('status', ['Finished','Retired','Walkover','Default','FT'])->count(),
        ];
    }

    // ─── Main fixture loader — always loads ALL fixtures (no status filter) ──

    public function loadFixtures(): void
    {
        if ($this->sport === 'basketball') {
            $this->loadBasketballGames();
            $this->counts = $this->getCounts();
            return;
        }
        if ($this->sport === 'tennis') {
            $this->loadTennisMatches();
            $this->counts = $this->getCounts();
            return;
        }

        // Always load ALL fixtures — Alpine.js handles client-side filtering
        $query = $this->baseQuery();

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
                'league_api_id'  => $fixture->league?->api_league_id,
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
                'home_team_logo' => $fixture->homeTeam?->logo_url,
                'away_team_logo' => $fixture->awayTeam?->logo_url,
                'league_logo'    => $fixture->league?->logo_url,
            ];
        }
        // Sort grouped fixtures — priority leagues first
        $priorityLeagueIds = [2, 3, 848, 210, 286, 315, 39, 140, 135, 78, 61, 211, 316, 317, 287];
        $priorityGrouped = [];
        $otherGrouped = [];
        foreach ($grouped as $leagueName => $leagueFixtures) {
            $leagueApiId = $leagueFixtures[0]['league_api_id'] ?? null;
            if ($leagueApiId && in_array($leagueApiId, $priorityLeagueIds)) {
                $priorityGrouped[$leagueName] = $leagueFixtures;
            } else {
                $otherGrouped[$leagueName] = $leagueFixtures;
            }
        }
        $grouped = array_merge($priorityGrouped, $otherGrouped);

        $this->fixtures = $grouped;
        $this->counts = $this->getCounts();
    }

    protected function loadBasketballGames(): void
    {
        $liveStatuses = ['Q1','Q2','Q3','Q4','HT','OT','LIVE','BP','BT'];
        $ftStatuses   = ['FT','AOT'];

        // Always load ALL games — Alpine.js handles client-side filtering
        $query = BasketballGame::whereDate('game_date', $this->selectedDate)
            ->orderBy('game_date');

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

        // Always load ALL matches — Alpine.js handles client-side filtering
        $query = TennisMatch::whereDate('match_date', $this->selectedDate)
            ->orderBy('match_date');

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
            'counts'   => $this->counts,
        ]);
    }
}
