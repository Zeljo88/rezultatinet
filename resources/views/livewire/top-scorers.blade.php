<div>
    <div class="flex items-center gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-black text-white">Strijelci <span class="text-[#CCFF00]">uživo</span></h1>
            <p class="text-gray-500 text-sm">{{ $leagueName }} — sezona 2024/25</p>
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
                <div class="col-span-6">Igrač</div>
                <div class="col-span-3">Klub</div>
                <div class="col-span-2 text-center">⚽ Gol</div>
            </div>
            @foreach($scorers as $i => $scorer)
            <div class="grid grid-cols-12 px-4 py-3 items-center {{ $i % 2 === 0 ? 'bg-[#0f0f0f]' : 'bg-[#161616]' }} border-b border-[#2a2a2a] last:border-0">
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
                <div class="col-span-6">
                    <span class="text-sm font-semibold text-white">{{ $scorer['player_name'] }}</span>
                </div>
                <div class="col-span-3 flex items-center gap-1.5">
                    @if($scorer['team_logo'])
                        <img src="{{ $scorer['team_logo'] }}" class="w-4 h-4 object-contain" alt="">
                    @endif
                    <span class="text-xs text-gray-400 truncate">{{ $scorer['team_name'] }}</span>
                </div>
                <div class="col-span-2 text-center">
                    <span class="text-lg font-black {{ $i < 3 ? 'text-[#CCFF00]' : 'text-white' }}">{{ $scorer['goals'] }}</span>
                </div>
            </div>
            @endforeach
        </div>
    @endif
</div>
