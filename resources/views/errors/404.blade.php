@extends('layouts.app')
@section('title', '404 — Stranica nije pronađena')
@section('content')
<div class="flex flex-col items-center justify-center py-24 text-center">
    <div class="text-8xl font-black text-[#CCFF00] mb-2">404</div>
    <div class="text-6xl mb-6">⚽</div>
    <h1 class="text-2xl font-black text-white mb-3">Auuut! Ova stranica nije pronađena.</h1>
    <p class="text-gray-500 text-sm mb-8 max-w-sm">Izgleda kao da je lopta otišla van terena. Stranica koju tražite ne postoji ili je premještena.</p>
    <div class="flex gap-3">
        <a href="/" class="px-6 py-3 bg-[#CCFF00] text-black font-bold rounded-lg hover:opacity-90 transition">
            ← Nazad na početnu
        </a>
        <a href="/liga/hnl" class="px-6 py-3 bg-[#1a1a1a] border border-[#2a2a2a] text-white font-bold rounded-lg hover:bg-[#222] transition">
            HNL rezultati
        </a>
    </div>
</div>
@endsection
