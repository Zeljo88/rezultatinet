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
            <li class="text-white font-semibold">Tablica</li>
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
                    {{ $leagueName }} Tablica {{ $season }}
                </h1>
                <p class="text-sm text-gray-400 mt-1">Bodovni poredak svih timova u {{ $leagueName }} za sezonu {{ $season }}.</p>
            </div>
        </div>
        {{-- Sub-page navigation --}}
        <div class="flex flex-wrap gap-2 mt-3">
            <a href="/liga/{{ $slug }}" class="px-3 py-1.5 rounded-full text-xs font-semibold bg-[#2a2a2a] text-gray-300 hover:text-white transition">
                ⚽ Rezultati
            </a>
            <span class="px-3 py-1.5 rounded-full text-xs font-semibold bg-[#CCFF00] text-black">
                📊 Tablica
            </span>
            <a href="/liga/{{ $slug }}/raspored" class="px-3 py-1.5 rounded-full text-xs font-semibold bg-[#2a2a2a] text-gray-300 hover:text-white transition">
                📅 Raspored
            </a>
            <a href="/liga/{{ $slug }}/strijelci" class="px-3 py-1.5 rounded-full text-xs font-semibold bg-[#2a2a2a] text-gray-300 hover:text-white transition">
                🥅 Strijelci
            </a>
        </div>
    </div>

    {{-- STANDINGS TABLE --}}
    @if(empty($standings))
        <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-12 text-center">
            <p class="text-gray-400 text-lg font-semibold">Tablica nije dostupna.</p>
        </div>
    @else
        <section class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl overflow-hidden">
            <h2 class="text-base font-bold text-white px-4 py-3 border-b border-[#2a2a2a] bg-[#0f0f0f]">
                📊 Tablica — {{ $leagueName }} {{ $season }}
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-gray-500 uppercase border-b border-[#2a2a2a] bg-[#0f0f0f]">
                            <th class="px-3 py-2 text-center w-8">#</th>
                            <th class="px-3 py-2 text-left">Klub</th>
                            <th class="px-2 py-2 text-center" title="Odigrane utakmice">U</th>
                            <th class="px-2 py-2 text-center" title="Pobjede">P</th>
                            <th class="px-2 py-2 text-center" title="Neriješeno">N</th>
                            <th class="px-2 py-2 text-center" title="Izgubljeno">I</th>
                            <th class="px-2 py-2 text-center hidden sm:table-cell" title="Golovi za">G+</th>
                            <th class="px-2 py-2 text-center hidden sm:table-cell" title="Golovi protiv">G-</th>
                            <th class="px-2 py-2 text-center" title="Razlika golova">GR</th>
                            <th class="px-2 py-2 text-center font-bold text-white" title="Bodovi">BOD</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($standings as $i => $row)
                        @php
                            $desc = strtolower($row['description'] ?? '');
                            $borderColor = match(true) {
                                str_contains($desc, 'champions league') => 'border-l-2 border-blue-500',
                                str_contains($desc, 'europa league')    => 'border-l-2 border-orange-500',
                                str_contains($desc, 'conference')       => 'border-l-2 border-green-500',
                                str_contains($desc, 'relegation')       => 'border-l-2 border-red-500',
                                default                                  => ''
                            };
                        @endphp
                        <tr class="{{ $borderColor }} {{ $i % 2 === 0 ? 'bg-[#0f0f0f]' : 'bg-[#161616]' }} border-b border-[#2a2a2a] last:border-0">
                            <td class="px-3 py-2.5 text-center text-gray-400 font-bold">{{ $row['rank'] }}</td>
                            <td class="px-3 py-2.5">
                                <div class="flex items-center gap-2">
                                    @if($row['team_logo'])
                                        <img src="{{ $row['team_logo'] }}" alt="{{ $row['team_name'] }}" class="w-5 h-5 object-contain flex-shrink-0" loading="lazy">
                                    @endif
                                    <a href="/tim/{{ $row['team_slug'] }}" class="text-white hover:text-[#CCFF00] font-semibold transition truncate">
                                        {{ $row['team_name'] }}
                                    </a>
                                </div>
                            </td>
                            <td class="px-2 py-2.5 text-center text-gray-400">{{ $row['played'] }}</td>
                            <td class="px-2 py-2.5 text-center text-gray-400">{{ $row['win'] }}</td>
                            <td class="px-2 py-2.5 text-center text-gray-400">{{ $row['draw'] }}</td>
                            <td class="px-2 py-2.5 text-center text-gray-400">{{ $row['lose'] }}</td>
                            <td class="px-2 py-2.5 text-center text-gray-400 hidden sm:table-cell">{{ $row['goals_for'] }}</td>
                            <td class="px-2 py-2.5 text-center text-gray-400 hidden sm:table-cell">{{ $row['goals_against'] }}</td>
                            <td class="px-2 py-2.5 text-center text-gray-400">{{ $row['goal_diff'] > 0 ? '+' . $row['goal_diff'] : $row['goal_diff'] }}</td>
                            <td class="px-2 py-2.5 text-center font-black text-white">{{ $row['points'] }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{-- Legend --}}
            <div class="flex flex-wrap gap-4 px-4 py-3 text-xs text-gray-500 border-t border-[#2a2a2a] bg-[#0f0f0f]">
                <span><span class="inline-block w-2 h-2 bg-blue-500 rounded-sm mr-1"></span>Champions League</span>
                <span><span class="inline-block w-2 h-2 bg-orange-500 rounded-sm mr-1"></span>Europa League</span>
                <span><span class="inline-block w-2 h-2 bg-green-500 rounded-sm mr-1"></span>Conference League</span>
                <span><span class="inline-block w-2 h-2 bg-red-500 rounded-sm mr-1"></span>Ispadanje</span>
            </div>
        </section>
    @endif

    {{-- BACK LINK --}}
    <div class="text-center">
        <a href="/liga/{{ $slug }}" class="inline-flex items-center gap-2 text-[#CCFF00] hover:underline text-sm font-semibold">
            ← Natrag na {{ $leagueName }} rezultate
        </a>
    </div>

</div>
