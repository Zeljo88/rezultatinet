<?php
namespace App\Livewire;

use App\Models\Fixture;
use App\Models\League;
use App\Models\PlayerStat;
use App\Models\Standing;
use Illuminate\Support\Facades\Cache;
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
    public string $slug = '';

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
        'kup-srbija'          => 732,
        'major-league-soccer' => 253,
        'snl'                 => 172,
        'prva-liga-crne-gore' => 394,
        'superliga-kosova'    => 351,
        'prva-liga-makedonije'=> 183,
    ];

    /** SEO-rich H1 labels (without season, appended dynamically) */
    protected array $seoH1Labels = [
        'hnl'                => 'HNL Rezultati Uživo — Hrvatska Nogometna Liga',
        'superliga-srbija'   => 'Super liga Srbije — Rezultati Uživo i Tablica',
        'premijer-liga-bih'  => 'Premijer liga BiH — Rezultati Uživo, Tablica i Poredak',
        'champions-liga'     => 'UEFA Liga prvaka — Rezultati Uživo i Sudionici',
        'europa-liga'        => 'UEFA Europa liga — Rezultati Uživo i Sudionici',
        'konferencijska-liga'=> 'UEFA Konferencijska liga — Rezultati Uživo',
        'premier-league'     => 'Premier League Rezultati Uživo — Engleska Premier liga',
        'la-liga'            => 'La Liga Rezultati Uživo — Španjolska Primera Division',
        'serie-a'            => 'Serie A Rezultati Uživo — Talijanska Serie A',
        'bundesliga'         => 'Bundesliga Rezultati Uživo — Njemačka Bundesliga',
        'ligue-1'            => 'Ligue 1 Rezultati Uživo — Francuska Ligue 1',
        'prva-liga-srbija'   => 'Prva liga Srbije — Rezultati Uživo i Tablica',
        'first-nl-hrvatska'  => 'Prva NL Hrvatska — Rezultati Uživo i Tablica',
        'hnl-2'              => 'HNL 2 Rezultati Uživo — Druga hrvatska liga',
        'prva-liga-fbih'     => 'Prva liga FBiH — Rezultati Uživo i Tablica',
        'prva-liga-rs'       => 'Prva liga RS — Rezultati Uživo i Tablica',
        'kup-hrvatska'       => 'Kup Hrvatske — Rezultati i Raspored',
        'kup-bosna'          => 'Kup Bosne i Hercegovine — Rezultati i Raspored',
        'kup-srbija'          => 'Kup Srbije — Rezultati i Raspored',
        'major-league-soccer' => 'Major League Soccer (MLS) — Rezultati Uživo i Tablica',
        'snl'                 => 'SNL Slovenija — Prva liga Rezultati Uživo i Tablica',
        'prva-liga-crne-gore' => 'Prva liga Crne Gore — Rezultati Uživo i Tablica',
        'superliga-kosova'    => 'Superliga Kosova — Rezultati Uživo i Tablica',
        'prva-liga-makedonije'=> 'Prva liga Sjeverne Makedonije — Rezultati Uživo i Tablica',
    ];

    /** SEO descriptive paragraphs per league slug */
    protected array $seoDescriptions = [
        'hnl'                => 'Hrvatska nogometna liga (HNL) je najviši razred klupskog nogometa u Hrvatskoj. Prati sve HNL rezultate uživo, tablicu, strijelce i raspored utakmica na rezultati.net.',
        'superliga-srbija'   => 'Super liga Srbije je vrhunsko fudbalsko takmičenje u Srbiji. Pratite rezultate uživo, tablicu i statistike Super lige na rezultati.net.',
        'premijer-liga-bih'  => 'Premijer liga Bosne i Hercegovine je najviši rang fudbalskog takmičenja u BiH. Pratite live rezultate, tablicu, poredak i raspored Premijer lige BiH na rezultati.net.',
        'major-league-soccer' => 'Major League Soccer (MLS) je najviše fudbalsko takmičenje u SAD-u i Kanadi. Pratite MLS rezultate uživo, tablicu i statistike na rezultati.net.',
        'snl'                 => 'Slovenačka Prva liga (SNL) je najviši rang klupskog fudbala u Sloveniji. Pratite SNL rezultate uživo, tablicu i raspored na rezultati.net.',
        'prva-liga-crne-gore' => 'Prva liga Crne Gore je najviši rang klupskog fudbala u Crnoj Gori. Pratite rezultate uživo, tablicu i raspored na rezultati.net.',
        'superliga-kosova'    => 'Superliga Kosova je najviši rang klupskog fudbala na Kosovu. Pratite rezultate uživo, tablicu i statistike na rezultati.net.',
        'prva-liga-makedonije'=> 'Prva liga Sjeverne Makedonije je najviši rang klupskog fudbala u Sjevernoj Makedoniji. Pratite rezultate uživo, tablicu i raspored na rezultati.net.',
        'champions-liga'     => 'UEFA Liga prvaka je najprestižnije klupsko nogometno natjecanje u Europi. Pratite sve rezultate Lige prvaka uživo, strijelce i statistike na rezultati.net.',
        'europa-liga'        => 'UEFA Europa liga je drugo najprestižnije klupsko natjecanje u Europi. Pratite rezultate Europske lige uživo, strijelce i statistike na rezultati.net.',
        'konferencijska-liga'=> 'UEFA Konferencijska liga nudi uzbudljive utakmice klupskog nogometa širom Europe. Pratite rezultate i statistike na rezultati.net.',
        'premier-league'     => 'Engleska Premier liga je jedna od najpopularnijih ligaških natjecanja na svijetu. Prati Premier League rezultate uživo, tablicu i statistike na rezultati.net.',
        'la-liga'            => 'Španjolska La Liga je jedno od najprestižnijih ligaških natjecanja na svijetu. Pratite La Liga rezultate uživo, tablicu i statistike na rezultati.net.',
        'serie-a'            => 'Talijanska Serie A je vrhunski razred klupskog nogometa u Italiji. Pratite Serie A rezultate uživo, tablicu i statistike na rezultati.net.',
        'bundesliga'         => 'Njemačka Bundesliga je najpraćenija liga u Europi. Pratite sve Bundesliga rezultate uživo, tablicu i statistike na rezultati.net.',
        'ligue-1'            => 'Francuska Ligue 1 je vrhunski razred klupskog nogometa u Francuskoj. Pratite Ligue 1 rezultate uživo, tablicu i statistike na rezultati.net.',
    ];

    public function mount(string $slug): void
    {
        $this->slug = $slug;
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
        $leagueId = $this->league->id;
        $this->standings = Cache::remember("standings:{$leagueId}", 300, function () use ($leagueId) {
            return Standing::with('team')
                ->where('league_id', $leagueId)
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
        });
    }

    public function setTab(string $tab): void { $this->tab = $tab; $this->loadFixtures(); }
    public function setView(string $view): void { $this->view = $view; }

    /** Format current_season (e.g. 2025 → "2025/26") */
    protected function seasonLabel(): string
    {
        $s = (int) ($this->league->current_season ?? date('Y'));
        return $s . '/' . substr((string)($s + 1), -2);
    }

    /** Return SEO-rich H1 string */
    protected function getSeoH1(): string
    {
        $season = $this->seasonLabel();
        if (isset($this->seoH1Labels[$this->slug])) {
            return $this->seoH1Labels[$this->slug] . ' ' . $season;
        }
        return $this->league->name . ' — Rezultati Uživo i Tablica ' . $season;
    }

    /** Return SEO description for the league */
    protected function getSeoDescription(): string
    {
        if (isset($this->seoDescriptions[$this->slug])) {
            return $this->seoDescriptions[$this->slug];
        }
        return $this->league->name . ' — pratite sve rezultate uživo, tablicu i statistike na rezultati.net.';
    }

    /** Upcoming fixtures for SEO content block (SSR, no polling) */
    protected function getUpcomingFixtures(): array
    {
        return Fixture::with(['homeTeam', 'awayTeam'])
            ->where('league_id', $this->league->id)
            ->whereIn('status_short', ['NS', 'TBD'])
            ->where('kick_off', '>=', now())
            ->orderBy('kick_off')
            ->take(5)
            ->get()
            ->map(fn($f) => [
                'kick_off'       => $f->kick_off,
                'home_team_name' => $f->homeTeam?->name ?? 'N/A',
                'away_team_name' => $f->awayTeam?->name ?? 'N/A',
                'home_team_slug' => $f->homeTeam?->slug,
                'away_team_slug' => $f->awayTeam?->slug,
            ])
            ->toArray();
    }

    /** Top scorers for this league (SSR, no polling) */
    protected function getTopScorers(): array
    {
        $leagueId = $this->league->id;
        return Cache::remember("top_scorers:{$leagueId}", 1800, function () use ($leagueId) {
            return PlayerStat::with('player')
                ->where('league_id', $leagueId)
                ->where('goals', '>', 0)
                ->orderByDesc('goals')
                ->take(5)
                ->get()
                ->map(fn($s) => [
                    'player_name' => $s->player?->name ?? 'N/A',
                    'player_slug' => $s->player?->slug,
                    'club'        => $s->player?->current_club,
                    'goals'       => $s->goals,
                    'assists'     => $s->assists,
                ])
                ->toArray();
        });
    }

    protected function buildSchemaBlocks(): array
    {
        $blocks = [];
        $leagueName = $this->league->name;
        $slug = $this->slug;
        $season = $this->league->current_season ?? date('Y');
        $leagueUrl = "https://rezultati.net/liga/{$slug}";

        // BreadcrumbList schema
        $breadcrumb = [
            '@context' => 'https://schema.org',
            '@type'    => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Rezultati', 'item' => 'https://rezultati.net'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $leagueName, 'item' => $leagueUrl],
            ],
        ];
        $blocks[] = json_encode($breadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        // SportsEvent schema for today's scheduled fixtures
        $todayFixtures = Fixture::with(['homeTeam', 'awayTeam'])
            ->where('league_id', $this->league->id)
            ->whereDate('kick_off', today())
            ->whereIn('status_short', ['NS', 'TBD', '1H', '2H', 'HT', 'ET', 'BT', 'P', 'LIVE'])
            ->orderBy('kick_off')
            ->take(10)
            ->get();

        foreach ($todayFixtures as $fixture) {
            $event = [
                '@context'    => 'https://schema.org',
                '@type'       => 'SportsEvent',
                'name'        => ($fixture->homeTeam?->name ?? '') . ' vs ' . ($fixture->awayTeam?->name ?? ''),
                'startDate'   => $fixture->kick_off ? \Carbon\Carbon::parse($fixture->kick_off)->toIso8601String() : '',
                'homeTeam'    => ['@type' => 'SportsTeam', 'name' => $fixture->homeTeam?->name ?? ''],
                'awayTeam'    => ['@type' => 'SportsTeam', 'name' => $fixture->awayTeam?->name ?? ''],
                'eventStatus' => 'https://schema.org/EventScheduled',
                'sport'       => 'Football',
                'url'         => $leagueUrl,
            ];
            $blocks[] = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        return $blocks;
    }

    public function render()
    {
        $leagueName = $this->league->name;
        $season = $this->seasonLabel();

        $metaTitleOverrides = [
            'premijer-liga-bih' => "Premijer liga BiH {$season} — Rezultati Uživo, Tablica i Poredak | rezultati.net",
        ];
        $metaDescOverrides = [
            'premijer-liga-bih' => "Premijer liga BiH {$season} — pratite live rezultate, tablicu, poredak i raspored na rezultati.net.",
        ];

        $metaTitle = $metaTitleOverrides[$this->slug] ?? "{$leagueName} {$season} — Rezultati Uživo & Tablica | rezultati.net";
        $metaDescription = $metaDescOverrides[$this->slug] ?? "Pratite {$leagueName} rezultate uživo, tablicu, strijelce i raspored. Sve o {$leagueName} na jednom mjestu.";
        $ogImage = $this->league->logo_url ?: null;

        return view('livewire.league-page', [
            'seoH1'            => $this->getSeoH1(),
            'seasonLabel'      => $this->seasonLabel(),
            'seoDescription'   => $this->getSeoDescription(),
            'seoUpcoming'      => $this->getUpcomingFixtures(),
            'seoTopScorers'    => $this->getTopScorers(),
            'seoStandings'     => array_slice($this->standings, 0, 8),
        ])->layout('layouts.app', [
            'metaTitle'       => $metaTitle,
            'metaDescription' => $metaDescription,
            'ogImage'         => $ogImage,
            'schemaBlocks'    => $this->buildSchemaBlocks(),
        ]);
    }
}
