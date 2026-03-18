<div>
    @if(!$sportAvailable)
    <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-16 text-center">
        <div class="text-6xl mb-4">🚧</div>
        <p class="text-white text-xl font-bold mb-2">Uskoro dostupno!</p>
        <p class="text-gray-500 text-sm">Košarka i tenis rezultati uskoro stižu na rezultati.net.</p>
        <a href="/" class="inline-block mt-6 px-4 py-2 bg-[#CCFF00] text-black text-sm font-bold rounded-lg hover:opacity-90 transition">Pogledaj fudbal &rarr;</a>
    </div>
    @else
<div>
    <div class="flex gap-2 mb-4">
        <button wire:click="setTab('live')" class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-bold transition cursor-pointer {{ $tab === 'live' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">
            <span class="w-2 h-2 rounded-full {{ $tab === 'live' ? 'bg-black' : 'bg-[#FF3B30]' }} animate-pulse inline-block"></span>
            UZIVO
        </button>
        <button wire:click="setTab('yesterday')" class="px-4 py-2 rounded-lg text-sm font-bold transition cursor-pointer {{ $tab === 'yesterday' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">Jucer</button>
        <button wire:click="setTab('today')" class="px-4 py-2 rounded-lg text-sm font-bold transition cursor-pointer {{ $tab === 'today' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">Danas</button>
        <button wire:click="setTab('tomorrow')" class="px-4 py-2 rounded-lg text-sm font-bold transition cursor-pointer {{ $tab === 'tomorrow' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">Sutra</button>
    </div>

    @if(empty($fixtures))
        <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-12 text-center">
            <div class="text-5xl mb-3">&#9917;</div>
            <p class="text-gray-400 text-lg font-semibold">
                @if($tab === 'live') Trenutno nema zivih utakmica.
                @elseif($tab === 'yesterday') Nema rezultata za jucer.
                @elseif($tab === 'tomorrow') Nema utakmica za sutra.
                @else Nema utakmica za danas.
                @endif
            </p>
            @if($tab === 'live')
                <button wire:click="setTab('today')" class="mt-4 text-[#CCFF00] text-sm hover:underline font-medium cursor-pointer">Pogledaj sve utakmice danas &rarr;</button>
            @endif
        </div>
    @else
        @foreach($fixtures as $leagueName => $leagueFixtures)
        <div class="mb-3">
            <div class="flex items-center gap-2 px-3 py-2 bg-[#1a1a1a] border border-[#2a2a2a] rounded-t-xl">
                <span class="text-white text-sm font-bold">{{ $leagueName ?: 'Ostale lige' }}</span>
                <span class="ml-auto text-gray-500 text-xs">{{ count($leagueFixtures) }} utakmica</span>
            </div>
            <div class="border border-t-0 border-[#2a2a2a] rounded-b-xl overflow-hidden">
                @foreach($leagueFixtures as $i => $fixture)
                @php
                    $isLive = in_array($fixture['status_short'] ?? '', ['1H','2H','ET','BT','P','LIVE']);
                    $isHT   = ($fixture['status_short'] ?? '') === 'HT';
                    $isFT   = in_array($fixture['status_short'] ?? '', ['FT','AET','PEN']);
                    $hasScore = $isLive || $isHT || $isFT;
                @endphp
                <div onclick="window.location='/utakmica/{{ $fixture['id'] }}'"
                   class="flex items-center px-3 py-3 hover:bg-[#222] transition border-b border-[#2a2a2a] last:border-0 cursor-pointer {{ $i % 2 === 0 ? 'bg-[#0f0f0f]' : 'bg-[#161616]' }}">
                    <div class="w-12 text-center flex-shrink-0">
                        @if($isLive)
                            <span class="text-[#FF3B30] text-xs font-black">{{ $fixture['elapsed_minute'] ?? '' }}'</span>
                        @elseif($isHT)
                            <span class="text-yellow-400 text-xs font-bold">HT</span>
                        @elseif($isFT)
                            <span class="text-gray-500 text-xs font-medium">FT</span>
                        @else
                            <span class="text-gray-500 text-xs">{{ isset($fixture['kick_off']) ? \Carbon\Carbon::parse($fixture['kick_off'])->format('H:i') : '--:--' }}</span>
                        @endif
                    </div>
                    <div class="flex-1 flex items-center px-2">
                        <div class="flex-1 text-right pr-3">
                            <a href="/tim/{{ $fixture['home_team_slug'] }}" class="text-sm font-semibold {{ $isLive ? 'text-white' : 'text-gray-300' }} hover:text-[#CCFF00] transition" onclick="event.stopPropagation()">{{ $fixture['home_team_name'] }}</a>
                        </div>
                        <div class="flex items-center gap-1 min-w-[60px] justify-center">
                            @if($hasScore)
                                <span class="text-lg font-black {{ $isLive ? 'text-[#CCFF00]' : 'text-white' }}">{{ $fixture['score_home'] ?? '0' }}</span>
                                <span class="text-gray-500 text-sm">-</span>
                                <span class="text-lg font-black {{ $isLive ? 'text-[#CCFF00]' : 'text-white' }}">{{ $fixture['score_away'] ?? '0' }}</span>
                            @else
                                <span class="text-gray-500 text-sm font-bold">-</span>
                            @endif
                        </div>
                        <div class="flex-1 pl-3">
                            <a href="/tim/{{ $fixture['away_team_slug'] }}" class="text-sm font-semibold {{ $isLive ? 'text-white' : 'text-gray-300' }} hover:text-[#CCFF00] transition" onclick="event.stopPropagation()">{{ $fixture['away_team_name'] }}</a>
                        </div>
                    </div>
                    <div class="w-14 text-right flex-shrink-0">
                        @if($isLive)
                            <span class="inline-block bg-[#FF3B30] text-white text-[10px] font-bold px-1.5 py-0.5 rounded">UZIVO</span>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endforeach
    @endif
</div>
@endif
