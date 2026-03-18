<?php

namespace App\Services;

use App\Models\Fixture;
use App\Models\Prediction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookPostService
{
    // Priority order: higher = more important
    // Tier 1: UEFA big 3 + top 5 leagues
    // Tier 2: Balkan leagues
    const LEAGUE_PRIORITY = [
        831 => 100, // UEFA Champions League
        833 => 90,  // UEFA Europa League
        832 => 85,  // UEFA Conference League
        806 => 80,  // Premier League
        862 => 80,  // La Liga
        799 => 75,  // Serie A
        850 => 75,  // Bundesliga
        847 => 70,  // Ligue 1
        852 => 60,  // Premijer Liga BiH
        835 => 55,  // HNL Croatia
    ];

    protected string $pageId;
    protected string $accessToken;

    public function __construct()
    {
        $this->pageId      = config('services.facebook.page_id');
        $this->accessToken = config('services.facebook.page_token');
    }

    /**
     * Get top 1-2 fixtures for today worth posting about.
     */
    public function getTopFixturesToday(int $limit = 2): \Illuminate\Support\Collection
    {
        $leagueIds = array_keys(self::LEAGUE_PRIORITY);

        return Fixture::with(['homeTeam', 'awayTeam', 'league'])
            ->whereDate('kick_off', today())
            ->whereIn('league_id', $leagueIds)
            ->whereIn('status_short', ['NS', 'TBD'])
            ->get()
            ->sortByDesc(fn($f) => self::LEAGUE_PRIORITY[$f->league_id] ?? 0)
            ->take($limit)
            ->values();
    }

    /**
     * Build pre-match post text.
     */
    public function buildPreMatchText(Fixture $fixture): string
    {
        $home   = $fixture->homeTeam->name;
        $away   = $fixture->awayTeam->name;
        $league = $fixture->league->name;
        $time   = $fixture->kick_off->format('H:i');
        $url    = url('/utakmica/' . $fixture->id);

        return "\u{26BD} {$home} vs {$away}\n"
             . "\u{1F3C6} {$league} | {$time}\n\n"
             . "Ko pobjedjuje veceras? Glasaj na rezultati.net!\n"
             . "\u{1F449} {$url}\n\n"
             . "#football #rezultati #{$this->slugify($home)} #{$this->slugify($away)}";
    }

    /**
     * Build post-match result text.
     */
    public function buildPostMatchText(Fixture $fixture): ?string
    {
        if (!in_array($fixture->status_short, ['FT', 'AET', 'PEN'])) {
            return null;
        }

        $score = $fixture->score;
        if (!$score) return null;

        $home   = $fixture->homeTeam->name;
        $away   = $fixture->awayTeam->name;
        $hScore = $score->home_fulltime ?? 0;
        $aScore = $score->away_fulltime ?? 0;

        $stats = Prediction::getStats($fixture->id);
        if ($stats['total'] === 0) return null;

        if ($hScore > $aScore)     $actual = 'home';
        elseif ($aScore > $hScore) $actual = 'away';
        else                        $actual = 'draw';

        $pct = $stats[$actual];
        $url = url('/utakmica/' . $fixture->id);

        return "\u{2705} {$home} {$hScore}:{$aScore} {$away}\n\n"
             . "\u{1F3AF} {$pct}% vas je pogodilo rezultat!\n"
             . "\u{1F4CA} Pogledaj detalje: {$url}\n\n"
             . "#football #rezultati #{$this->slugify($home)} #{$this->slugify($away)}";
    }

    /**
     * Post text to Facebook Page.
     * $dryRun = true (default) logs only, does NOT call FB API.
     */
    public function postToPage(string $message, bool $dryRun = true): array
    {
        if ($dryRun) {
            Log::info('[FB DryRun] Would post to Facebook', ['message' => $message]);
            return ['dry_run' => true, 'message' => $message];
        }

        $response = Http::post("https://graph.facebook.com/v19.0/{$this->pageId}/feed", [
            'message'      => $message,
            'access_token' => $this->accessToken,
        ]);

        if ($response->failed()) {
            Log::error('[FB] Post failed', ['body' => $response->body()]);
            return ['success' => false, 'error' => $response->body()];
        }

        Log::info('[FB] Posted successfully', ['id' => $response->json('id')]);
        return ['success' => true, 'id' => $response->json('id')];
    }

    private function slugify(string $name): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));
    }
}
