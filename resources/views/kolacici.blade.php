@extends('layouts.app')

@section('title', 'Politika kolačića — rezultati.net')
@section('meta_description', 'Saznajte koje kolačiće koristi rezultati.net, zašto ih koristimo i kako možete upravljati vašim postavkama.')

@php
    $metaTitle = 'Politika kolačića — rezultati.net';
    $metaDescription = 'Saznajte koje kolačiće koristi rezultati.net, zašto ih koristimo i kako možete upravljati vašim postavkama.';
@endphp

<x-slot name="slot">
<div class="max-w-3xl mx-auto px-4 py-8 text-gray-300">

    <h1 class="text-2xl font-bold text-white mb-2">Politika kolačića</h1>
    <p class="text-xs text-gray-500 mb-8">Posljednje ažuriranje: {{ date('d.m.Y') }}</p>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">Šta su kolačići?</h2>
        <p class="text-sm leading-relaxed">
            Kolačići (eng. <em>cookies</em>) su male tekstualne datoteke koje web stranica pohranjuje u vaš preglednik.
            Koristimo ih isključivo u svrhu poboljšanja usluge i razumijevanja kako korisnici koriste naš sajt.
            Nema kolačića za reklamiranje ili prodaju podataka trećim stranama.
        </p>
    </section>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">Koje kolačiće koristimo?</h2>

        <div class="overflow-x-auto">
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="border-b border-[#2a2a2a] text-gray-400 text-left">
                        <th class="py-2 pr-4 font-semibold">Kolačić</th>
                        <th class="py-2 pr-4 font-semibold">Vrsta</th>
                        <th class="py-2 pr-4 font-semibold">Svrha</th>
                        <th class="py-2 font-semibold">Trajanje</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#2a2a2a]">
                    <tr>
                        <td class="py-3 pr-4 font-mono text-xs text-[#CCFF00]">rn_cookie_consent</td>
                        <td class="py-3 pr-4">Nužni</td>
                        <td class="py-3 pr-4">Pamti vašu odluku o kolačićima</td>
                        <td class="py-3">1 godina</td>
                    </tr>
                    <tr>
                        <td class="py-3 pr-4 font-mono text-xs text-[#CCFF00]">_ga, _ga_*</td>
                        <td class="py-3 pr-4">Analitički</td>
                        <td class="py-3 pr-4">Google Analytics 4 — broj posjetilaca, trajanje sesije, najpopularnije stranice</td>
                        <td class="py-3">2 godine</td>
                    </tr>
                    <tr>
                        <td class="py-3 pr-4 font-mono text-xs text-[#CCFF00]">_fbp</td>
                        <td class="py-3 pr-4">Analitički</td>
                        <td class="py-3 pr-4">Facebook Pixel — statistike posjeta putem Facebook SDK</td>
                        <td class="py-3">3 mjeseca</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-xs text-gray-500 mt-3">
            Analitički kolačići se postavljaju <strong class="text-gray-400">isključivo uz vaš pristanak</strong>.
            Nužni kolačići (consent odluka) se postavljaju bez pristanka jer su tehnički neophodni za rad banera.
        </p>
    </section>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">Upravljanje kolačićima</h2>
        <p class="text-sm leading-relaxed mb-4">
            Svoju odluku možete promijeniti u svakom trenutku:
        </p>
        <div class="flex flex-col sm:flex-row gap-3">
            <button onclick="localStorage.removeItem('rn_cookie_consent'); location.reload();"
                class="px-5 py-2.5 text-sm font-semibold bg-[#CCFF00] text-black rounded-lg hover:brightness-110 transition">
                Resetuj moje postavke
            </button>
        </div>
        <p class="text-xs text-gray-500 mt-3">
            Klikom na "Resetuj" baner za kolačiće će se ponovo prikazati i moći ćete odabrati nove postavke.
        </p>
        <p class="text-sm leading-relaxed mt-4">
            Kolačiće možete također onemogućiti direktno u postavkama preglednika
            (Chrome, Firefox, Safari, Edge). Napominjemo da onemogućavanje svih kolačića može utjecati
            na ispravno funkcioniranje nekih dijelova sajta.
        </p>
    </section>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">Kontakt</h2>
        <p class="text-sm leading-relaxed">
            Za sva pitanja vezana uz obradu podataka i kolačiće možete nas kontaktirati na:
            <a href="mailto:contact@rezultati.net" class="text-[#CCFF00] hover:underline">contact@rezultati.net</a>
        </p>
    </section>

</div>
</x-slot>
