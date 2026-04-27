@extends('layouts.app')

@section('title', 'Uvjeti korištenja — rezultati.net')
@section('meta_description', 'Uvjeti i pravila korištenja web sajta rezultati.net.')

@php
    $metaTitle = 'Uvjeti korištenja — rezultati.net';
    $metaDescription = 'Uvjeti i pravila korištenja web sajta rezultati.net.';
@endphp

<x-slot name="slot">
<div class="max-w-3xl mx-auto px-4 py-8 text-gray-300">

    <h1 class="text-2xl font-bold text-white mb-2">Uvjeti korištenja</h1>
    <p class="text-xs text-gray-500 mb-8">Posljednje ažuriranje: {{ date('d.m.Y') }}</p>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">1. Prihvatanje uvjeta</h2>
        <p class="text-sm leading-relaxed">
            Korištenjem web sajta rezultati.net prihvatate ove uvjete korištenja u cijelosti.
            Ako se ne slažete s navedenim uvjetima, molimo vas da prestanete koristiti sajt.
            Zadržavamo pravo izmjene ovih uvjeta u bilo koje vrijeme, a nastavak korištenja sajta
            nakon objave izmjena smatra se prihvatanjem novih uvjeta.
        </p>
    </section>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">2. Sadržaj</h2>
        <p class="text-sm leading-relaxed">
            Sav sadržaj na rezultati.net — uključujući rezultate, statistike, tabele, vijesti i analize —
            je isključivo <strong class="text-gray-200">informativnog karaktera</strong> i ne predstavlja
            garanciju tačnosti ni potpunosti informacija.
        </p>
        <p class="text-sm leading-relaxed mt-3">
            Nastojimo osigurati tačnost podataka, ali zbog prirode sporta i live prijenosa podataka
            mogu se pojaviti greške ili kašnjenja. Ne preuzimamo odgovornost za eventualne netačnosti.
        </p>
        <p class="text-sm leading-relaxed mt-3">
            Sadržaj na ovom sajtu ni na koji način ne predstavlja sportsku prognozu niti savjet
            za kladenje ili ulaganje novca.
        </p>
    </section>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">3. Kladionički sadržaj (18+)</h2>
        <p class="text-sm leading-relaxed">
            rezultati.net može sadržavati reklamne linkove i banere kladioničkih kompanija.
            Kladionički sadržaji su namijenjeni <strong class="text-gray-200">isključivo punoljetnim osobama (18+)</strong>.
        </p>
        <p class="text-sm leading-relaxed mt-3">
            Kladenje može biti štetno za zdravlje. Igrajte odgovorno. Ako imate problema s
            kompulzivnim klađenjem, potražite stručnu pomoć.
        </p>
        <p class="text-sm leading-relaxed mt-3">
            Linkovi ka kladionicama mogu biti affiliate linkovi putem kojih ostvarujemo prihod.
            Ovo ne utječe na objektivnost informativnog sadržaja sajta.
        </p>
    </section>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">4. Intelektualno vlasništvo</h2>
        <p class="text-sm leading-relaxed">
            Sav originalni sadržaj na rezultati.net — tekstovi, dizajn, grafike, logotip — je vlasništvo
            rezultati.net i zaštićen je autorskim pravima. Nije dozvoljeno kopirati, reproducirati
            niti distribuirati sadržaj bez prethodnog pismenog odobrenja.
        </p>
        <p class="text-sm leading-relaxed mt-3">
            Statistički podaci o utakmicama i igračima potiču od dobavljača podataka trećih strana
            i podliježu njihovim uvjetima korištenja.
        </p>
    </section>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">5. Ograničenje odgovornosti</h2>
        <p class="text-sm leading-relaxed">
            rezultati.net se ne može smatrati odgovornim za bilo kakvu direktnu ili indirektnu štetu
            koja može nastati korištenjem ovog sajta ili oslanjanjem na informacije objavljene na njemu.
        </p>
        <p class="text-sm leading-relaxed mt-3">
            Sajt se pruža "kakav jest" bez ikakvih garancija o neprekidnoj dostupnosti, tačnosti podataka
            ili prikladnosti za određenu namjenu. Pristup sajtu može biti privremeno onemogućen zbog
            tehničkih razloga bez prethodne najave.
        </p>
        <p class="text-sm leading-relaxed mt-3">
            Za pitanja vezana uz ove uvjete, kontaktirajte nas na:
            <a href="mailto:contact@rezultati.net" class="text-[#CCFF00] hover:underline">contact@rezultati.net</a>
        </p>
    </section>

</div>
</x-slot>
