<?php
namespace App\Livewire;

use App\Models\Fixture;
use App\Models\Team;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class TeamPage extends Component
{
    public Team $team;
    public array $fixtures = [];
    public array $upcoming = [];
    public string $tab = 'results';

    public function mount(string $slug): void
    {
        $this->team = Team::where('slug', $slug)->firstOrFail();
        $this->loadFixtures();
    }

    public function loadFixtures(): void
    {
        $teamId = $this->team->id;

        // Last 5 finished matches (regardless of date)
        $this->fixtures = Fixture::with(['homeTeam', 'awayTeam', 'score', 'league'])
            ->where(fn($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->whereIn('status_short', ['FT','AET','PEN'])
            ->orderBy('kick_off', 'desc')
            ->take(5)
            ->get()
            ->map(fn($f) => $this->mapFixture($f))
            ->toArray();

        // Next 5 upcoming matches (regardless of date)
        $this->upcoming = Fixture::with(['homeTeam', 'awayTeam', 'league'])
            ->where(fn($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->whereIn('status_short', ['NS','TBD'])
            ->orderBy('kick_off')
            ->take(5)
            ->get()
            ->map(fn($f) => $this->mapFixture($f))
            ->toArray();
    }

    protected function mapFixture(Fixture $f): array
    {
        $isFT = in_array($f->status_short, ['FT','AET','PEN']);
        $isHome = $f->home_team_id === $this->team->id;

        $sh = $isFT ? ($f->score?->home_fulltime ?? $f->score?->goals_home) : null;
        $sa = $isFT ? ($f->score?->away_fulltime ?? $f->score?->goals_away) : null;

        $result = null;
        if ($isFT && $sh !== null && $sa !== null) {
            if ($isHome) $result = $sh > $sa ? 'W' : ($sh === $sa ? 'D' : 'L');
            else $result = $sa > $sh ? 'W' : ($sa === $sh ? 'D' : 'L');
        }

        return [
            'id'             => $f->id,
            'kick_off'       => $f->kick_off,
            'status_short'   => $f->status_short,
            'league_name'    => $f->league?->name,
            'league_logo'    => $f->league?->logo_url,
            'home_team_name' => $f->homeTeam?->name ?? 'N/A',
            'home_team_logo' => $f->homeTeam?->logo_url,
            'away_team_name' => $f->awayTeam?->name ?? 'N/A',
            'away_team_logo' => $f->awayTeam?->logo_url,
            'score_home'     => $sh,
            'score_away'     => $sa,
            'is_home'        => $isHome,
            'result'         => $result,
            'is_ft'          => $isFT,
            'home_team_slug' => $f->homeTeam?->slug,
            'away_team_slug' => $f->awayTeam?->slug,
        ];
    }

    public function setTab(string $tab): void { $this->tab = $tab; }

    public function render()
    {
        $teamName = $this->team->name;
        $metaTitle = "{$teamName} — Rezultati, Raspored i Statistike — rezultati.net";
        $metaDescription = "Sve o {$teamName}: live rezultati, raspored utakmica, statistike igrača i pozicija u tablici na rezultati.net.";
        // Use team logo as og:image if available
        $ogImage = $this->team->logo_url ?: null;

        // BreadcrumbList schema for team page
        $breadcrumb = [
            '@context' => 'https://schema.org',
            '@type'    => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Rezultati', 'item' => 'https://rezultati.net'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $teamName, 'item' => 'https://rezultati.net/tim/' . ($this->team->slug ?? '')],
            ],
        ];
        $schemaBlocks = [
            json_encode($breadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ];

        return view('livewire.team-page')
            ->layout('layouts.app', [
                'metaTitle'       => $metaTitle,
                'metaDescription' => $metaDescription,
                'ogImage'         => $ogImage,
                'schemaBlocks'    => $schemaBlocks,
            ]);
    }
}
