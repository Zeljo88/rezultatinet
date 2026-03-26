@extends('layouts.app')

@php
$metaTitles = [
    'football'   => 'Rezultati Uživo ⚽ Danas | HNL, Liga Prvaka, Bundesliga — rezultati.net',
    'basketball' => 'Košarka rezultati uživo | ABA liga i više',
    'tennis'     => 'Tenis rezultati uživo | ATP, WTA, Grand Slam',
];
$metaDescs = [
    'football'   => 'Prati football rezultate uživo. HNL, SuperLiga Srbije, Premijer Liga BiH, Liga prvaka i 500+ liga. Live score, tablice i statistike na jednom mjestu.',
    'basketball' => 'Košarka rezultati uživo — ABA liga, NBA, Euroliga, HT Premijer liga i sva domaća natjecanja. Ljestvice, statistike i raspored utakmica na jednom mjestu.',
    'tennis'     => 'Tenis rezultati uživo s ATP i WTA turnira, Grand Slam natjecanja i Davis Cupa. Pratite sve mečeve u realnom vremenu — setovi, gemovi, statistike.',
];
$tabTitles = [
    'yesterday' => 'Jučerašnji rezultati | Sve utakmice',
    'tomorrow'  => 'Sutrašnje utakmice | Raspored i termini',
];
$tabDescs = [
    'yesterday' => 'Propustili ste jučerašnje utakmice? Pogledajte sve rezultate od juče — nogomet, košarka i tenis. HNL, Premijer liga, ABA liga i stotine liga diljem svijeta.',
    'tomorrow'  => 'Raspored sutrašnjih utakmica — football, košarka, tenis. Ne propustite nijedan meč. HNL, Champions liga, ABA liga i sve wichtige utakmice u jednom pregledu.',
];
$currentSport = $sport ?? 'football';
$currentTab   = $initialTab ?? 'live';
$pageTitle = $tabTitles[$currentTab] ?? ($metaTitles[$currentSport] ?? $metaTitles['football']);
$pageDesc  = $tabDescs[$currentTab]  ?? ($metaDescs[$currentSport]  ?? $metaDescs['football']);
@endphp

@section('title', $pageTitle)
@section('meta_description', $pageDesc)

@section('content')
<div class="mb-5 flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-black text-white">Rezultati <span class="text-[#CCFF00]">uživo</span></h1>
        <p class="text-gray-500 text-sm mt-0.5">
            @php
            $days = ['Sunday'=>'Nedjelja','Monday'=>'Ponedjeljak','Tuesday'=>'Utorak','Wednesday'=>'Srijeda','Thursday'=>'Cetvrtak','Friday'=>'Petak','Saturday'=>'Subota'];
            $months = ['January'=>'januar','February'=>'februar','March'=>'mart','April'=>'april','May'=>'maj','June'=>'juni','July'=>'juli','August'=>'august','September'=>'septembar','October'=>'oktobar','November'=>'novembar','December'=>'decembar'];
            $day = $days[now()->format('l')];
            $month = $months[now()->format('F')];
            echo $day . ', ' . now()->format('j') . '. ' . $month . ' ' . now()->format('Y') . '.';
            @endphp
        </p>
    </div>
    <div class="flex items-center gap-2 bg-[#1a1a1a] border border-[#2a2a2a] rounded-lg px-3 py-2">
        <span class="w-2 h-2 rounded-full bg-[#FF3B30] animate-pulse inline-block"></span>
        <span class="text-xs text-gray-500">Azurira se automatski</span>
    </div>
</div>
@include('components.derby-countdown')
<x-affiliate-banner ad-slot="homepage-top" extra-class="mb-4" />
@livewire('live-scores', ['initialTab' => $initialTab ?? 'live', 'sport' => $sport ?? 'football'])
@endsection
