<div>
    {{-- League header --}}
    <div class="flex items-center gap-3 mb-5">
        @if($league->logo_url)
            <img src="{{ $league->logo_url }}" class="w-10 h-10 object-contain" alt="{{ $league->name }}">
        @endif
        <div>
            <h1 class="text-2xl font-black text-white">{{ $league->name }}</h1>
            <p class="text-gray-500 text-sm">{{ $league->country }}</p>
        </div>
    </div>

    {{-- View toggle: Utakmice / Tablica --}}
    <div class="flex gap-2 mb-4">
        <button wire:click="setView('fixtures')" class="px-4 py-2 rounded-lg text-sm font-bold transition cursor-pointer {{ $view === 'fixtures' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">
            ⚽ Utakmice
        </button>
        <button wire:click="setView('standings')" class="px-4 py-2 rounded-lg text-sm font-bold transition cursor-pointer {{ $view === 'standings' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">
            📊 Tablica
        </button>
    </div>

    {{-- FIXTURES VIEW --}}
    @if($view === 'fixtures')
        <div class="flex gap-2 mb-4">
            <button wire:click="setTab('yesterday')" class="px-3 py-1.5 rounded-lg text-sm font-semibold transition cursor-pointer {{ $tab === 'yesterday' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">Jucer</button>
            <button wire:click="setTab('today')" class="px-3 py-1.5 rounded-lg text-sm font-semibold transition cursor-pointer {{ $tab === 'today' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">Danas</button>
            <button wire:click="setTab('tomorrow')" class="px-3 py-1.5 rounded-lg text-sm font-semibold transition cursor-pointer {{ $tab === 'tomorrow' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">Sutra</button>
            <button wire:click="setTab('recent')" class="px-3 py-1.5 rounded-lg text-sm font-semibold transition cursor-pointer {{ $tab === 'recent' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">Zadnjih 7 dana</button>
        </div>

        @if(empty($fixtures))
            <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-12 text-center">
                <div class="text-5xl mb-3">⚽</div>
                <p class="text-gray-400 text-lg font-semibold">Nema utakmica za ovaj period.</p>
            </div>
        @else
            <div class="border border-[#2a2a2a] rounded-xl overflow-hidden">
                @foreach($fixtures as $i => $fixture)
                @php
                    $isLive = $fixture['is_live'];
                    $isFT   = $fixture['is_ft'];
                    $hasScore = $isLive || $isFT;
                @endphp
                <a href="/utakmica/{{ $fixture['id'] }}"
                   class="flex items-center px-3 py-3 hover:bg-[#222] transition border-b border-[#2a2a2a] last:border-0 cursor-pointer {{ $i % 2 === 0 ? 'bg-[#0f0f0f]' : 'bg-[#161616]' }}">
                    <div class="w-12 text-center flex-shrink-0">
                        @if($isLive)
                            <span class="text-[#FF3B30] text-xs font-black">{{ $fixture['elapsed_minute'] ?? '' }}'</span>
                        @elseif(($fixture['status_short'] ?? '') === 'HT')
                            <span class="text-yellow-400 text-xs font-bold">HT</span>
                        @elseif($isFT)
                            <span class="text-gray-500 text-xs font-medium">FT</span>
                        @else
                            <span class="text-gray-500 text-xs">{{ isset($fixture['kick_off']) ? \Carbon\Carbon::parse($fixture['kick_off'])->format('H:i') : '--:--' }}</span>
                        @endif
                    </div>
                    <div class="flex-1 flex items-center px-2">
                        <div class="flex-1 flex items-center justify-end gap-2 pr-3">
                            @if($fixture['home_team_logo'])
                                <img src="{{ $fixture['home_team_logo'] }}" class="w-5 h-5 object-contain" alt="">
                            @endif
                            <span class="text-sm font-semibold {{ $isLive ? 'text-white' : 'text-gray-300' }}">{{ $fixture['home_team_name'] }}</span>
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
                        <div class="flex-1 flex items-center gap-2 pl-3">
                            @if($fixture['away_team_logo'])
                                <img src="{{ $fixture['away_team_logo'] }}" class="w-5 h-5 object-contain" alt="">
                            @endif
                            <span class="text-sm font-semibold {{ $isLive ? 'text-white' : 'text-gray-300' }}">{{ $fixture['away_team_name'] }}</span>
                        </div>
                    </div>
                    <div class="w-14 text-right flex-shrink-0">
                        @if($isLive)
                            <span class="inline-block bg-[#FF3B30] text-white text-[10px] font-bold px-1.5 py-0.5 rounded">UZIVO</span>
                        @endif
                    </div>
                </a>
                @endforeach
            </div>
        @endif
    @endif

    {{-- STANDINGS VIEW --}}
    @if($view === 'standings')
        @if(empty($standings))
            <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-12 text-center">
                <p class="text-gray-400 text-lg font-semibold">Tablica nije dostupna.</p>
            </div>
        @else
            <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl overflow-hidden">
                {{-- Header --}}
                <div class="grid grid-cols-12 px-3 py-2 text-xs font-bold text-gray-500 uppercase tracking-wider border-b border-[#2a2a2a]">
                    <div class="col-span-1 text-center">#</div>
                    <div class="col-span-5">Klub</div>
                    <div class="col-span-1 text-center">U</div>
                    <div class="col-span-1 text-center">P</div>
                    <div class="col-span-1 text-center">N</div>
                    <div class="col-span-1 text-center">I</div>
                    <div class="col-span-1 text-center">GR</div>
                    <div class="col-span-1 text-center font-black text-white">BOD</div>
                </div>
                @foreach($standings as $i => $row)
                @php
                    $desc = strtolower($row['description'] ?? '');
                    $rowColor = match(true) {
                        str_contains($desc, 'champions league') => 'border-l-2 border-blue-500',
                        str_contains($desc, 'europa league') => 'border-l-2 border-orange-500',
                        str_contains($desc, 'conference') => 'border-l-2 border-green-500',
                        str_contains($desc, 'relegation') => 'border-l-2 border-red-500',
                        default => ''
                    };
                @endphp
                <div class="grid grid-cols-12 px-3 py-2.5 {{ $rowColor }} {{ $i % 2 === 0 ? 'bg-[#0f0f0f]' : 'bg-[#161616]' }} border-b border-[#2a2a2a] last:border-0 items-center">
                    <div class="col-span-1 text-center text-sm text-gray-400 font-bold">{{ $row['rank'] }}</div>
                    <div class="col-span-5 flex items-center gap-2">
                        @if($row['team_logo'])
                            <img src="{{ $row['team_logo'] }}" class="w-5 h-5 object-contain flex-shrink-0" alt="">
                        @endif
                        <span class="text-sm font-semibold text-white truncate">{{ $row['team_name'] }}</span>
                    </div>
                    <div class="col-span-1 text-center text-sm text-gray-400">{{ $row['played'] }}</div>
                    <div class="col-span-1 text-center text-sm text-gray-400">{{ $row['win'] }}</div>
                    <div class="col-span-1 text-center text-sm text-gray-400">{{ $row['draw'] }}</div>
                    <div class="col-span-1 text-center text-sm text-gray-400">{{ $row['lose'] }}</div>
                    <div class="col-span-1 text-center text-sm text-gray-400">{{ $row['goal_diff'] > 0 ? '+' . $row['goal_diff'] : $row['goal_diff'] }}</div>
                    <div class="col-span-1 text-center text-sm font-black text-white">{{ $row['points'] }}</div>
                </div>
                @endforeach
            </div>
            {{-- Legend --}}
            <div class="flex flex-wrap gap-4 mt-3 text-xs text-gray-500">
                <span><span class="inline-block w-2 h-2 bg-blue-500 rounded-sm mr-1"></span>Champions League</span>
                <span><span class="inline-block w-2 h-2 bg-orange-500 rounded-sm mr-1"></span>Europa League</span>
                <span><span class="inline-block w-2 h-2 bg-green-500 rounded-sm mr-1"></span>Conference League</span>
                <span><span class="inline-block w-2 h-2 bg-red-500 rounded-sm mr-1"></span>Ispadanje</span>
            </div>
        @endif
    @endif
</div>
