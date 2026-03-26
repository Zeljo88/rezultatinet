<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use App\Livewire\MatchDetail;
use App\Livewire\LeaguePage;
use App\Livewire\TopScorers;
use App\Livewire\TeamPage;
use App\Livewire\Search;
use App\Livewire\Blog;
use App\Http\Controllers\OgImageController;
use App\Livewire\BlogPost;
use App\Livewire\BalkanPlayers;
use App\Livewire\PlayerProfile;
use App\Livewire\RefereeStats;
use App\Livewire\TablePage;
use App\Livewire\LeagueTable;
use App\Livewire\LeagueSchedule;
use App\Livewire\LeagueScorers;

Route::get('/', fn() => view('home', ['sport' => 'football', 'initialTab' => 'live']));
Route::get('/kosarka', fn() => view('home', ['sport' => 'basketball', 'initialTab' => 'live']));
Route::get('/tenis', fn() => view('home', ['sport' => 'tennis', 'initialTab' => 'live']));
Route::get('/jucer', fn() => view('home', ['sport' => 'football', 'initialTab' => 'yesterday']));
Route::get('/sutra', fn() => view('home', ['sport' => 'football', 'initialTab' => 'tomorrow']));

// Legacy numeric ID route (keep for backwards compatibility)
Route::get('/utakmica/{id}', MatchDetail::class)
    ->where('id', '[0-9]+')
    ->name('match.detail');

// SEO-friendly match URL: /utakmica/dinamo-zagreb-vs-hajduk-split-22-03-2026
Route::get('/utakmica/{slug}', MatchDetail::class)
    ->where('slug', '[a-z0-9\-]+-vs-[a-z0-9\-]+-[0-9]{2}-[0-9]{2}-[0-9]{4}')
    ->name('match.show');

Route::get('/strijelci', TopScorers::class)->name('top.scorers');
Route::get('/tim/{slug}', TeamPage::class)->name('team.page');
Route::get('/liga/{slug}', LeaguePage::class)->name('league.page');

// League sub-pages: tablica, raspored, strijelci
Route::get('/liga/{slug}/tablica',   LeagueTable::class)->name('league.table');
Route::get('/liga/{slug}/raspored',  LeagueSchedule::class)->name('league.schedule');
Route::get('/liga/{slug}/strijelci', LeagueScorers::class)->name('league.scorers');
Route::get('/tablica/{leagueSlug}', TablePage::class)->name('table.show');
Route::get('/pretraga', Search::class)->name('search');
Route::get('/og/match', [OgImageController::class, 'matchImage'])->name('og.match-image');

Route::get('/blog', Blog::class)->name('blog');
Route::get('/blog/{slug}', BlogPost::class)->name('blog.post');
Route::get('/igraci/balkan', BalkanPlayers::class)->name('balkan.players');
Route::get('/igraci/{slug}', PlayerProfile::class)->name('player.profile');
Route::get('/sudija', RefereeStats::class)->name('referee.list');
Route::get('/sudija/{slug}', RefereeStats::class)->name('referee.show');

// ─────────────────────────────────────────
// SITEMAP INDEX
// ─────────────────────────────────────────
Route::get('/sitemap.xml', function () {
    $sitemaps = [
        ['loc' => url('/sitemap-leagues.xml'),  'lastmod' => now()->toAtomString()],
        ['loc' => url('/sitemap-teams.xml'),    'lastmod' => now()->toAtomString()],
        ['loc' => url('/sitemap-blog.xml'),     'lastmod' => now()->toAtomString()],
        ['loc' => url('/sitemap-matches.xml'),  'lastmod' => now()->toAtomString()],
    ];

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($sitemaps as $sm) {
        $xml .= "  <sitemap>\n";
        $xml .= "    <loc>{$sm['loc']}</loc>\n";
        $xml .= "    <lastmod>{$sm['lastmod']}</lastmod>\n";
        $xml .= "  </sitemap>\n";
    }
    $xml .= '</sitemapindex>';

    return response($xml, 200)->header('Content-Type', 'application/xml');
})->name('sitemap.index');

// ─────────────────────────────────────────
// SITEMAP: LEAGUES
// ─────────────────────────────────────────
Route::get('/sitemap-leagues.xml', function () {
    $urls = collect();

    // Core pages
    $urls->push(['loc' => url('/'),            'changefreq' => 'always',  'priority' => '1.0']);
    $urls->push(['loc' => url('/blog'),        'changefreq' => 'daily',   'priority' => '0.9']);
    $urls->push(['loc' => url('/strijelci'),   'changefreq' => 'daily',   'priority' => '0.8']);
    $urls->push(['loc' => url('/igraci/balkan'), 'changefreq' => 'weekly', 'priority' => '0.7']);

    // All active league pages with known slugs
    $leagues = [
        'hnl', 'superliga-srbija', 'premijer-liga-bih', 'hnl-2',
        'prva-liga-fbih', 'prva-liga-rs', 'champions-liga', 'europa-liga',
        'konferencijska-liga', 'premier-league', 'la-liga', 'serie-a',
        'bundesliga', 'ligue-1',
    ];
    foreach ($leagues as $slug) {
        $urls->push(['loc' => url("/liga/{$slug}"), 'changefreq' => 'hourly', 'priority' => '0.8']);
    }

    // Tablica (standings) pages — high SEO value for '{league} tablica' searches
    $tablicaLeagues = [
        'hnl', 'superliga-srbija', 'premijer-liga-bih',
        'premier-league', 'la-liga', 'serie-a', 'bundesliga', 'ligue-1',
        'champions-liga', 'europa-liga', 'konferencijska-liga',
    ];
    foreach ($tablicaLeagues as $slug) {
        $urls->push(['loc' => url("/tablica/{$slug}"), 'changefreq' => 'daily', 'priority' => '0.9']);
    }

    // New league sub-pages (tablica, raspored, strijelci)
    $subPageLeagues = [
        'hnl', 'superliga-srbija', 'premijer-liga-bih', 'hnl-2',
        'prva-liga-fbih', 'prva-liga-rs', 'champions-liga', 'europa-liga',
        'konferencijska-liga', 'premier-league', 'la-liga', 'serie-a',
        'bundesliga', 'ligue-1',
    ];
    foreach ($subPageLeagues as $slug) {
        $urls->push(['loc' => url("/liga/{$slug}/tablica"),   'changefreq' => 'daily',  'priority' => '0.8']);
        $urls->push(['loc' => url("/liga/{$slug}/raspored"),  'changefreq' => 'daily',  'priority' => '0.7']);
        $urls->push(['loc' => url("/liga/{$slug}/strijelci"), 'changefreq' => 'weekly', 'priority' => '0.7']);
    }

    $urls = $urls->toArray();
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $url) {
        $xml .= "  <url>\n";
        $xml .= "    <loc>" . htmlspecialchars($url['loc']) . "</loc>\n";
        if (!empty($url['lastmod'])) $xml .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
        $xml .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
        $xml .= "    <priority>{$url['priority']}</priority>\n";
        $xml .= "  </url>\n";
    }
    $xml .= '</urlset>';
    return response($xml, 200)->header('Content-Type', 'application/xml');
})->name('sitemap.leagues');

// ─────────────────────────────────────────
// SITEMAP: TEAMS (Balkan + top5 only)
// ─────────────────────────────────────────
Route::get('/sitemap-teams.xml', function () {
    // Balkan countries + top5 European leagues
    $balkanCountries = ['Croatia', 'Bosnia', 'Serbia', 'Slovenia', 'Montenegro', 'Macedonia'];
    $top5LeagueIds   = [39, 140, 135, 78, 61];      // PL, La Liga, Serie A, Bundesliga, Ligue 1
    $euCupLeagueIds  = [2, 3, 848];                   // UCL, UEL, UECL

    // Get league IDs for Balkan countries
    $balkanLeagueIds = \App\Models\League::whereIn('country', $balkanCountries)
        ->pluck('id');

    // Get league IDs for top5 + cups (by api_league_id)
    $top5DbIds = \App\Models\League::whereIn('api_league_id', array_merge($top5LeagueIds, $euCupLeagueIds))
        ->pluck('id');

    $allRelevantLeagueIds = $balkanLeagueIds->merge($top5DbIds)->unique()->values();

    // Get teams that appear in fixtures for these leagues (last 2 seasons)
    $teamIds = \App\Models\Fixture::whereIn('league_id', $allRelevantLeagueIds)
        ->where('season', '>=', 2024)
        ->select(['home_team_id', 'away_team_id'])
        ->get()
        ->flatMap(fn($f) => [$f->home_team_id, $f->away_team_id])
        ->unique();

    $teams = \App\Models\Team::whereIn('id', $teamIds)
        ->whereNotNull('slug')
        ->where('slug', '!=', '')
        ->select(['slug'])
        ->get();

    $urls = $teams->map(fn($t) => [
        'loc'        => url("/tim/{$t->slug}"),
        'changefreq' => 'daily',
        'priority'   => '0.6',
    ])->toArray();

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $url) {
        $xml .= "  <url>\n";
        $xml .= "    <loc>" . htmlspecialchars($url['loc']) . "</loc>\n";
        if (!empty($url['lastmod'])) {
            $xml .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
        }
        $xml .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
        $xml .= "    <priority>{$url['priority']}</priority>\n";
        $xml .= "  </url>\n";
    }
    $xml .= '</urlset>';
    return response($xml, 200)->header('Content-Type', 'application/xml');
})->name('sitemap.teams');

// ─────────────────────────────────────────
// SITEMAP: BLOG POSTS
// ─────────────────────────────────────────
Route::get('/sitemap-blog.xml', function () {
    $urls = \App\Models\Post::where('published', 1)
        ->select(['slug', 'updated_at'])
        ->get()
        ->map(fn($p) => [
            'loc'        => url("/blog/{$p->slug}"),
            'changefreq' => 'monthly',
            'priority'   => '0.7',
            'lastmod'    => $p->updated_at?->toAtomString(),
        ])->toArray();

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $url) {
        $xml .= "  <url>\n";
        $xml .= "    <loc>" . htmlspecialchars($url['loc']) . "</loc>\n";
        if (!empty($url['lastmod'])) {
            $xml .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
        }
        $xml .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
        $xml .= "    <priority>{$url['priority']}</priority>\n";
        $xml .= "  </url>\n";
    }
    $xml .= '</urlset>';
    return response($xml, 200)->header('Content-Type', 'application/xml');
})->name('sitemap.blog');

// ─────────────────────────────────────────
// SITEMAP: MATCHES (recent 30d + next 7d)
// ─────────────────────────────────────────
Route::get('/sitemap-matches.xml', function () {
    $from = now()->subDays(30);
    $to   = now()->addDays(7);

    $fixtures = \App\Models\Fixture::with(['homeTeam', 'awayTeam'])
        ->whereBetween('kick_off', [$from, $to])
        ->whereNotNull('home_team_id')
        ->whereNotNull('away_team_id')
        ->select(['id', 'home_team_id', 'away_team_id', 'kick_off', 'status_short', 'updated_at'])
        ->get();

    $urls = $fixtures->map(function ($f) {
        $homeSlug = $f->homeTeam?->slug ?: Str::slug($f->homeTeam?->name ?? '');
        $awaySlug = $f->awayTeam?->slug ?: Str::slug($f->awayTeam?->name ?? '');
        if (!$homeSlug || !$awaySlug) return null;
        $dateStr  = $f->kick_off ? $f->kick_off->format('d-m-Y') : null;
        if (!$dateStr) return null;

        $slug = "{$homeSlug}-vs-{$awaySlug}-{$dateStr}";

        return [
            'loc'        => url("/utakmica/{$slug}"),
            'changefreq' => in_array($f->status_short, ['FT','AET','PEN']) ? 'monthly' : 'hourly',
            'priority'   => '0.7',
            'lastmod'    => $f->updated_at?->toAtomString(),
        ];
    })->filter()->values()->toArray();

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $url) {
        $xml .= "  <url>\n";
        $xml .= "    <loc>" . htmlspecialchars($url['loc']) . "</loc>\n";
        if (!empty($url['lastmod'])) {
            $xml .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
        }
        $xml .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
        $xml .= "    <priority>{$url['priority']}</priority>\n";
        $xml .= "  </url>\n";
    }
    $xml .= '</urlset>';
    return response($xml, 200)->header('Content-Type', 'application/xml');
})->name('sitemap.matches');

// ─────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────

// ─────────────────────────────────────────
// RSS FEED
// ─────────────────────────────────────────
use App\Http\Controllers\FeedController;

Route::get('/feed', [FeedController::class, 'rss'])->name('feed.rss');
Route::get('/rss', [FeedController::class, 'rss'])->name('feed.rss.alt');
Route::get('/feed.xml', [FeedController::class, 'rss'])->name('feed.xml');

// ─────────────────────────────────────────
// ADMIN PANEL
// ─────────────────────────────────────────
use App\Http\Controllers\Admin\PostController;
use App\Http\Middleware\AdminAuth;

Route::get('/admin/login', fn() => view('admin.login'))->name('admin.login');
Route::post('/admin/login', function(\Illuminate\Http\Request $request) {
    $token = config('app.admin_token');
    if ($request->input('password') === $token) {
        session(['admin_authenticated' => true]);
        return redirect()->route('admin.posts.index');
    }
    return back()->withErrors(['password' => 'Pogresna lozinka.']);
})->name('admin.login.post');

Route::post('/admin/logout', function() {
    session()->forget('admin_authenticated');
    return redirect()->route('admin.login');
})->name('admin.logout');

Route::middleware(AdminAuth::class)->prefix('admin')->name('admin.')->group(function () {
    Route::resource('posts', PostController::class);
});
