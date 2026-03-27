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

    // Priority 1 = Top 5 EU, 2 = Balkan, 3 = WC Qual + CL/EL, 99 = ostale (abecedno)
    private const PRIORITY_LEAGUES = [
        39  => 1, 140 => 1, 135 => 1, 78  => 1, 61  => 1,   // Top 5 EU
        210 => 2, 286 => 2, 315 => 2, 382 => 2, 394 => 2, 271 => 2, 113 => 2, // Balkan
        32  => 3, 34  => 3, 30  => 3, 31  => 3, 33  => 3, 2  => 3, 3  => 3, 848 => 3, 5  => 3, // WC Qual + CL/EL
    ];

    // Flat list for SQL FIELD() ordering (priority 1 first, then 2, then 3)
    protected array $priorityLeagues = [
        39, 140, 135, 78, 61,
        210, 286, 315, 382, 394, 271, 113,
        32, 34, 30, 31, 33, 2, 3, 848, 5,
    ];

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

    // ─── Counts for pills ────────────────────────────────────────────────────

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

    // ─── Main fixture loader ─────────────────────────────────────────────────

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

            $country    = $fixture->league?->country ?? '';
            $leagueName = ($country ? $country . ' — ' : '') . ($fixture->league?->name ?? 'Ostale lige');
            $leagueApiId = $fixture->league?->api_league_id;

            $grouped[$leagueName][] = [
                'id'             => $fixture->id,
                'league_api_id'  => $leagueApiId,
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

        // Sort: priority 1, 2, 3 on top (sorted by priority number), then 99 alphabetically
        $priorityMap = self::PRIORITY_LEAGUES;

        $priorityGroups = [1 => [], 2 => [], 3 => []];
        $otherGroups    = [];

        foreach ($grouped as $leagueName => $leagueFixtures) {
            $apiId    = $leagueFixtures[0]['league_api_id'] ?? null;
            $priority = $apiId !== null ? ($priorityMap[$apiId] ?? 99) : 99;

            if ($priority <= 3) {
                $priorityGroups[$priority][$leagueName] = $leagueFixtures;
            } else {
                $otherGroups[$leagueName] = $leagueFixtures;
            }
        }

        // Sort "other" alphabetically
        ksort($otherGroups);

        $this->fixtures = array_merge(
            $priorityGroups[1],
            $priorityGroups[2],
            $priorityGroups[3],
            $otherGroups
        );

        $this->counts = $this->getCounts();
    }

    protected function loadBasketballGames(): void
    {
        $liveStatuses = ['Q1','Q2','Q3','Q4','HT','OT','LIVE','BP','BT'];
        $ftStatuses   = ['FT','AOT'];

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
