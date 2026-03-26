<?php
namespace App\Livewire;

use App\Models\League;
use App\Models\Standing;
use App\Models\PlayerStat;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class TablePage extends Component
{
    public string $leagueSlug = '';
    public ?League $league = null;
    public array $standings = [];

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
    ];

    protected array $leagueDisplayNames = [
        'hnl'                 => 'Hrvatska Nogometna Liga',
        'superliga-srbija'    => 'Super liga Srbije',
        'premijer-liga-bih'   => 'Premijer Liga BiH',
        'champions-liga'      => 'UEFA Liga Prvaka',
        'europa-liga'         => 'UEFA Europa Liga',
        'konferencijska-liga' => 'UEFA Konferencijska Liga',
        'premier-league'      => 'Engleska Premier liga',
        'la-liga'             => 'Španjolska La Liga',
        'serie-a'             => 'Talijanska Serie A',
        'bundesliga'          => 'Njemačka Bundesliga',
        'ligue-1'             => 'Francuska Ligue 1',
        'prva-liga-srbija'    => 'Prva Liga Srbije',
        'hnl-2'               => 'HNL 2 — Druga Hrvatska Liga',
        'prva-liga-fbih'      => 'Prva Liga FBiH',
        'prva-liga-rs'        => 'Prva Liga RS',
    ];

    protected array $seoDescriptions = [
        'hnl'                 => 'HNL tablica — trenutni poredak timova u Hrvatskoj nogometnoj ligi. Bodovi, golovi, pobjede i statistike za svaki klub na rezultati.net.',
        'superliga-srbija'    => 'Super liga Srbije tablica — trenutna rang-lista klubova u srpskom fudbalskom prvoligu. Bodovi, golovi i statistike na rezultati.net.',
        'premijer-liga-bih'   => 'Premijer liga BiH tablica — poredak timova u najvišem rangu fudbalskog takmičenja Bosne i Hercegovine. Bodovi i statistike na rezultati.net.',
        'champions-liga'      => 'Liga prvaka tablica — poredak i statistike svih timova u UEFA Ligi prvaka. Bodovi, golovi i grupe na rezultati.net.',
        'europa-liga'         => 'Europa liga tablica — poredak i statistike svih timova u UEFA Europi ligi. Bodovi i statistike na rezultati.net.',
        'konferencijska-liga' => 'Konferencijska liga tablica — poredak timova u UEFA Konferencijskoj ligi. Bodovi i statistike na rezultati.net.',
        'premier-league'      => 'Premier League tablica — poredak 20 engleskih klubova u najgledanijoj ligi na svijetu. Bodovi i statistike na rezultati.net.',
        'la-liga'             => 'La Liga tablica — poredak timova u španjolskoj La Ligi. Bodovi, golovi i statistike za sve klubove na rezultati.net.',
        'serie-a'             => 'Serie A tablica — poredak timova u talijanskoj Serie A. Bodovi, golovi i statistike za sve klubove na rezultati.net.',
        'bundesliga'          => 'Bundesliga tablica — poredak timova u njemačkoj Bundesligi. Bodovi, golovi i statistike za sve klubove na rezultati.net.',
        'ligue-1'             => 'Ligue 1 tablica — poredak timova u francuskoj Ligue 1. Bodovi, golovi i statistike za sve klubove na rezultati.net.',
    ];

    public function mount(string $leagueSlug): void
    {
        $this->leagueSlug = $leagueSlug;
        $apiId = $this->slugMap[$leagueSlug] ?? null;
        abort_if(!$apiId, 404);

        $this->league = League::where('api_league_id', $apiId)->firstOrFail();
        $this->loadStandings();
    }

    protected function loadStandings(): void
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

    protected function getTopScorers(): array
    {
        return PlayerStat::with('player')
            ->where('league_id', $this->league->id)
            ->where('goals', '>', 0)
            ->orderByDesc('goals')
            ->take(10)
            ->get()
            ->map(fn($s) => [
                'player_name' => $s->player?->name ?? 'N/A',
                'player_slug' => $s->player?->slug,
                'club'        => $s->player?->current_club,
                'goals'       => $s->goals,
                'assists'     => $s->assists ?? 0,
            ])
            ->toArray();
    }

    protected function seasonLabel(): string
    {
        $s = (int) ($this->league->current_season ?? date('Y'));
        return $s . '/' . substr((string)($s + 1), -2);
    }

    protected function getDisplayName(): string
    {
        return $this->leagueDisplayNames[$this->leagueSlug] ?? $this->league->name;
    }

    protected function getSeoDescription(): string
    {
        $season = $this->seasonLabel();
        $name = $this->getDisplayName();
        $nameShort = $this->league->name;
        if (isset($this->seoDescriptions[$this->leagueSlug])) {
            return $this->seoDescriptions[$this->leagueSlug];
        }
        return "{$nameShort} tablica {$season} — trenutni poredak timova. Bodovi, golovi i statistike na rezultati.net.";
    }

    protected function buildSchemaBlocks(): array
    {
        $blocks = [];
        $leagueName = $this->getDisplayName();
        $slug = $this->leagueSlug;
        $tableUrl = "https://rezultati.net/tablica/{$slug}";
        $leagueUrl = "https://rezultati.net/liga/{$slug}";

        $breadcrumb = [
            '@context' => 'https://schema.org',
            '@type'    => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Rezultati', 'item' => 'https://rezultati.net'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $leagueName, 'item' => $leagueUrl],
                ['@type' => 'ListItem', 'position' => 3, 'name' => 'Tablica', 'item' => $tableUrl],
            ],
        ];
        $blocks[] = json_encode($breadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return $blocks;
    }

    public function render()
    {
        $displayName = $this->getDisplayName();
        $season = $this->seasonLabel();
        $leagueName = $this->league->name;

        $metaTitle = "{$leagueName} Tablica {$season} — {$displayName} | rezultati.net";
        $metaDescription = $this->getSeoDescription();
        $ogImage = $this->league->logo_url ?: null;
        $canonicalUrl = url("/tablica/{$this->leagueSlug}");

        return view('livewire.table-page', [
            'season'        => $season,
            'displayName'   => $displayName,
            'seoDescription'=> $this->getSeoDescription(),
            'topScorers'    => $this->getTopScorers(),
        ])->layout('layouts.app', [
            'metaTitle'       => $metaTitle,
            'metaDescription' => $metaDescription,
            'ogImage'         => $ogImage,
            'canonicalUrl'    => $canonicalUrl,
            'schemaBlocks'    => $this->buildSchemaBlocks(),
        ]);
    }
}
