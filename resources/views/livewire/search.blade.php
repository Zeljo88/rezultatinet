<div>
    <h1 class="text-2xl font-black text-white mb-5">🔍 Pretraga</h1>

    {{-- Search input --}}
    <div class="relative mb-6">
        <input
            wire:model.live.debounce.300ms="q"
            type="text"
            placeholder="Pretraži timove ili lige..."
            autofocus
            class="w-full bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl px-4 py-3 pl-12 text-white placeholder-gray-500 focus:outline-none focus:border-[#CCFF00] transition text-sm"
        >
        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500">🔍</span>
        @if($q)
            <button wire:click="$set('q','')" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white">✕</button>
        @endif
    </div>

    @if(strlen($q) < 2 && empty($teams) && empty($leagues))
        <div class="text-center py-16 text-gray-500">
            <div class="text-5xl mb-4">⚽</div>
            <p>Upiši ime tima ili lige za pretragu...</p>
            <p class="text-sm mt-2 text-gray-600">Npr: "Dinamo", "Hajduk", "Premier League"</p>
        </div>
    @endif

    {{-- Teams results --}}
    @if(!empty($teams))
    <div class="mb-6">
        <h2 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 px-1">👕 Timovi</h2>
        <div class="border border-[#2a2a2a] rounded-xl overflow-hidden">
            @foreach($teams as $i => $team)
            <a href="/tim/{{ $team['slug'] }}"
               class="flex items-center gap-3 px-4 py-3 hover:bg-[#222] transition border-b border-[#2a2a2a] last:border-0 cursor-pointer {{ $i % 2 === 0 ? 'bg-[#0f0f0f]' : 'bg-[#161616]' }}">
                @if($team['logo'])
                    <img src="{{ $team['logo'] }}" class="w-7 h-7 object-contain flex-shrink-0" alt="" loading="lazy">
                @else
                    <div class="w-7 h-7 bg-[#2a2a2a] rounded-full flex-shrink-0"></div>
                @endif
                <span class="text-sm font-semibold text-white">{{ $team['name'] }}</span>
                <span class="ml-auto text-gray-600 text-xs">→</span>
            </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- League results --}}
    @if(!empty($leagues))
    <div class="mb-6">
        <h2 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 px-1">🏆 Lige</h2>
        <div class="border border-[#2a2a2a] rounded-xl overflow-hidden">
            @foreach($leagues as $i => $league)
            <div class="flex items-center gap-3 px-4 py-3 border-b border-[#2a2a2a] last:border-0 {{ $i % 2 === 0 ? 'bg-[#0f0f0f]' : 'bg-[#161616]' }}">
                @if($league['logo'])
                    <img src="{{ $league['logo'] }}" class="w-7 h-7 object-contain flex-shrink-0" alt="" loading="lazy">
                @else
                    <div class="w-7 h-7 bg-[#2a2a2a] rounded-full flex-shrink-0"></div>
                @endif
                <div>
                    <span class="text-sm font-semibold text-white">{{ $league['name'] }}</span>
                    @if($league['country'])
                        <span class="text-xs text-gray-500 ml-2">{{ $league['country'] }}</span>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    @if(strlen($q) >= 2 && empty($teams) && empty($leagues))
    <div class="text-center py-16 text-gray-500">
        <div class="text-5xl mb-4">🤷</div>
        <p>Nema rezultata za "<span class="text-white">{{ $q }}</span>"</p>
    </div>
    @endif
</div>
