<div class="max-w-4xl mx-auto px-4 py-6 space-y-6">

    {{-- BREADCRUMB NAV --}}
    <nav aria-label="breadcrumb" class="text-xs text-gray-500">
        <ol class="flex items-center gap-1.5 flex-wrap">
            <li><a href="/" class="hover:text-[#CCFF00] transition">Rezultati</a></li>
            <li class="text-gray-600">/</li>
            <li>
                <a href="/liga/{{ $slug }}" class="hover:text-[#CCFF00] transition">
                    @if($league->logo_url)
                        <img src="{{ $league->logo_url }}" alt="" class="inline w-4 h-4 object-contain mr-1 align-middle" loading="lazy">
                    @endif
                    {{ $league->name }}
                </a>
            </li>
            <li class="text-gray-600">/</li>
            <li class="text-white font-semibold">Strijelci</li>
        </ol>
    </nav>

    {{-- PAGE HEADER --}}
    <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-5">
        <div class="flex items-center gap-3 mb-3">
            @if($league->logo_url)
                <img src="{{ $league->logo_url }}" alt="{{ $league->name }}" class="w-10 h-10 object-contain" loading="lazy">
            @endif
            <div>
                <h1 class="text-xl font-black text-white">
                    {{ $leagueName }} Strijelci {{ $season }}
                </h1>
                <p class="text-sm text-gray-400 mt-1">Vodeći strijelci u {{ $leagueName }} za sezonu {{ $season }}.</p>
            </div>
        </div>
        {{-- Sub-page navigation --}}
        <div class="flex flex-wrap gap-2 mt-3">
            <a href="/liga/{{ $slug }}" class="px-3 py-1.5 rounded-full text-xs font-semibold bg-[#2a2a2a] text-gray-300 hover:text-white transition">
                ⚽ Rezultati
            </a>
            <a href="/liga/{{ $slug }}/tablica" class="px-3 py-1.5 rounded-full text-xs font-semibold bg-[#2a2a2a] text-gray-300 hover:text-white transition">
                📊 Tablica
            </a>
            <a href="/liga/{{ $slug }}/raspored" class="px-3 py-1.5 rounded-full text-xs font-semibold bg-[#2a2a2a] text-gray-300 hover:text-white transition">
                📅 Raspored
            </a>
            <span class="px-3 py-1.5 rounded-full text-xs font-semibold bg-[#CCFF00] text-black">
                🥅 Strijelci
            </span>
        </div>
    </div>

    {{-- SCORERS LIST --}}
    <section class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl overflow-hidden">
        <h2 class="text-base font-bold text-white px-4 py-3 border-b border-[#2a2a2a] bg-[#0f0f0f]">
            🥅 Lista strijelaca — {{ $leagueName }} {{ $season }}
        </h2>
        @if(empty($scorers))
            <div class="px-4 py-8 text-center text-gray-500">
                <p>Nema dostupnih podataka o strijelcima.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-gray-500 uppercase border-b border-[#2a2a2a] bg-[#0f0f0f]">
                            <th class="px-3 py-2 text-center w-10">#</th>
                            <th class="px-3 py-2 text-left">Igrač</th>
                            <th class="px-3 py-2 text-left hidden sm:table-cell">Klub</th>
                            <th class="px-3 py-2 text-left hidden md:table-cell">Nacion.</th>
                            <th class="px-3 py-2 text-center hidden sm:table-cell" title="Nastupa">Ut.</th>
                            <th class="px-3 py-2 text-center font-bold text-white" title="Golovi">Gol</th>
                            <th class="px-3 py-2 text-center" title="Asistencije">Asis</th>
                            <th class="px-3 py-2 text-center hidden sm:table-cell" title="Penali">11m</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($scorers as $i => $scorer)
                        <tr class="{{ $i % 2 === 0 ? 'bg-[#0f0f0f]' : 'bg-[#161616]' }} border-b border-[#2a2a2a] last:border-0">
                            <td class="px-3 py-2.5 text-center text-gray-500 font-bold">{{ $i + 1 }}</td>
                            <td class="px-3 py-2.5">
                                <div class="flex items-center gap-2">
                                    @if($scorer['player_photo'])
                                        <img src="{{ $scorer['player_photo'] }}" alt="{{ $scorer['player_name'] }}" class="w-6 h-6 rounded-full object-cover flex-shrink-0" loading="lazy">
                                    @endif
                                    @if($scorer['player_slug'])
                                        <a href="/igraci/{{ $scorer['player_slug'] }}" class="text-white hover:text-[#CCFF00] font-semibold transition truncate">
                                            {{ $scorer['player_name'] }}
                                        </a>
                                    @else
                                        <span class="text-white font-semibold truncate">{{ $scorer['player_name'] }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-3 py-2.5 text-gray-400 hidden sm:table-cell">{{ $scorer['club'] ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-gray-400 hidden md:table-cell text-xs">{{ $scorer['nationality'] ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-center text-gray-400 hidden sm:table-cell">{{ $scorer['appearances'] ?: '—' }}</td>
                            <td class="px-3 py-2.5 text-center font-black text-[#CCFF00] text-base">{{ $scorer['goals'] }}</td>
                            <td class="px-3 py-2.5 text-center text-gray-400">{{ $scorer['assists'] ?: '—' }}</td>
                            <td class="px-3 py-2.5 text-center text-gray-500 hidden sm:table-cell text-xs">{{ $scorer['penalties'] ?: '—' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- BACK LINK --}}
    <div class="text-center">
        <a href="/liga/{{ $slug }}" class="inline-flex items-center gap-2 text-[#CCFF00] hover:underline text-sm font-semibold">
            ← Natrag na {{ $leagueName }} rezultate
        </a>
    </div>

</div>
