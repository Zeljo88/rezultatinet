{{-- Match header --}}
<div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-6 mb-4">
    <div class="text-center text-xs text-gray-500 mb-4">
        {{ $fixture->league->name }} &bull; {{ $fixture->round }}
    </div>
    @php
        $homeTeamUrl = filled($fixture->homeTeam->slug)
            ? route('team.page', ['slug' => $fixture->homeTeam->slug])
            : null;
        $awayTeamUrl = filled($fixture->awayTeam->slug)
            ? route('team.page', ['slug' => $fixture->awayTeam->slug])
            : null;
    @endphp
    <h1 class="flex items-center justify-between gap-4">
        <span class="flex-1 text-center">
            @if($fixture->homeTeam->logo_url)
                <img src="{{ $fixture->homeTeam->logo_url }}" class="w-16 h-16 mx-auto mb-2 object-contain" alt="{{ $fixture->homeTeam->name }}" loading="lazy">
            @endif
            @if($homeTeamUrl)
                <a href="{{ $homeTeamUrl }}" class="font-bold text-white text-lg hover:text-[#CCFF00] transition">{{ $fixture->homeTeam->name }}</a>
            @else
                <span class="font-bold text-white text-lg">{{ $fixture->homeTeam->name }}</span>
            @endif
        </span>
        <span class="text-center min-w-[120px]">
            @if($hasScore)
                <span class="block text-5xl font-black {{ $isLive ? 'text-[#CCFF00]' : 'text-white' }}">
                    <span id="score-home">{{ $scoreHome }}</span><span class="text-gray-500 text-3xl mx-1">-</span><span id="score-away">{{ $scoreAway }}</span>
                </span>
                @if($score && $score->home_halftime !== null && !in_array($fixture->status_short, ['1H', 'NS']))
                    <span class="block text-xs text-gray-500 mt-1">Poluvrijeme: {{ $score->home_halftime }} - {{ $score->away_halftime }}</span>
                @endif
            @else
                <span class="block text-3xl font-black text-gray-500">{{ \Carbon\Carbon::parse($fixture->kick_off)->format('H:i') }}</span>
            @endif
            <span class="block mt-2">
                @if($isLive)
                    <span class="inline-flex items-center gap-1 bg-[#FF3B30] text-white text-xs font-bold px-2 py-1 rounded">
                        <span class="w-1.5 h-1.5 bg-white rounded-full animate-pulse"></span>{{ $fixture->elapsed_minute }}{{ $fixture->elapsed_extra ? '+' . $fixture->elapsed_extra : '' }}'
                    </span>
                @elseif($isHT)
                    <span class="text-yellow-400 text-sm font-bold">Poluvrijeme</span>
                @elseif($isFT)
                    <span class="text-gray-400 text-sm">Kraj utakmice</span>
                @else
                    <span class="text-gray-500 text-xs">{{ \Carbon\Carbon::parse($fixture->kick_off)->format('d.m.Y') }}</span>
                @endif
            </span>
        </span>
        <span class="flex-1 text-center">
            @if($fixture->awayTeam->logo_url)
                <img src="{{ $fixture->awayTeam->logo_url }}" class="w-16 h-16 mx-auto mb-2 object-contain" alt="{{ $fixture->awayTeam->name }}" loading="lazy">
            @endif
            @if($awayTeamUrl)
                <a href="{{ $awayTeamUrl }}" class="font-bold text-white text-lg hover:text-[#CCFF00] transition">{{ $fixture->awayTeam->name }}</a>
            @else
                <span class="font-bold text-white text-lg">{{ $fixture->awayTeam->name }}</span>
            @endif
        </span>
    </h1>
</div>
