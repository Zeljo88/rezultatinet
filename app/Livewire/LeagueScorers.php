<?php
namespace App\Livewire;

use App\Models\League;
use App\Models\PlayerStat;
use Livewire\Component;
use Livewire\Attributes\Layout;

/**
 * Dedicated league top scorers page: /liga/{slug}/strijelci
 */
#[Layout('layouts.app')]
class LeagueScorers extends Component
{
    public string $slug = '';
    public ?League $league = null;
    public array $scorers = [];

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
        'snl'                 => 172,
        'prva-liga-crne-gore' => 394,
        'superliga-kosova'    => 351,
        'prva-liga-makedonije'=> 183,
    ];

    public function mount(string $slug): void
    {
        $this->slug = $slug;
        $apiId = $this->slugMap[$slug] ?? null;
        abort_if(!$apiId, 404);

        $this->league = League::where('api_league_id', $apiId)->firstOrFail();
        $this->loadScorers();
    }

    protected function loadScorers(): void
    {
        $season = $this->league->current_season ?? (now()->month < 8 ? now()->year - 1 : now()->year);

        $this->scorers = PlayerStat::with('player')
            ->where('league_id', $this->league->id)
            ->where('season', (string) $season)
            ->where('goals', '>', 0)
            ->orderByDesc('goals')
            ->orderByDesc('assists')
            ->take(50)
            ->get()
            ->map(fn($s) => [
                'player_name'  => $s->player?->name ?? 'N/A',
                'player_slug'  => $s->player?->slug,
                'player_photo' => $s->player?->photo_url,
                'nationality'  => $s->player?->nationality,
                'club'         => $s->player?->current_club,
                'goals'        => $s->goals,
                'assists'      => $s->assists ?? 0,
                'penalties'    => $s->penalty_goals ?? 0,
                'appearances'  => $s->appearances ?? 0,
            ])
            ->toArray();
    }

    protected function seasonLabel(): string
    {
        $s = (int) ($this->league->current_season ?? date('Y'));
        return $s . '/' . substr((string)($s + 1), -2);
    }

    protected function buildSchemaBlocks(): array
    {
        $leagueName    = $this->league->name;
        $slug          = $this->slug;
        $season        = $this->seasonLabel();
        $strijelciUrl  = "https://rezultati.net/liga/{$slug}/strijelci";
        $leagueUrl     = "https://rezultati.net/liga/{$slug}";

        $breadcrumb = [
            '@context' => 'https://schema.org',
            '@type'    => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Rezultati',   'item' => 'https://rezultati.net'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $leagueName,   'item' => $leagueUrl],
                ['@type' => 'ListItem', 'position' => 3, 'name' => 'Strijelci',   'item' => $strijelciUrl],
            ],
        ];

        return [
            json_encode($breadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ];
    }

    public function render()
    {
        $leagueName  = $this->league->name;
        $season      = $this->seasonLabel();
        $metaTitle   = "{$leagueName} {$season} Strijelci — Lista Strijelaca | rezultati.net";
        $metaDescription = "Top strijelci {$leagueName} {$season}. Golovi, asistencije i statistike svih igrača na rezultati.net.";
        $canonicalUrl = url("/liga/{$this->slug}/strijelci");

        return view('livewire.league-scorers', [
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
