<?php
use Illuminate\Support\Facades\Route;
use App\Livewire\MatchDetail;
use App\Livewire\LeaguePage;
use App\Livewire\TopScorers;
use App\Livewire\TeamPage;
use App\Livewire\Search;
use App\Livewire\Blog;
use App\Livewire\BlogPost;
use App\Livewire\BalkanPlayers;
use App\Livewire\PlayerProfile;
use App\Livewire\RefereeStats;

Route::get('/', fn() => view('home', ['sport' => 'football', 'initialTab' => 'live']));
Route::get('/kosarka', fn() => view('home', ['sport' => 'basketball', 'initialTab' => 'live']));
Route::get('/tenis', fn() => view('home', ['sport' => 'tennis', 'initialTab' => 'live']));
Route::get('/jucer', fn() => view('home', ['sport' => 'football', 'initialTab' => 'yesterday']));
Route::get('/sutra', fn() => view('home', ['sport' => 'football', 'initialTab' => 'tomorrow']));
Route::get('/utakmica/{id}', MatchDetail::class)->name('match.detail');
Route::get('/strijelci', TopScorers::class)->name('top.scorers');
Route::get('/tim/{slug}', TeamPage::class)->name('team.page');
Route::get('/liga/{slug}', LeaguePage::class)->name('league.page');
Route::get('/pretraga', Search::class)->name('search');
Route::get('/blog', Blog::class)->name('blog');
Route::get('/blog/{slug}', BlogPost::class)->name('blog.post');
Route::get('/igraci/balkan', BalkanPlayers::class)->name('balkan.players');
Route::get('/igraci/{slug}', PlayerProfile::class)->name('player.profile');
Route::get('/sudija', RefereeStats::class)->name('referee.list');
Route::get('/sudija/{slug}', RefereeStats::class)->name('referee.show');

Route::get('/sitemap.xml', function () {
    $urls = collect();

    // Core pages
    $urls->push(['loc' => url('/'), 'changefreq' => 'always', 'priority' => '1.0']);
    $urls->push(['loc' => url('/blog'), 'changefreq' => 'daily', 'priority' => '0.9']);
    $urls->push(['loc' => url('/strijelci'), 'changefreq' => 'daily', 'priority' => '0.8']);
    $urls->push(['loc' => url('/igraci/balkan'), 'changefreq' => 'weekly', 'priority' => '0.7']);

    // League pages
    $leagues = [
        'hnl', 'superliga-srbija', 'premijer-liga-bih', 'hnl-2',
        'prva-liga-fbih', 'prva-liga-rs', 'champions-liga', 'europa-liga',
        'konferencijska-liga', 'premier-league', 'la-liga', 'serie-a',
        'bundesliga', 'ligue-1'
    ];
    foreach ($leagues as $slug) {
        $urls->push(['loc' => url("/liga/{$slug}"), 'changefreq' => 'hourly', 'priority' => '0.8']);
    }

    // Team pages — all teams with slugs
    \App\Models\Team::whereNotNull('slug')->where('slug', '!=', '')->select('slug')->each(function($team) use ($urls) {
        $urls->push(['loc' => url("/tim/{$team->slug}"), 'changefreq' => 'daily', 'priority' => '0.6']);
    });

    // Blog posts
    \App\Models\Post::where('published', 1)->select('slug', 'updated_at')->each(function($post) use ($urls) {
        $urls->push([
            'loc' => url("/blog/{$post->slug}"),
            'changefreq' => 'monthly',
            'priority' => '0.7',
            'lastmod' => $post->updated_at?->toAtomString(),
        ]);
    });

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $url) {
        $xml .= "  <url>\n";
        $xml .= "    <loc>{$url['loc']}</loc>\n";
        if (!empty($url['lastmod'])) $xml .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
        $xml .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
        $xml .= "    <priority>{$url['priority']}</priority>\n";
        $xml .= "  </url>\n";
    }
    $xml .= '</urlset>';

    return response($xml, 200)->header('Content-Type', 'application/xml');
})->name('sitemap');
