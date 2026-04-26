<?php
namespace App\Livewire;

use App\Models\League;
use App\Models\Standing;
use App\Models\PlayerStat;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\Attributes\Layout;

/**
 * Dedicated league standings page: /liga/{slug}/tablica
 * Unique SEO meta + BreadcrumbList schema
 */
#[Layout('layouts.app')]
class LeagueTable extends Component
{
    public string $slug = '';
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
        $this->loadStandings();
    }

    protected function loadStandings(): void
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

    protected function seasonLabel(): string
    {
        $s = (int) ($this->league->current_season ?? date('Y'));
        return $s . '/' . substr((string)($s + 1), -2);
    }

    protected function buildSchemaBlocks(): array
    {
        $leagueName = $this->league->name;
        $slug       = $this->slug;
        $season     = $this->seasonLabel();
        $tableUrl   = "https://rezultati.net/liga/{$slug}/tablica";
        $leagueUrl  = "https://rezultati.net/liga/{$slug}";

        $breadcrumb = [
            '@context' => 'https://schema.org',
            '@type'    => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Rezultati',  'item' => 'https://rezultati.net'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $leagueName,  'item' => $leagueUrl],
                ['@type' => 'ListItem', 'position' => 3, 'name' => "Tablica {$season}", 'item' => $tableUrl],
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
        $metaTitle   = "{$leagueName} {$season} Tablica — Bodovna Tablica & Poredak | rezultati.net";
        $metaDescription = "Pratite {$leagueName} tablicu {$season}. Bodovi, poredak timova, golovi i statistike za sve klubove na rezultati.net.";
        $canonicalUrl = url("/liga/{$this->slug}/tablica");

        return view('livewire.league-table', [
            'season'      => $season,
            'leagueName'  => $leagueName,
        ])->layout('layouts.app', [
            'metaTitle'       => $metaTitle,
            'metaDescription' => $metaDescription,
            'ogImage'         => $this->league->logo_url ?: null,
            'canonicalUrl'    => $canonicalUrl,
            'schemaBlocks'    => $this->buildSchemaBlocks(),
        ]);
    }
}
