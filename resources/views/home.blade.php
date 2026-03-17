@extends('layouts.app')
@section('title', 'Rezultati uzivo - Fudbal, Kosarka, Tenis')
@section('content')
<div class="mb-5 flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-black text-white">Rezultati <span class="text-[#CCFF00]">uzivo</span></h1>
        <p class="text-gray-500 text-sm mt-0.5">{{ now()->format('l, d. F Y.') }}</p>
    </div>
    <div class="flex items-center gap-2 bg-[#1a1a1a] border border-[#2a2a2a] rounded-lg px-3 py-2">
        <span class="w-2 h-2 rounded-full bg-[#FF3B30] animate-pulse inline-block"></span>
        <span class="text-xs text-gray-500">Azurira se automatski</span>
    </div>
</div>
@livewire('live-scores')
@endsection
