@extends('layouts.app')

@section('title', 'Kontakt — rezultati.net')
@section('meta_description', 'Kontaktirajte tim rezultati.net za pitanja, sugestije ili poslovnu suradnju.')

@php
    $metaTitle = 'Kontakt — rezultati.net';
    $metaDescription = 'Kontaktirajte tim rezultati.net za pitanja, sugestije ili poslovnu suradnju.';
@endphp

<x-slot name="slot">
<div class="max-w-3xl mx-auto px-4 py-8 text-gray-300">

    <h1 class="text-2xl font-bold text-white mb-2">Kontaktirajte nas</h1>
    <p class="text-xs text-gray-500 mb-8">Trudimo se odgovoriti na sve upite u roku od 48 sati.</p>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">Opći upiti</h2>
        <p class="text-sm leading-relaxed">
            Za pitanja, sugestije ili prijavu grešaka na sajtu, pišite nam na:
        </p>
        <p class="mt-2">
            <a href="mailto:contact@rezultati.net" class="text-[#CCFF00] hover:underline text-sm">contact@rezultati.net</a>
        </p>
    </section>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">Marketing i reklame</h2>
        <p class="text-sm leading-relaxed">
            Za poslovnu suradnju, oglašavanje i partnerstva, kontaktirajte naš marketing tim:
        </p>
        <p class="mt-2">
            <a href="mailto:marketing@rezultati.net" class="text-[#CCFF00] hover:underline text-sm">marketing@rezultati.net</a>
        </p>
    </section>

    <section class="mb-8">
        <h2 class="text-lg font-bold text-white mb-3">Napomena</h2>
        <p class="text-sm leading-relaxed">
            Trudimo se odgovoriti na sve upite u roku od 48 sati. Molimo vas da u poruci jasno navedete
            predmet upita kako bismo vam mogli što brže i preciznije odgovoriti.
        </p>
        <p class="text-sm leading-relaxed mt-3">
            Napominjemo da ne pružamo savjete za kladenje niti preporuke sportskih prognoza.
            Sav sadržaj na rezultati.net je isključivo informativnog karaktera.
        </p>
    </section>

</div>
</x-slot>
