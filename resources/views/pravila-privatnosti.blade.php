@extends('layouts.app')

@section('title', 'Pravila privatnosti — rezultati.net')
@section('meta_description', 'Saznajte kako rezultati.net prikuplja, koristi i štiti vaše podatke.')

@php
    $metaTitle = 'Pravila privatnosti — rezultati.net';
    $metaDescription = 'Saznajte kako rezultati.net prikuplja, koristi i štiti vaše podatke.';
@endphp

<x-slot name="slot">
<div class="max-w-3xl mx-auto px-4 py-8 text-gray-300">

    <h1 class="text-2xl font-bold text-white mb-2">Pravila privatnosti</h1>
    <p class="text-xs text-gray-500 mb-8">Posljednje ažuriranje: {{ date('d.m.Y') }}</p>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">1. Koje podatke prikupljamo?</h2>
        <p class="text-sm leading-relaxed">
            rezultati.net ne zahtijeva registraciju niti prikuplja lične podatke poput imena, adrese
            ili e-mail adrese direktno od korisnika. Jedini podaci koji se automatski prikupljaju su
            tehnički podaci o posjeti:
        </p>
        <ul class="text-sm leading-relaxed mt-3 space-y-1 list-disc list-inside">
            <li>IP adresa (anonimizirana)</li>
            <li>Vrsta preglednika i operativnog sistema</li>
            <li>Posjećene stranice i trajanje posjete</li>
            <li>Izvor posjete (referrer)</li>
        </ul>
        <p class="text-sm leading-relaxed mt-3">
            Ovi podaci se prikupljaju isključivo putem analitičkih alata i isključivo uz vaš pristanak.
        </p>
    </section>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">2. Kolačići</h2>
        <p class="text-sm leading-relaxed">
            Koristimo kolačiće za analitiku i pamćenje vaših postavki. Detaljne informacije o vrstama
            kolačića, njihovoj svrsi i trajanju možete pronaći na našoj stranici
            <a href="/kolacici" class="text-[#CCFF00] hover:underline">Politika kolačića</a>.
        </p>
        <p class="text-sm leading-relaxed mt-3">
            Analitički kolačići se postavljaju isključivo uz vaš izričit pristanak. Svoju odluku možete
            promijeniti u svakom trenutku putem banera za kolačiće ili na stranici
            <a href="/kolacici" class="text-[#CCFF00] hover:underline">Politika kolačića</a>.
        </p>
    </section>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">3. Treće strane</h2>
        <p class="text-sm leading-relaxed">
            Naš sajt koristi usluge trećih strana koje mogu prikupljati podatke u skladu s vlastitim
            pravilima privatnosti:
        </p>
        <ul class="text-sm leading-relaxed mt-3 space-y-2 list-disc list-inside">
            <li>
                <strong class="text-gray-200">Google Analytics 4 (GA4)</strong> — analitika posjeta.
                Podaci se šalju Googleu i obrađuju u skladu s Google Privacy Policy.
            </li>
            <li>
                <strong class="text-gray-200">Kladionički partneri (affiliate)</strong> — sajt može
                sadržavati reklamne linkove ka kladionicama. Klikanjem na te linkove možete biti
                praćeni od strane partnera u skladu s njihovim pravilima privatnosti.
                Svi kladionički sadržaji su namijenjeni isključivo osobama starijim od 18 godina.
            </li>
        </ul>
    </section>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">4. Vaša prava</h2>
        <p class="text-sm leading-relaxed">
            U skladu s primjenjivim zakonodavstvom o zaštiti podataka, imate pravo na:
        </p>
        <ul class="text-sm leading-relaxed mt-3 space-y-1 list-disc list-inside">
            <li>Pristup podacima koji se o vama obrađuju</li>
            <li>Ispravak netačnih podataka</li>
            <li>Brisanje podataka ("pravo na zaborav")</li>
            <li>Ograničenje obrade podataka</li>
            <li>Prigovor na obradu podataka</li>
        </ul>
        <p class="text-sm leading-relaxed mt-3">
            Budući da ne prikupljamo lične podatke direktno, većina ovih prava se odnosi na podatke
            koje prikupljaju treće strane. Za ostvarivanje prava vezanih za Google Analytics,
            molimo vas da kontaktirate Google direktno.
        </p>
    </section>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">5. Kontakt</h2>
        <p class="text-sm leading-relaxed">
            Za sva pitanja vezana uz obradu podataka i privatnost možete nas kontaktirati na:
            <a href="mailto:contact@rezultati.net" class="text-[#CCFF00] hover:underline">contact@rezultati.net</a>
        </p>
    </section>

</div>
</x-slot>
