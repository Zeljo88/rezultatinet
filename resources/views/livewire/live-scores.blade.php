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

        {{-- ══════════════════════════════════════════════════════════════
             DATE STRIP — ← Uto 18.3 | Sri 19.3 | Čet 20.3 →
        ══════════════════════════════════════════════════════════════ --}}
        <div class="flex items-center justify-center gap-2 py-3 border-b border-gray-800 mb-2">
            <button wire:click="setDate('{{ $prevDate }}')"
                    class="cursor-pointer text-gray-400 hover:text-white px-2 text-lg leading-none">←</button>

            @foreach([$prevDate, $selectedDate, $nextDate] as $d)
                @php
                    $label = \Carbon\Carbon::parse($d)->locale('hr')->isoFormat('ddd D.M');
                @endphp
                <button wire:click="setDate('{{ $d }}')"
                    class="cursor-pointer px-3 py-1 rounded text-sm transition
                           {{ $d === $selectedDate
                               ? 'bg-[#CCFF00] text-black font-bold'
                               : 'text-gray-400 hover:text-white' }}">
                    {{ $label }}
                </button>
            @endforeach

            <button wire:click="setDate('{{ $nextDate }}')"
                    class="cursor-pointer text-gray-400 hover:text-white px-2 text-lg leading-none">→</button>
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             FILTER PILLS — SVE | UŽIVO | ZAKAZANO | ZAVRŠENO
        ══════════════════════════════════════════════════════════════ --}}
        <div class="flex gap-2 flex-wrap px-2 py-2 mb-3">
            @foreach(['sve' => 'SVE', 'uzivo' => 'UŽIVO', 'zakazano' => 'ZAKAZANO', 'zavrseno' => 'ZAVRŠENO'] as $key => $label)
                @php
                    $countKey = match($key) {
                        'sve'      => 'all',
                        'uzivo'    => 'live',
                        'zakazano' => 'upcoming',
                        'zavrseno' => 'finished',
                    };
                    $count = $counts[$countKey] ?? 0;
                @endphp
                <button wire:click="setFilter('{{ $key }}')"
                    class="cursor-pointer px-3 py-1 text-xs rounded-full border transition
                           {{ $filter === $key
                               ? 'bg-[#CCFF00] text-black border-[#CCFF00] font-bold'
                               : 'border-gray-600 text-gray-400 hover:border-white hover:text-white' }}">
                    {{ $label }}
                    <span class="opacity-70">({{ $count }})</span>
                </button>
            @endforeach
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             MATCH LIST
        ══════════════════════════════════════════════════════════════ --}}
        @if(empty($fixtures))
            <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-12 text-center">
                <div class="text-5xl mb-3">&#9917;</div>
                <p class="text-gray-400 text-lg font-semibold">
                    @if($filter === 'uzivo') Trenutno nema živih utakmica.
                    @elseif($filter === 'zakazano') Nema zakazanih utakmica za ovaj datum.
                    @elseif($filter === 'zavrseno') Nema završenih utakmica za ovaj datum.
                    @else Nema utakmica za odabrani datum.
                    @endif
                </p>
                @if($filter !== 'sve')
                    <button wire:click="setFilter('sve')" class="mt-4 text-[#CCFF00] text-sm hover:underline font-medium cursor-pointer">Pogledaj sve utakmice &rarr;</button>
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
                        @php
                            $statusDisplay = match(true) {
                                in_array($fixture['status_short'] ?? '', ['FT', 'AET', 'PEN']) => 'FT',
                                ($fixture['status_short'] ?? '') === 'HT' => 'HT',
                                in_array($fixture['status_short'] ?? '', ['PST', 'CANC', 'ABD', 'SUSP', 'INT']) => 'OTK',
                                in_array($fixture['status_short'] ?? '', ['1H', '2H', 'ET', 'BT', 'P', 'LIVE']) => ($fixture['elapsed_minute'] ?? '?') . (!empty($fixture['elapsed_extra']) ? '+' . $fixture['elapsed_extra'] : '') . "'",
                                default => (isset($fixture['kick_off']) ? \Carbon\Carbon::parse($fixture['kick_off'])->format('H:i') : '--:--'),
                            };
                            $statusClass = match(true) {
                                in_array($fixture['status_short'] ?? '', ['1H', '2H', 'ET', 'BT', 'P', 'LIVE']) => 'text-[#FF3B30] font-black',
                                ($fixture['status_short'] ?? '') === 'HT' => 'text-yellow-400 font-bold',
                                in_array($fixture['status_short'] ?? '', ['FT', 'AET', 'PEN']) => 'text-gray-500 font-medium',
                                default => 'text-gray-500',
                            };
                        @endphp
                        <div class="w-12 text-center flex-shrink-0">
                            <span class="text-xs {{ $statusClass }}">{{ $statusDisplay }}</span>
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
</div>
