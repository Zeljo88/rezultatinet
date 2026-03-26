<?php
// Updated MatchDetail.php
namespace App\Livewire;

use App\Models\Fixture;
use App\Models\FixtureLineup;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

#[Layout('layouts.app')]
class MatchDetail extends Component
{
    public Fixture $fixture;
    public string $pageTitle = 'Detalji utakmice';
    public array $h2h = [];
    public string $activeTab = 'events'; // events | lineups | h2h
    public ?FixtureLineup $homeLineup = null;
    public ?FixtureLineup $awayLineup = null;

    public function mount(string|int $id = null, string $slug = null): void
    {
        if ($id !== null && is_numeric($id)) {
            // Legacy numeric ID route: /utakmica/12345
            $this->fixture = Fixture::with([
                'homeTeam', 'awayTeam', 'score', 'league', 'events'
            ])->findOrFail($id);
        } elseif ($slug !== null || ($id !== null && !is_numeric($id))) {
            // SEO slug route: /utakmica/dinamo-zagreb-vs-hajduk-split-22-03-2026
            $this->fixture = $this->resolveBySlug($slug ?? $id);
        } else {
            abort(404);
        }

        $this->pageTitle = $this->fixture->homeTeam->name . ' vs ' . $this->fixture->awayTeam->name;
        $this->loadH2H();
        $this->loadLineups();
    }

    /**
     * Resolve a fixture from a SEO slug like:
     * dinamo-zagreb-vs-hajduk-split-22-03-2026
     */
    protected function resolveBySlug(string $slug): Fixture
    {
        // Parse: {home-slug}-vs-{away-slug}-{dd-mm-yyyy}
        if (!preg_match('/^(.+)-vs-(.+)-(\d{2})-(\d{2})-(\d{4})$/', $slug, $m)) {
            abort(404);
        }

        $homeSlug = $m[1];
        $awaySlug = $m[2];
        $day      = $m[3];
        $month    = $m[4];
        $year     = $m[5];

        $date = "{$year}-{$month}-{$day}";

        $homeTeam = \App\Models\Team::where('slug', $homeSlug)->first();
        $awayTeam = \App\Models\Team::where('slug', $awaySlug)->first();

        if (!$homeTeam || !$awayTeam) {
            if (!$homeTeam) {
                $homeTeam = \App\Models\Team::whereRaw('LOWER(REPLACE(REPLACE(name, " ", "-"), ".", "")) = ?', [$homeSlug])->first();
            }
            if (!$awayTeam) {
                $awayTeam = \App\Models\Team::whereRaw('LOWER(REPLACE(REPLACE(name, " ", "-"), ".", "")) = ?', [$awaySlug])->first();
            }
        }

        if (!$homeTeam || !$awayTeam) {
            abort(404);
        }

        $fixture = Fixture::with(['homeTeam', 'awayTeam', 'score', 'league', 'events'])
            ->where('home_team_id', $homeTeam->id)
            ->where('away_team_id', $awayTeam->id)
            ->whereDate('kick_off', $date)
            ->first();

        if (!$fixture) {
            abort(404);
        }

        return $fixture;
    }

    public function loadLineups(): void
    {
        $this->homeLineup = FixtureLineup::where('fixture_id', $this->fixture->id)
            ->where('team_side', 'home')
            ->first();
        $this->awayLineup = FixtureLineup::where('fixture_id', $this->fixture->id)
            ->where('team_side', 'away')
            ->first();
    }

    public function loadH2H(): void
    {
        $homeId = $this->fixture->home_team_id;
        $awayId = $this->fixture->away_team_id;

        $this->h2h = Fixture::with(['homeTeam', 'awayTeam', 'score', 'league'])
            ->where(function($q) use ($homeId, $awayId) {
                $q->where(function($q2) use ($homeId, $awayId) {
                    $q2->where('home_team_id', $homeId)->where('away_team_id', $awayId);
                })->orWhere(function($q2) use ($homeId, $awayId) {
                    $q2->where('home_team_id', $awayId)->where('away_team_id', $homeId);
                });
            })
            ->whereIn('status_short', ['FT','AET','PEN'])
            ->where('id', '!=', $this->fixture->id)
            ->orderBy('kick_off', 'desc')
            ->take(6)
            ->get()
            ->map(function($f) {
                return [
                    'id'             => $f->id,
                    'kick_off'       => $f->kick_off,
                    'league_name'    => $f->league?->name,
                    'home_team_name' => $f->homeTeam?->name,
                    'home_team_logo' => $f->homeTeam?->logo_url,
                    'away_team_name' => $f->awayTeam?->name,
                    'away_team_logo' => $f->awayTeam?->logo_url,
                    'score_home'     => $f->score?->home_fulltime ?? $f->score?->goals_home,
                    'score_away'     => $f->score?->away_fulltime ?? $f->score?->goals_away,
                    'status'         => $f->status_short,
                ];
            })->toArray();
    }

    public function setTab(string $tab): void { $this->activeTab = $tab; }

    #[On('echo:fixture.{fixture.id},score.updated')]
    public function refresh(): void
    {
        $this->fixture = $this->fixture->fresh(['homeTeam','awayTeam','score','league','events']);
        $this->loadLineups();
    }

    /**
     * Generate the SEO-friendly canonical URL for this fixture.
     */
    public function getCanonicalUrl(): string
    {
        $homeSlug = $this->fixture->homeTeam?->slug
            ?: Str::slug($this->fixture->homeTeam?->name ?? '');
        $awaySlug = $this->fixture->awayTeam?->slug
            ?: Str::slug($this->fixture->awayTeam?->name ?? '');
        $dateStr  = $this->fixture->kick_off
            ? \Carbon\Carbon::parse($this->fixture->kick_off)->format('d-m-Y')
            : null;

        if ($homeSlug && $awaySlug && $dateStr) {
            return url("/utakmica/{$homeSlug}-vs-{$awaySlug}-{$dateStr}");
        }

        return url("/utakmica/{$this->fixture->id}");
    }

    /**
     * Determine if the match is finished (FT, AET, PEN).
     */
    protected function isFinished(): bool
    {
        return in_array($this->fixture->status_short, ['FT', 'AET', 'PEN']);
    }

    /**
     * Build meta title according to match state.
     * Upcoming: {tim_A} vs {tim_B} — Prenos Uživo | {datum} | rezultati.net
     * Finished:  {tim_A} {gol_A}:{gol_B} {tim_B} — Sažetak & Statistike | rezultati.net
     */
    protected function buildMetaTitle(): string
    {
        $homeTeam = $this->fixture->homeTeam?->name ?? '';
        $awayTeam = $this->fixture->awayTeam?->name ?? '';
        $datum    = $this->fixture->kick_off
            ? \Carbon\Carbon::parse($this->fixture->kick_off)->format('d.m.Y')
            : '';

        if ($this->isFinished()) {
            $golA = $this->fixture->score?->home_fulltime ?? $this->fixture->score?->goals_home ?? '?';
            $golB = $this->fixture->score?->away_fulltime ?? $this->fixture->score?->goals_away ?? '?';
            return "{$homeTeam} {$golA}:{$golB} {$awayTeam} — Sažetak & Statistike | rezultati.net";
        }

        return "{$homeTeam} vs {$awayTeam} — Prenos Uživo | {$datum} | rezultati.net";
    }

    /**
     * Build meta description according to match state.
     */
    protected function buildMetaDescription(): string
    {
        $homeTeam  = $this->fixture->homeTeam?->name ?? '';
        $awayTeam  = $this->fixture->awayTeam?->name ?? '';
        $liga      = $this->fixture->league?->name ?? '';

        if ($this->isFinished()) {
            $golA    = $this->fixture->score?->home_fulltime ?? $this->fixture->score?->goals_home ?? '?';
            $golB    = $this->fixture->score?->away_fulltime ?? $this->fixture->score?->goals_away ?? '?';
            $rezultat = "{$golA}:{$golB}";
            return "Kompletan izvještaj: {$homeTeam} {$rezultat} {$awayTeam}. Strijelci golova, statistike posjeda i detaljna analiza na rezultati.net.";
        }

        return "{$homeTeam} vs {$awayTeam} LIVE — pratite rezultat utakmice {$liga} uživo. Lineup, statistike i tečajevi na rezultati.net.";
    }

    /**
     * Build SportsEvent JSON-LD schema block for this fixture.
     */
    protected function buildSchemaBlocks(): array
    {
        $homeTeam = $this->fixture->homeTeam?->name ?? '';
        $awayTeam = $this->fixture->awayTeam?->name ?? '';
        $liga     = $this->fixture->league?->name ?? '';
        $stadion  = $this->fixture->venue_name ?? $liga;
        $startDate = $this->fixture->kick_off
            ? \Carbon\Carbon::parse($this->fixture->kick_off)->toIso8601String()
            : '';

        // Determine event status for schema
        $eventStatus = 'https://schema.org/EventScheduled';
        if ($this->isFinished()) {
            $eventStatus = 'https://schema.org/EventCompleted';
        } elseif (in_array($this->fixture->status_short, ['CANC', 'PST', 'ABD'])) {
            $eventStatus = 'https://schema.org/EventCancelled';
        }

        $sportsEvent = [
            '@context'   => 'https://schema.org',
            '@type'      => 'SportsEvent',
            'name'       => "{$homeTeam} vs {$awayTeam}",
            'startDate'  => $startDate,
            'location'   => [
                '@type' => 'Place',
                'name'  => $stadion,
            ],
            'homeTeam'   => [
                '@type' => 'SportsTeam',
                'name'  => $homeTeam,
            ],
            'awayTeam'   => [
                '@type' => 'SportsTeam',
                'name'  => $awayTeam,
            ],
            'organizer'  => [
                '@type' => 'Organization',
                'name'  => $liga,
            ],
            'eventStatus' => $eventStatus,
            'sport'       => 'Football',
            'url'         => $this->getCanonicalUrl(),
        ];

        // Add score if finished
        if ($this->isFinished()) {
            $golA = $this->fixture->score?->home_fulltime ?? $this->fixture->score?->goals_home;
            $golB = $this->fixture->score?->away_fulltime ?? $this->fixture->score?->goals_away;
            if ($golA !== null && $golB !== null) {
                $sportsEvent['description'] = "Konačan rezultat: {$homeTeam} {$golA}:{$golB} {$awayTeam}";
            }
        }

        // BreadcrumbList schema
        $breadcrumb = [
            '@context' => 'https://schema.org',
            '@type'    => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Rezultati', 'item' => 'https://rezultati.net'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $liga, 'item' => "https://rezultati.net/liga/{$this->fixture->league?->slug}"],
                ['@type' => 'ListItem', 'position' => 3, 'name' => "{$homeTeam} vs {$awayTeam}", 'item' => $this->getCanonicalUrl()],
            ],
        ];

        return [
            json_encode($sportsEvent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            json_encode($breadcrumb,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ];
    }

    public function render()
    {
        $canonicalUrl = $this->getCanonicalUrl();

        return view('livewire.match-detail', [
            'canonicalUrl' => $canonicalUrl,
        ])
            ->layout('layouts.app', [
                'metaTitle'       => $this->buildMetaTitle(),
                'metaDescription' => $this->buildMetaDescription(),
                'ogImage'         => null,
                'schemaBlocks'    => $this->buildSchemaBlocks(),
                'canonicalUrl'    => $canonicalUrl,
            ]);
    }
}
