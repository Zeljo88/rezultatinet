@extends('layouts.app')

@section('title', 'O nama — rezultati.net')
@section('meta_description', 'rezultati.net je vaš izvor live rezultata i statistika fudbala, košarke i tenisa za Balkan i top europske lige.')

@php
    $metaTitle = 'O nama — rezultati.net';
    $metaDescription = 'rezultati.net je vaš izvor live rezultata i statistika fudbala, košarke i tenisa za Balkan i top europske lige.';
@endphp

<x-slot name="slot">
<div class="max-w-3xl mx-auto px-4 py-8 text-gray-300">

    <h1 class="text-2xl font-bold text-white mb-2">O rezultati.net</h1>
    <p class="text-xs text-gray-500 mb-8">Vaš izvor live rezultata za Balkan i Europu</p>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">Ko smo mi?</h2>
        <p class="text-sm leading-relaxed">
            rezultati.net je sportski informativni portal osnovan 2025. godine s fokusom na fudbalske rezultate
            i statistike za tržište Balkana. Naš primarni fokus su regionalne lige — <strong class="text-gray-200">HNL</strong>,
            <strong class="text-gray-200">SuperLiga Srbije</strong> i <strong class="text-gray-200">Premijer liga BiH</strong> —
            ali pratimo i sve top5 europskih liga te najvažnija europska natjecanja poput Lige prvaka,
            Europske lige i Konferencijske lige.
        </p>
        <p class="text-sm leading-relaxed mt-3">
            Naš cilj je pružiti korisnicima iz Bosne i Hercegovine, Hrvatske i Srbije brz, pouzdan
            i pregledan pristup svim relevantnim fudbalskim informacijama na jednom mjestu.
        </p>
    </section>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">Šta nudimo?</h2>
        <ul class="text-sm leading-relaxed space-y-2 list-disc list-inside">
            <li><strong class="text-gray-200">Live rezultati</strong> — praćenje utakmica u realnom vremenu</li>
            <li><strong class="text-gray-200">Live statistike</strong> — posjed lopte, udarci, korneri i više</li>
            <li><strong class="text-gray-200">Pregled utakmica</strong> — detaljni podaci za sve odigrane i predstojeće utakmice</li>
            <li><strong class="text-gray-200">Tabele liga</strong> — ažurirane tabele za sve praćene lige</li>
            <li><strong class="text-gray-200">Top strijelci</strong> — liste strijelaca po ligama i sezonama</li>
            <li><strong class="text-gray-200">Profili igrača</strong> — statistike i karijerni podaci za igrače iz regije i Evrope</li>
            <li><strong class="text-gray-200">Vijesti i analize</strong> — tekstovi s naglaskom na balkanski fudbal</li>
        </ul>
    </section>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">Naša misija</h2>
        <p class="text-sm leading-relaxed">
            Naša misija je biti <strong class="text-gray-200">najbolji izvor fudbalskih informacija</strong> za korisnike
            iz Bosne i Hercegovine, Hrvatske i Srbije. Vjerujemo da balkanski navijači zaslužuju platformu koja
            ravnopravno tretira domaće i europske lige — bez kompromisa u brzini ili kvaliteti podataka.
        </p>
        <p class="text-sm leading-relaxed mt-3">
            Sve informacije na sajtu su isključivo informativnog karaktera i ne predstavljaju sportske prognoze
            niti savjete za kladenje.
        </p>
    </section>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">Kontakt</h2>
        <p class="text-sm leading-relaxed">
            Za opće upite: <a href="mailto:contact@rezultati.net" class="text-[#CCFF00] hover:underline">contact@rezultati.net</a>
        </p>
        <p class="text-sm leading-relaxed mt-2">
            Za marketing i poslovnu suradnju: <a href="mailto:marketing@rezultati.net" class="text-[#CCFF00] hover:underline">marketing@rezultati.net</a>
        </p>
    </section>

</div>
</x-slot>
