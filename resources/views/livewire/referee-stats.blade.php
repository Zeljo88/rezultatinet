<div>
    @if(!$slug)
    {{-- List view --}}
    <div class="mb-6">
        <h1 class="text-2xl font-black text-white">Statistike <span class="text-[#CCFF00]">sudija</span></h1>
        <p class="text-gray-500 text-sm mt-1">Kartoni, utakmice i sudačke tendencije</p>
    </div>

    @if(empty($referees))
        <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-12 text-center">
            <div class="text-5xl mb-3">🏁</div>
            <p class="text-gray-400">Podaci o sudijama bit će dostupni nakon sljedeće sinhronizacije.</p>
        </div>
    @else
    <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl overflow-hidden">
        <div class="grid grid-cols-12 px-4 py-2 text-xs font-bold text-gray-500 uppercase tracking-wider border-b border-[#2a2a2a]">
            <div class="col-span-4">Sudija</div>
            <div class="col-span-2 text-center">Utakmice</div>
            <div class="col-span-2 text-center">🟨 Ukupno</div>
            <div class="col-span-2 text-center">🟨/utakm.</div>
            <div class="col-span-1 text-center">🟥</div>
            <div class="col-span-1 text-center">🟥/utakm.</div>
        </div>
        @foreach($referees as $i => $ref)
        <a href="/sudija/{{ $ref['slug'] }}"
           class="grid grid-cols-12 px-4 py-3 items-center hover:bg-[#222] transition border-b border-[#2a2a2a] last:border-0 cursor-pointer {{ $i % 2 === 0 ? 'bg-[#0f0f0f]' : 'bg-[#161616]' }}">
            <div class="col-span-4 text-sm font-semibold text-white">{{ $ref['name'] }}</div>
            <div class="col-span-2 text-center text-sm text-gray-400">{{ $ref['matches'] }}</div>
            <div class="col-span-2 text-center text-sm text-yellow-400 font-bold">{{ $ref['yellow_cards'] }}</div>
            <div class="col-span-2 text-center text-sm {{ $ref['yellows_per_match'] > 4 ? 'text-red-400' : ($ref['yellows_per_match'] > 3 ? 'text-yellow-400' : 'text-gray-400') }} font-bold">
                {{ $ref['yellows_per_match'] }}
            </div>
            <div class="col-span-1 text-center text-sm text-red-400 font-bold">{{ $ref['red_cards'] }}</div>
            <div class="col-span-1 text-center text-sm text-gray-500">{{ $ref['reds_per_match'] }}</div>
        </a>
        @endforeach
    </div>
    @endif

    @else
    {{-- Individual referee view --}}
    <a href="/sudija" class="inline-flex items-center gap-2 text-gray-400 hover:text-white text-sm mb-4 transition">
        &larr; Svi sudije
    </a>

    @if(!$referee)
        <div class="text-center py-16 text-gray-500">Sudija nije pronađen.</div>
    @else
    <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-6 mb-4">
        <h1 class="text-2xl font-black text-white mb-1">🏁 {{ $referee['name'] }}</h1>
        <p class="text-gray-500 text-sm mb-5">Statistike sudijenja</p>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-[#0f0f0f] rounded-xl p-4 text-center">
                <div class="text-3xl font-black text-white">{{ $referee['matches'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Utakmice</div>
            </div>
            <div class="bg-[#0f0f0f] rounded-xl p-4 text-center">
                <div class="text-3xl font-black text-yellow-400">{{ $referee['yellow_cards'] }}</div>
                <div class="text-xs text-gray-500 mt-1">🟨 Žutih kartona</div>
            </div>
            <div class="bg-[#0f0f0f] rounded-xl p-4 text-center">
                <div class="text-3xl font-black text-red-400">{{ $referee['red_cards'] }}</div>
                <div class="text-xs text-gray-500 mt-1">🟥 Crvenih kartona</div>
            </div>
            <div class="bg-[#0f0f0f] rounded-xl p-4 text-center">
                <div class="text-3xl font-black {{ $referee['yellows_per_match'] > 4 ? 'text-red-400' : 'text-[#CCFF00]' }}">{{ $referee['yellows_per_match'] }}</div>
                <div class="text-xs text-gray-500 mt-1">🟨 po utakmici</div>
            </div>
        </div>
    </div>

    @if(!empty($recentMatches))
    <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl overflow-hidden">
        <div class="px-4 py-3 border-b border-[#2a2a2a]">
            <h2 class="text-sm font-bold text-gray-400 uppercase tracking-wider">Posljednje utakmice</h2>
        </div>
        @foreach($recentMatches as $i => $match)
        <a href="/utakmica/{{ $match['id'] }}"
           class="flex items-center gap-3 px-4 py-3 hover:bg-[#222] transition border-b border-[#2a2a2a] last:border-0 cursor-pointer {{ $i % 2 === 0 ? 'bg-[#0f0f0f]' : 'bg-[#161616]' }}">
            <div class="w-20 flex-shrink-0 text-xs text-gray-500">{{ \Carbon\Carbon::parse($match['kick_off'])->format('d.m.Y') }}</div>
            <div class="flex-1 flex items-center gap-2 justify-end">
                <span class="text-sm text-gray-300">{{ $match['home_team_name'] }}</span>
            </div>
            <div class="min-w-[60px] text-center">
                <span class="text-sm font-black text-white">{{ $match['score_home'] ?? '?' }} - {{ $match['score_away'] ?? '?' }}</span>
            </div>
            <div class="flex-1">
                <span class="text-sm text-gray-300">{{ $match['away_team_name'] }}</span>
            </div>
            <div class="flex gap-2 flex-shrink-0 text-xs">
                @if($match['yellow_cards']) <span class="text-yellow-400 font-bold">🟨{{ $match['yellow_cards'] }}</span> @endif
                @if($match['red_cards']) <span class="text-red-400 font-bold">🟥{{ $match['red_cards'] }}</span> @endif
            </div>
        </a>
        @endforeach
    </div>
    @endif
    @endif
    @endif
</div>
