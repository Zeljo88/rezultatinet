@extends('layouts.app')

@php
$title = "{$name} — rezultati i raspored | rezultati.net";
$description = "Pratite {$name}: dostupne rezultate, raspored utakmica i podatke o mečevima na rezultati.net.";
@endphp
@section('title', $title)
@section('meta_description', $description)

@push('schema')
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $name,
    'url' => url('/liga/' . $slug),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush

@section('content')
<header class="mb-5">
    <h1 class="text-2xl font-black text-white">{{ $name }} {{ $seasonLabel }} — rezultati, raspored i poredak</h1>
</header>

<section class="mb-7 bg-[#111] border border-[#2a2a2a] rounded-xl p-5 text-sm leading-relaxed text-gray-300 space-y-3">
@if($slug === 'evroliga')
    <p>Evroliga stranica objedinjuje dostupne rezultate i raspored za aktuelnu sezonu. U pregledu utakmica prvo pronađite završene susrete i objavljene naredne termine, a status i rezultat uvijek čitajte iz prikazanih podataka, bez zaključaka iz statičnog teksta.</p>
    <p>Kada su podaci dostupni, pregled utakmica daje kontekst takmičenja. Poredak i stranice ekipa nisu prikazani dok za njih ne postoje pouzdana odredišta. Time svaka poveznica ostaje valjana, a stranica ne predstavlja podatke koji nisu objavljeni.</p>
    <p>Za kompletan program vratite se na <a href="/kosarka" class="text-[#CCFF00] hover:underline">košarkaški centar</a>, a za sažetak sportskog dana otvorite <a href="/utakmice-danas" class="text-[#CCFF00] hover:underline">utakmice danas</a>.</p>
@else
    <p>ABA Liga stranica služi kao centralni pregled dostupnih utakmica i rezultata za aktuelnu sezonu. Završeni mečevi i objavljeni naredni termini razdvojeni su tako da se odmah vidi šta je odigrano i šta slijedi. Rezultat i status preuzimaju se iz prikazanih podataka.</p>
    <p>Poveznice prema ekipama, poretku i dodatnim tabelama dodat će se samo uz stvarno dostupna kanonska odredišta. Kada nema novih termina, stranica ne izmišlja raspored niti prikazuje nepouzdane sezonske tvrdnje.</p>
    <p>Za ostala takmičenja otvorite <a href="/kosarka" class="text-[#CCFF00] hover:underline">košarkaški centar</a>. Dnevni program podržanih sportova nalazi se na stranici <a href="/utakmice-danas" class="text-[#CCFF00] hover:underline">utakmice danas</a>.</p>
@endif
</section>

@include('seo.partials.event-windows', ['windows' => $windows])

@if(empty($windows['live']) && empty($windows['upcoming']) && empty($windows['recent']))
<div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-8 text-center text-gray-400">Trenutno nema objavljenih utakmica za prikaz.</div>
@endif
@endsection
