<?php
use Illuminate\Support\Facades\Route;
use App\Livewire\MatchDetail;

Route::get('/', fn() => view('home'));
Route::get('/kosarka', fn() => view('home'));
Route::get('/tenis', fn() => view('home'));
Route::get('/jucer', fn() => view('home'));
Route::get('/sutra', fn() => view('home'));
Route::get('/utakmica/{id}', MatchDetail::class)->name('match.detail');
