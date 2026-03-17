<?php
use Illuminate\Support\Facades\Route;
Route::get('/', fn() => view('home'));
Route::get('/kosarka', fn() => view('home'));
Route::get('/tenis', fn() => view('home'));
Route::get('/jucer', fn() => view('home'));
Route::get('/sutra', fn() => view('home'));
