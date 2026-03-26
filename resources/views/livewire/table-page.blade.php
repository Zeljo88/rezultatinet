<div class="max-w-4xl mx-auto px-4 py-6 space-y-6">

    {{-- PAGE HEADER --}}
    <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-5">
        <div class="flex items-center gap-3 mb-3">
            @if($league->logo_url)
                <img src="{{ $league->logo_url }}" alt="{{ $league->name }}" class="w-10 h-10 object-contain" loading="lazy">
            @endif
            <div>
                <h1 class="text-xl font-black text-white">
                    {{ $league->name }} Tablica {{ $season }}
                    @if($displayName !== $league->name)
                        <span class="text-gray-400 font-normal text-base"> — {{ $displayName }}</span>
                    @endif
                </h1>
                <p class="text-sm text-gray-400 mt-1">{{ $seoDescription }}</p>
            </div>
        </div>
        <a href="/liga/{{ $leagueSlug }}" class="inline-flex items-center gap-1 text-[#CCFF00] hover:underline text-sm font-semibold">
            ⚽ Pratite {{ $league->name }} rezultate uživo →
        </a>
    </div>

    {{-- STANDINGS TABLE --}}
    @if(empty($standings))
        <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-12 text-center">
            <p class="text-gray-400 text-lg font-semibold">Tablica nije dostupna.</p>
        </div>
    @else
        <section class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl overflow-hidden">
            <h2 class="text-base font-bold text-white px-4 py-3 border-b border-[#2a2a2a] bg-[#0f0f0f]">
                📊 Tablica — {{ $league->name }} {{ $season }}
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

        {{-- TOP SCORERS --}}
        @if(!empty($topScorers))
        <section class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl overflow-hidden">
            <h2 class="text-base font-bold text-white px-4 py-3 border-b border-[#2a2a2a] bg-[#0f0f0f]">
                ⚽ Strijelci — {{ $league->name }} {{ $season }}
            </h2>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-gray-500 uppercase border-b border-[#2a2a2a] bg-[#0f0f0f]">
                        <th class="px-4 py-2 text-left w-8">#</th>
                        <th class="px-4 py-2 text-left">Igrač</th>
                        <th class="px-4 py-2 text-left hidden sm:table-cell">Klub</th>
                        <th class="px-4 py-2 text-center">Gol</th>
                        <th class="px-4 py-2 text-center hidden sm:table-cell">Asis</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($topScorers as $i => $scorer)
                    <tr class="{{ $i % 2 === 0 ? 'bg-[#0f0f0f]' : 'bg-[#161616]' }} border-b border-[#2a2a2a] last:border-0">
                        <td class="px-4 py-2.5 text-gray-500 font-bold">{{ $i + 1 }}</td>
                        <td class="px-4 py-2.5">
                            @if($scorer['player_slug'])
                                <a href="/igraci/{{ $scorer['player_slug'] }}" class="text-white hover:text-[#CCFF00] font-semibold transition">
                                    {{ $scorer['player_name'] }}
                                </a>
                            @else
                                <span class="text-white font-semibold">{{ $scorer['player_name'] }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-gray-400 hidden sm:table-cell">{{ $scorer['club'] ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center font-black text-[#CCFF00]">{{ $scorer['goals'] }}</td>
                        <td class="px-4 py-2.5 text-center text-gray-400 hidden sm:table-cell">{{ $scorer['assists'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        @endif

        {{-- SEO DESCRIPTION BLOCK --}}
        <section class="bg-[#111] border border-[#2a2a2a] rounded-xl p-5">
            <h2 class="text-base font-bold text-white mb-3">O {{ $league->name }} tablici {{ $season }}</h2>
            <p class="text-gray-400 text-sm leading-relaxed">
                {{ $seoDescription }}
                Tablica se ažurira u stvarnom vremenu nakon svake odigrane utakmice.
                Kliknite na ime kluba za detalje, historiju nastupa i statistike.
            </p>
            <div class="mt-4 flex gap-4 flex-wrap">
                <a href="/liga/{{ $leagueSlug }}" class="inline-flex items-center gap-1 text-[#CCFF00] hover:underline text-sm font-semibold">
                    ⚽ Pratite {{ $league->name }} rezultate uživo →
                </a>
                <a href="/strijelci" class="inline-flex items-center gap-1 text-gray-400 hover:text-white text-sm">
                    📊 Svi strijelci →
                </a>
            </div>
        </section>
    @endif

</div>
