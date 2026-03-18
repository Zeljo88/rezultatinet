<div>
    {{-- League header --}}
    <div class="mb-5">
        <h1 class="text-2xl font-black text-white">{{ $league->name }} <span class="text-[#CCFF00]">rezultati</span></h1>
        <p class="text-gray-500 text-sm mt-0.5">{{ $league->country }} &bull; Sezona {{ date('Y') }}</p>
    </div>

    {{-- Tabs --}}
    <div class="flex gap-2 mb-4">
        <button wire:click="setTab('yesterday')" class="px-4 py-2 rounded-lg text-sm font-bold transition cursor-pointer {{ $tab === 'yesterday' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">Jucer</button>
        <button wire:click="setTab('today')" class="px-4 py-2 rounded-lg text-sm font-bold transition cursor-pointer {{ $tab === 'today' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">Danas</button>
        <button wire:click="setTab('tomorrow')" class="px-4 py-2 rounded-lg text-sm font-bold transition cursor-pointer {{ $tab === 'tomorrow' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">Sutra</button>
        <button wire:click="setTab('recent')" class="px-4 py-2 rounded-lg text-sm font-bold transition cursor-pointer {{ $tab === 'recent' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">Zadnjih 7 dana</button>
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
                    <div class="flex-1 text-right pr-3">
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
                    <div class="flex-1 pl-3">
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
</div>
