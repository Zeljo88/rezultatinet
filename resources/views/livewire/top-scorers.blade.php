<div>
    <div class="flex items-center gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-black text-white">Strijelci <span class="text-[#CCFF00]">2024/25</span></h1>
            <p class="text-gray-500 text-sm">{{ $leagueName }} — top strijelci sezone</p>
        </div>
    </div>

    {{-- League selector --}}
    <div class="flex flex-wrap gap-2 mb-5">
        @foreach($availableLeagues as $id => $name)
        <button wire:click="setLeague({{ $id }})"
            class="px-3 py-1.5 rounded-full text-xs font-semibold transition cursor-pointer {{ $leagueApiId === $id ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">
            {{ $name }}
        </button>
        @endforeach
    </div>

    @if(empty($scorers))
        <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-12 text-center">
            <div class="text-5xl mb-3">⚽</div>
            <p class="text-gray-400 text-lg font-semibold">Nema podataka o strijelcima za ovu ligu.</p>
        </div>
    @else
        <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl overflow-hidden">
            {{-- Header --}}
            <div class="grid grid-cols-12 px-4 py-2 text-xs font-bold text-gray-500 uppercase tracking-wider border-b border-[#2a2a2a]">
                <div class="col-span-1 text-center">#</div>
                <div class="col-span-5">Igrač</div>
                <div class="col-span-3">Klub</div>
                <div class="col-span-1 text-center">⚽</div>
                <div class="col-span-1 text-center">🅰️</div>
                <div class="col-span-1 text-center">Ut.</div>
            </div>
            @foreach($scorers as $i => $scorer)
            <div class="grid grid-cols-12 px-4 py-3 items-center {{ $i % 2 === 0 ? 'bg-[#0f0f0f]' : 'bg-[#161616]' }} border-b border-[#2a2a2a] last:border-0">
                {{-- Rank --}}
                <div class="col-span-1 text-center">
                    @if($i === 0)
                        <span class="text-yellow-400 font-black text-sm">🥇</span>
                    @elseif($i === 1)
                        <span class="text-gray-400 font-black text-sm">🥈</span>
                    @elseif($i === 2)
                        <span class="text-orange-400 font-black text-sm">🥉</span>
                    @else
                        <span class="text-gray-500 text-sm">{{ $i + 1 }}</span>
                    @endif
                </div>
                {{-- Player --}}
                <div class="col-span-5 flex items-center gap-2">
                    @if($scorer['player_photo'])
                        <img src="{{ $scorer['player_photo'] }}" class="w-8 h-8 rounded-full object-cover bg-[#2a2a2a]" alt="" loading="lazy">
                    @else
                        <div class="w-8 h-8 rounded-full bg-[#2a2a2a] flex items-center justify-center text-gray-600 text-xs">?</div>
                    @endif
                    <div>
                        <span class="text-sm font-semibold text-white block leading-tight">{{ $scorer['player_name'] }}</span>
                        @if($scorer['nationality'])
                            <span class="text-xs text-gray-500">{{ $scorer['nationality'] }}</span>
                        @endif
                    </div>
                </div>
                {{-- Club --}}
                <div class="col-span-3 flex items-center gap-1.5">
                    @if($scorer['team_logo'])
                        <img src="{{ $scorer['team_logo'] }}" class="w-4 h-4 object-contain" alt="" loading="lazy">
                    @endif
                    <span class="text-xs text-gray-400 truncate">{{ $scorer['team_name'] }}</span>
                </div>
                {{-- Goals --}}
                <div class="col-span-1 text-center">
                    <span class="text-lg font-black {{ $i < 3 ? 'text-[#CCFF00]' : 'text-white' }}">{{ $scorer['goals'] }}</span>
                </div>
                {{-- Assists --}}
                <div class="col-span-1 text-center">
                    <span class="text-sm text-gray-400">{{ $scorer['assists'] }}</span>
                </div>
                {{-- Appearances --}}
                <div class="col-span-1 text-center">
                    <span class="text-sm text-gray-500">{{ $scorer['appearances'] }}</span>
                </div>
            </div>
            @endforeach
        </div>
    @endif
</div>
