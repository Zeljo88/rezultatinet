<div>
    @php
        $isLive = in_array($fixture->status_short, ['1H','2H','HT','ET','BT','P','LIVE']);
        $isFT = in_array($fixture->status_short, ['FT','AET','PEN']);
        $hasScore = $isLive || $isFT || $fixture->status_short === 'HT';
    @endphp

    {{-- Back --}}
    <a href="/" class="inline-flex items-center gap-2 text-gray-400 hover:text-white text-sm mb-4 transition">
        &larr; Nazad
    </a>

    {{-- Match header --}}
    <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-6 mb-4">
        <div class="text-center text-xs text-gray-500 mb-4">
            {{ $fixture->league->name }} &bull; {{ $fixture->round }}
        </div>

        <div class="flex items-center justify-between gap-4">
            {{-- Home team --}}
            <div class="flex-1 text-center">
                @if($fixture->homeTeam->logo_url)
                    <img src="{{ $fixture->homeTeam->logo_url }}" class="w-16 h-16 mx-auto mb-2 object-contain" alt="{{ $fixture->homeTeam->name }}">
                @endif
                <p class="font-bold text-white text-lg">{{ $fixture->homeTeam->name }}</p>
            </div>

            {{-- Score --}}
            <div class="text-center min-w-[120px]">
                @if($hasScore)
                    <div class="text-5xl font-black text-white">
                        {{ $fixture->score?->home_fulltime ?? 0 }}
                        <span class="text-gray-500 text-3xl mx-1">-</span>
                        {{ $fixture->score?->away_fulltime ?? 0 }}
                    </div>
                    @if($fixture->score?->home_halftime !== null)
                        <div class="text-xs text-gray-500 mt-1">
                            Poluvrijeme: {{ $fixture->score->home_halftime }} - {{ $fixture->score->away_halftime }}
                        </div>
                    @endif
                @else
                    <div class="text-3xl font-black text-gray-500">
                        {{ \Carbon\Carbon::parse($fixture->kick_off)->format('H:i') }}
                    </div>
                @endif

                <div class="mt-2">
                    @if($isLive)
                        <span class="inline-flex items-center gap-1 bg-[#FF3B30] text-white text-xs font-bold px-2 py-1 rounded">
                            <span class="w-1.5 h-1.5 bg-white rounded-full animate-pulse"></span>
                            {{ $fixture->elapsed_minute }}'
                        </span>
                    @elseif($fixture->status_short === 'HT')
                        <span class="text-yellow-400 text-sm font-bold">Poluvrijeme</span>
                    @elseif($isFT)
                        <span class="text-gray-400 text-sm">Kraj utakmice</span>
                    @else
                        <span class="text-gray-500 text-xs">{{ \Carbon\Carbon::parse($fixture->kick_off)->format('d.m.Y') }}</span>
                    @endif
                </div>
            </div>

            {{-- Away team --}}
            <div class="flex-1 text-center">
                @if($fixture->awayTeam->logo_url)
                    <img src="{{ $fixture->awayTeam->logo_url }}" class="w-16 h-16 mx-auto mb-2 object-contain" alt="{{ $fixture->awayTeam->name }}">
                @endif
                <p class="font-bold text-white text-lg">{{ $fixture->awayTeam->name }}</p>
            </div>
        </div>
    </div>

    {{-- Events timeline --}}
    @if($fixture->events->count() > 0)
    <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-4 mb-4">
        <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-3">Dogadjaji</h3>
        @foreach($fixture->events->sortBy('elapsed_minute') as $event)
        @php
            $isHome = isset($event->team_id) && $event->team_id === $fixture->home_team_id;
            $icon = match($event->type) {
                'Goal' => $event->detail === 'Own Goal' ? '&#x26BD;&#xFE0F;' : '&#x26BD;',
                'Card' => $event->detail === 'Yellow Card' ? '&#x1F7E8;' : '&#x1F7E5;',
                'subst' => '&#x21C4;',
                default => '&#x2022;'
            };
        @endphp
        <div class="flex items-center gap-3 py-2 border-b border-[#2a2a2a] last:border-0">
            <span class="text-xs text-gray-500 w-8 text-right">{{ $event->elapsed_minute }}'</span>
            <span class="text-sm">{!! $icon !!}</span>
            @if($isHome)
                <span class="text-sm text-white flex-1">{{ $event->player_name }}</span>
                <span class="flex-1"></span>
            @else
                <span class="flex-1"></span>
                <span class="text-sm text-white flex-1 text-right">{{ $event->player_name }}</span>
            @endif
        </div>
        @endforeach
    </div>
    @endif

    {{-- Venue info --}}
    @if($fixture->venue_name)
    <div class="text-center text-xs text-gray-500">
        &#x1F3DF; {{ $fixture->venue_name }}
        @if($fixture->referee) &bull; Sudija: {{ $fixture->referee }} @endif
    </div>
    @endif
</div>
