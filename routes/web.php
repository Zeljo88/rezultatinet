<?php
use Illuminate\Support\Facades\Route;
use App\Livewire\MatchDetail;
use App\Livewire\LeaguePage;

Route::get('/', fn() => view('home', ['sport' => 'football', 'initialTab' => 'live']));
Route::get('/kosarka', fn() => view('home', ['sport' => 'basketball', 'initialTab' => 'live']));
Route::get('/tenis', fn() => view('home', ['sport' => 'tennis', 'initialTab' => 'live']));
Route::get('/jucer', fn() => view('home', ['sport' => 'football', 'initialTab' => 'yesterday']));
Route::get('/sutra', fn() => view('home', ['sport' => 'football', 'initialTab' => 'tomorrow']));
Route::get('/utakmica/{id}', MatchDetail::class)->name('match.detail');
Route::get('/liga/{slug}', LeaguePage::class)->name('league.page');
