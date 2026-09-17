@extends('layouts.app')

@php
$hubConfig = [
    'football' => [
        'title' => 'Nogomet rezultati uživo, raspored i lige | rezultati.net',
        'description' => 'Pratite nogometne rezultate uživo, raspored utakmica i ligaška takmičenja. Brzo pronađite mečeve, status igre i povezane stranice liga.',
        'h1' => 'Nogomet rezultati uživo i raspored',
    ],
    'basketball' => [
        'title' => 'Košarka rezultati uživo, raspored i lige | rezultati.net',
        'description' => 'Pratite košarkaške rezultate uživo, raspored utakmica i stranice liga. Pronađite današnje mečeve, završene rezultate i naredne termine.',
        'h1' => 'Košarka rezultati uživo i raspored',
    ],
    'tennis' => [
        'title' => 'Tenis rezultati uživo, raspored i turniri | rezultati.net',
        'description' => 'Pratite teniske rezultate uživo, raspored mečeva i turnire. Pregledajte današnje susrete, završene rezultate i naredne termine na jednom mjestu.',
        'h1' => 'Tenis rezultati uživo i raspored mečeva',
    ],
];
$tabTitles = ['yesterday' => 'Jučerašnji rezultati | Sve utakmice', 'tomorrow' => 'Sutrašnje utakmice | Raspored i termini'];
$tabDescs = ['yesterday' => 'Pogledajte sve jučerašnje rezultate nogometnih utakmica.', 'tomorrow' => 'Pogledajte raspored sutrašnjih nogometnih utakmica i termine.'];
$currentSport = $sport ?? 'football';
$currentTab = $initialTab ?? 'live';
$isHub = isset($hub) && isset($hubConfig[$hub]);
$pageTitle = $isHub ? $hubConfig[$hub]['title'] : ($tabTitles[$currentTab] ?? 'Rezultati Uživo ⚽ Danas | HNL, Liga Prvaka, Bundesliga — rezultati.net');
$pageDesc = $isHub ? $hubConfig[$hub]['description'] : ($tabDescs[$currentTab] ?? 'Pratite rezultate uživo, današnji raspored i najvažnije sportske lige na rezultati.net.');
$dateLabel = now()->locale('bs')->translatedFormat('j. F Y.');
@endphp

@section('title', $pageTitle)
@section('meta_description', $pageDesc)

@if($isHub)
@push('schema')
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $hubConfig[$hub]['h1'],
    'url' => url()->current(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush
@endif

@section('content')
<div class="mb-5 flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-black text-white">{{ $isHub ? $hubConfig[$hub]['h1'] : 'Rezultati uživo' }}</h1>
        <p class="text-gray-500 text-sm mt-0.5">{{ $dateLabel }}</p>
    </div>
    <div class="flex items-center gap-2 bg-[#1a1a1a] border border-[#2a2a2a] rounded-lg px-3 py-2">
        <span class="w-2 h-2 rounded-full bg-[#FF3B30] animate-pulse inline-block"></span>
        <span class="text-xs text-gray-500">Ažurira se automatski</span>
    </div>
</div>

@if($isHub)
<section class="mb-6 bg-[#111] border border-[#2a2a2a] rounded-xl p-5 text-sm leading-relaxed text-gray-300 space-y-3">
    @if($hub === 'football')
        <p>Na jednom mjestu pregledajte nogometne utakmice koje se igraju danas, završene rezultate i naredne termine. Mečevi su organizovani tako da brzo možete pronaći ligu, ekipe i status utakmice, bez prolaska kroz nepovezane stranice. Odaberite datum za pregled programa, a zatim otvorite pojedinačnu utakmicu kada su dostupni dodatni detalji.</p>
        <p>Stranica povezuje dnevni pregled s korisnim stranicama liga. Nastavite na <a href="/liga/hnl" class="text-[#CCFF00] hover:underline">HNL rezultate i poredak</a>, <a href="/liga/premijer-liga-bih" class="text-[#CCFF00] hover:underline">Premijer ligu BiH</a> ili <a href="/liga/superliga-srbija" class="text-[#CCFF00] hover:underline">Super ligu Srbije</a>. Na stranici odabrane lige pronađite dostupne rezultate, naredne utakmice i pregled poretka.</p>
        <p>Ako se trenutno ne igra nijedna utakmica, promijenite datum ili provjerite završene i predstojeće mečeve ispod. Za drugi sport otvorite <a href="/kosarka" class="text-[#CCFF00] hover:underline">košarkaške</a> ili <a href="/tenis" class="text-[#CCFF00] hover:underline">teniske rezultate</a>, a za sažet prikaz koristite <a href="/utakmice-danas" class="text-[#CCFF00] hover:underline">utakmice danas</a>. Vrijeme početka i status čitajte iz prikazanih podataka jer se raspored može mijenjati.</p>
    @elseif($hub === 'basketball')
        <p>Košarkaški centar donosi pregled utakmica za odabrani datum, uz jasno izdvojene mečeve uživo, završene susrete i naredne termine kada su podaci dostupni. Svaki zapis prikazuje ekipe, vrijeme početka i aktuelni status. Promjenom datuma možete provjeriti ranije rezultate ili pogledati šta slijedi.</p>
        <p>Za detaljnije praćenje otvorite zasebne stranice za <a href="/liga/evroliga" class="text-[#CCFF00] hover:underline">Evroligu</a> i <a href="/liga/aba-liga" class="text-[#CCFF00] hover:underline">ABA Ligu</a>. One prikazuju samo stvarno dostupne rezultate i raspored; poredak i veze prema ekipama ne predstavljaju se dok pouzdana odredišta nisu dostupna.</p>
        <p>Ako za {{ $dateLabel }} nema utakmica, pregledajte prethodni ili naredni termin te dostupne završene rezultate ispod. Rezultat i status uvijek provjerite na kartici meča. Ljubitelji drugih sportova mogu nastaviti prema <a href="/nogomet" class="text-[#CCFF00] hover:underline">nogometnom</a> ili <a href="/tenis" class="text-[#CCFF00] hover:underline">teniskom centru</a>, dok <a href="/utakmice-danas" class="text-[#CCFF00] hover:underline">utakmice danas</a> daju sažet pregled programa.</p>
    @else
        <p>Teniski centar omogućava pregled mečeva prema datumu i statusu. Na jednom mjestu pronađite susrete koji su u toku, završene rezultate i naredne termine kada su podaci dostupni. Uz svaki meč navedeni su igrači, planirano vrijeme početka i aktuelni status.</p>
        <p>Za brže snalaženje koristite podjelu prema turniru ili odaberite drugi datum. To je posebno korisno kada za {{ $dateLabel }} nema aktivnih mečeva: prethodni dan vodi do završenih rezultata, a naredni do objavljenog rasporeda. Stranice turnira i igrača povezujemo samo kada postoje valjana odredišta i podaci.</p>
        <p>Rezultat, setove i status pratite iz prikaza meča jer se tok susreta i termini mogu mijenjati. Ako želite drugi sport, otvorite <a href="/kosarka" class="text-[#CCFF00] hover:underline">košarkaške</a> ili <a href="/nogomet" class="text-[#CCFF00] hover:underline">nogometne rezultate</a>. Za širi dnevni pregled dostupne su <a href="/utakmice-danas" class="text-[#CCFF00] hover:underline">utakmice danas</a>.</p>
    @endif
</section>
@else
<section class="mb-5 bg-[#111] border border-[#2a2a2a] rounded-xl p-4">
    <h2 class="font-bold mb-3">Sportski centri</h2>
    <div class="flex flex-wrap gap-3 text-sm">
        <a href="/nogomet" class="text-[#CCFF00] hover:underline">Nogomet</a>
        <a href="/kosarka" class="text-[#CCFF00] hover:underline">Košarka</a>
        <a href="/tenis" class="text-[#CCFF00] hover:underline">Tenis</a>
        <a href="/utakmice-danas" class="text-[#CCFF00] hover:underline">Utakmice danas</a>
    </div>
</section>
@endif

@include('components.derby-countdown')
<x-affiliate-banner ad-slot="homepage-top" extra-class="mb-4" />
@livewire('live-scores', ['initialTab' => $initialTab ?? 'live', 'sport' => $sport ?? 'football'])

@if($isHub)
<div class="mt-8">
    @include('seo.partials.event-windows', ['windows' => $seoWindows])
</div>
@endif
@endsection
