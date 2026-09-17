@php
    $windowLabels = [
        'live' => 'Uživo',
        'upcoming' => 'Naredni termini',
        'recent' => 'Završeni rezultati',
    ];
@endphp
<div class="grid gap-4 md:grid-cols-3">
    @foreach($windowLabels as $windowKey => $windowLabel)
        @if(!empty($windows[$windowKey]))
        <section class="bg-[#111] border border-[#2a2a2a] rounded-xl overflow-hidden">
            <h2 class="text-base font-bold text-white px-4 py-3 border-b border-[#2a2a2a]">{{ $windowLabel }}</h2>
            <ul class="divide-y divide-[#2a2a2a]">
                @foreach($windows[$windowKey] as $event)
                <li class="px-4 py-3 text-sm">
                    <div class="text-[11px] text-gray-500 mb-1">
                        {{ $event['date'] ? \Carbon\Carbon::parse($event['date'])->format('d.m.Y H:i') : '' }}
                        @if(!empty($event['league'])) · {{ $event['league'] }} @endif
                    </div>
                    <div class="font-semibold text-gray-200">
                        @if(!empty($event['url']))<a href="{{ $event['url'] }}" class="hover:text-[#CCFF00]">@endif
                        {{ $event['home'] }} <span class="text-gray-500">—</span> {{ $event['away'] }}
                        @if(!empty($event['url']))</a>@endif
                    </div>
                    @if(isset($event['home_score']) && $event['home_score'] !== null)
                        <div class="text-xs text-gray-400 mt-1">Rezultat: {{ $event['home_score'] }}–{{ $event['away_score'] }}</div>
                    @elseif(!empty($event['score']))
                        <div class="text-xs text-gray-400 mt-1">{{ $event['score'] }}</div>
                    @endif
                    @if(!empty($event['home_slug']) || !empty($event['away_slug']))
                    <div class="flex gap-3 mt-2 text-xs text-[#CCFF00]">
                        @if(!empty($event['home_slug']))<a href="{{ url('/tim/' . $event['home_slug']) }}" class="hover:underline">{{ $event['home'] }}</a>@endif
                        @if(!empty($event['away_slug']))<a href="{{ url('/tim/' . $event['away_slug']) }}" class="hover:underline">{{ $event['away'] }}</a>@endif
                    </div>
                    @endif
                </li>
                @endforeach
            </ul>
        </section>
        @endif
    @endforeach
</div>
