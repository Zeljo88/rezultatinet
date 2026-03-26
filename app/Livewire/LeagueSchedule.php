<?php
namespace App\Livewire;

use App\Models\Fixture;
use App\Models\League;
use Livewire\Component;
use Livewire\Attributes\Layout;

/**
 * Dedicated league schedule page: /liga/{slug}/raspored
 * Shows upcoming + recent fixtures for the league.
 */
#[Layout('layouts.app')]
class LeagueSchedule extends Component
{
    public string $slug = '';
    public ?League $league = null;
    public array $upcomingFixtures = [];
    public array $recentFixtures   = [];

    protected array $slugMap = [
        'hnl'                 => 210,
        'superliga-srbija'    => 286,
        'premijer-liga-bih'   => 315,
        'champions-liga'      => 2,
        'europa-liga'         => 3,
        'konferencijska-liga' => 848,
        'premier-league'      => 39,
        'la-liga'             => 140,
        'serie-a'             => 135,
        'bundesliga'          => 78,
        'ligue-1'             => 61,
        'prva-liga-srbija'    => 287,
        'hnl-2'               => 946,
        'prva-liga-fbih'      => 316,
        'prva-liga-rs'        => 317,
        'kup-hrvatska'        => 212,
        'kup-bosna'           => 314,
        'kup-srbija'          => 732,
    ];

    public function mount(string $slug): void
    {
        $this->slug = $slug;
        $apiId = $this->slugMap[$slug] ?? null;
        abort_if(!$apiId, 404);

        $this->league = League::where('api_league_id', $apiId)->firstOrFail();
        $this->loadFixtures();
    }

    protected function loadFixtures(): void
    {
        $ftStatuses   = ['FT', 'AET', 'PEN'];
        $liveStatuses = ['1H', '2H', 'HT', 'ET', 'BT', 'P', 'LIVE'];

        $mapFixture = function ($f) use ($ftStatuses, $liveStatuses) {
            $isFT   = in_array($f->status_short, $ftStatuses);
            $isLive = in_array($f->status_short, $liveStatuses);
            $isHT   = $f->status_short === 'HT';

            if ($isFT)            { $sh = $f->score?->home_fulltime ?? $f->score?->goals_home; $sa = $f->score?->away_fulltime ?? $f->score?->goals_away; }
            elseif ($isLive || $isHT) { $sh = $f->score?->goals_home ?? $f->score?->home_fulltime; $sa = $f->score?->goals_away ?? $f->score?->away_fulltime; }
            else                  { $sh = null; $sa = null; }

            return [
                'id'             => $f->id,
                'status_short'   => $f->status_short,
                'elapsed_minute' => $f->elapsed_minute,
                'kick_off'       => $f->kick_off,
                'home_team_name' => $f->homeTeam?->name ?? 'N/A',
                'home_team_logo' => $f->homeTeam?->logo_url,
                'away_team_name' => $f->awayTeam?->name ?? 'N/A',
                'away_team_logo' => $f->awayTeam?->logo_url,
                'home_team_slug' => $f->homeTeam?->slug,
                'away_team_slug' => $f->awayTeam?->slug,
                'score_home'     => $sh,
                'score_away'     => $sa,
                'is_live'        => $isLive || $isHT,
                'is_ft'          => $isFT,
                'round'          => $f->round,
            ];
        };

        // Upcoming: next 10 fixtures
        $this->upcomingFixtures = Fixture::with(['homeTeam', 'awayTeam', 'score'])
            ->where('league_id', $this->league->id)
            ->whereIn('status_short', ['NS', 'TBD'])
            ->where('kick_off', '>=', now())
            ->orderBy('kick_off')
            ->take(20)
            ->get()
            ->map($mapFixture)
            ->toArray();

        // Recent: last 10 finished fixtures
        $this->recentFixtures = Fixture::with(['homeTeam', 'awayTeam', 'score'])
            ->where('league_id', $this->league->id)
            ->whereIn('status_short', ['FT', 'AET', 'PEN'])
            ->where('kick_off', '<=', now())
            ->orderBy('kick_off', 'desc')
            ->take(20)
            ->get()
            ->map($mapFixture)
            ->toArray();
    }

    protected function seasonLabel(): string
    {
        $s = (int) ($this->league->current_season ?? date('Y'));
        return $s . '/' . substr((string)($s + 1), -2);
    }

    protected function buildSchemaBlocks(): array
    {
        $leagueName   = $this->league->name;
        $slug         = $this->slug;
        $season       = $this->seasonLabel();
        $rasporedUrl  = "https://rezultati.net/liga/{$slug}/raspored";
        $leagueUrl    = "https://rezultati.net/liga/{$slug}";

        $breadcrumb = [
            '@context' => 'https://schema.org',
            '@type'    => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Rezultati',   'item' => 'https://rezultati.net'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $leagueName,   'item' => $leagueUrl],
                ['@type' => 'ListItem', 'position' => 3, 'name' => 'Raspored',    'item' => $rasporedUrl],
            ],
        ];

        return [
            json_encode($breadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ];
    }

    public function render()
    {
        $leagueName   = $this->league->name;
        $season       = $this->seasonLabel();
        $metaTitle    = "{$leagueName} {$season} Raspored — Sljedeće & Prethodne Utakmice | rezultati.net";
        $metaDescription = "Raspored utakmica {$leagueName} {$season}. Datumi, termini i rezultati svih kola — sljedeće i prethodne utakmice na rezultati.net.";
        $canonicalUrl = url("/liga/{$this->slug}/raspored");

        return view('livewire.league-schedule', [
            'season'     => $season,
            'leagueName' => $leagueName,
        ])->layout('layouts.app', [
            'metaTitle'       => $metaTitle,
            'metaDescription' => $metaDescription,
            'ogImage'         => $this->league->logo_url ?: null,
            'canonicalUrl'    => $canonicalUrl,
            'schemaBlocks'    => $this->buildSchemaBlocks(),
        ]);
    }
}
