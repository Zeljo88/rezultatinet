<?php
namespace App\Livewire;

use App\Models\Fixture;
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
        // Basketball and tennis not yet synced from API
        $this->sportAvailable = ($sport === 'football');
        if ($this->sportAvailable) {
            $this->loadFixtures();
        }
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

    // ─── Counts for pills (runs one query, counts in PHP) ────────────────────

    public function getCounts(): array
    {
        $all = $this->baseQuery()->get();

        return [
            'all'      => $all->count(),
            'live'     => $all->whereIn('status_short', ['1H','2H','HT','ET','BT','P'])->count(),
            'upcoming' => $all->where('status_short', 'NS')->count(),
            'finished' => $all->whereIn('status_short', ['FT','AET','PEN'])->count(),
        ];
    }

    // ─── Main fixture loader ──────────────────────────────────────────────────

    public function loadFixtures(): void
    {
        $query = $this->baseQuery();

        // Apply status filter
        match ($this->filter) {
            'uzivo'    => $query->whereIn('fixtures.status_short', ['1H','2H','HT','ET','BT','P']),
            'zakazano' => $query->where('fixtures.status_short', 'NS'),
            'zavrseno' => $query->whereIn('fixtures.status_short', ['FT','AET','PEN']),
            default    => null, // 'sve' — no extra constraint
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

            $leagueName = $fixture->league?->name ?? 'Ostale lige';
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

    // ─── Legacy tab support (kept for any external calls) ────────────────────

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
