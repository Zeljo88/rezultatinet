@extends('layouts.app')

@section('title', 'Rezultati uživo – Fudbal, Košarka, Tenis')

@section('content')

{{-- Hero --}}
<div class="mb-6">
    <h1 class="text-2xl font-black text-white">
        Rezultati <span class="text-brand-lime">uživo</span>
    </h1>
    <p class="text-brand-muted text-sm mt-1">
        {{ now()->locale('bs')->isoFormat('dddd, D. MMMM YYYY.') }}
    </p>
</div>

{{-- Live scores component --}}
@livewire('live-scores')

@endsection
