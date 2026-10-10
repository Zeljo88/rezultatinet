@extends('layouts.app')

@section('title', 'Utakmice danas — rezultati uživo i raspored | rezultati.net')
@section('meta_description', "Pogledajte utakmice za {$dateLabel}: rezultate uživo, završene susrete i objavljeni raspored. Odaberite sport, ligu ili pojedinačni meč.")

@push('schema')
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => "Utakmice danas — {$dateLabel}",
    'url' => url('/utakmice-danas'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush

@section('content')
<header class="mb-5">
    <h1 class="text-2xl font-black text-white">Utakmice danas — {{ $dateLabel }}</h1>
</header>

<section class="mb-6 bg-[#111] border border-[#2a2a2a] rounded-xl p-5 text-sm leading-relaxed text-gray-300 space-y-3">
    <p>Pregled utakmica za {{ $dateLabel }} okuplja sportski program prema vremenu i statusu meča. Najprije provjerite susrete koji su u toku, zatim završene rezultate i objavljene naredne termine. Odabirom sporta možete suziti prikaz na nogomet, košarku ili tenis, a poveznica uz takmičenje vodi prema njegovoj stranici kada ona postoji.</p>
    <p>Vrijeme početka, rezultat i status čitaju se iz podataka uz pojedinačni meč. Budući da se termini mogu promijeniti, ova stranica prikazuje dinamički datum i aktuelno stanje. Ako trenutno nema aktivnih utakmica, provjerite sportske centre za završene i predstojeće susrete.</p>
    <p>Za više konteksta otvorite <a href="/nogomet" class="text-[#CCFF00] hover:underline">nogometni centar</a>, <a href="/kosarka" class="text-[#CCFF00] hover:underline">košarkaški centar</a> ili <a href="/tenis" class="text-[#CCFF00] hover:underline">teniski centar</a>. Dostupne su i stranice za <a href="/liga/hnl" class="text-[#CCFF00] hover:underline">HNL</a>, <a href="/liga/premijer-liga-bih" class="text-[#CCFF00] hover:underline">Premijer ligu BiH</a> i <a href="/liga/superliga-srbija" class="text-[#CCFF00] hover:underline">Super ligu Srbije</a>.</p>
</section>

<div class="space-y-8">
    @foreach(['football' => ['Nogometne utakmice danas', 'nogomet'], 'basketball' => ['Košarkaške utakmice danas', 'kosarka'], 'tennis' => ['Teniski mečevi danas', 'tenis']] as $sport => [$label, $path])
    <section>
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-xl font-bold">{{ $label }}</h2>
            <a href="/{{ $path }}" class="text-sm text-[#CCFF00] hover:underline">Otvori centar →</a>
        </div>
        @livewire('live-scores', ['initialTab' => 'live', 'sport' => $sport], key('today-' . $sport))
    </section>
    @endforeach
</div>
@endsection
