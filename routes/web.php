<?php
use Illuminate\Support\Facades\Route;
use App\Livewire\MatchDetail;
use App\Livewire\LeaguePage;
use App\Livewire\TopScorers;
use App\Livewire\TeamPage;
use App\Livewire\Search;
use App\Livewire\Blog;
use App\Livewire\BlogPost;

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
