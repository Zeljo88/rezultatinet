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

            {{-- Tab header --}}
            <div class="flex items-center gap-0 border-b border-[#2a2a2a] bg-[#0f0f0f] px-4 pt-3">
                <h2 class="text-base font-bold text-white mr-4 pb-3">📊 Tablica</h2>
                <button wire:click="switchTab('all')"
                    class="px-3 py-2 text-sm font-semibold rounded-t border-b-2 transition
                        {{ $tab === 'all' ? 'border-[#CCFF00] text-[#CCFF00]' : 'border-transparent text-gray-400 hover:text-white' }}">
                    Ukupno
                </button>
                <button wire:click="switchTab('home')"
                    class="px-3 py-2 text-sm font-semibold rounded-t border-b-2 transition
                        {{ $tab === 'home' ? 'border-[#CCFF00] text-[#CCFF00]' : 'border-transparent text-gray-400 hover:text-white' }}">
                    Domaći
                </button>
                <button wire:click="switchTab('away')"
                    class="px-3 py-2 text-sm font-semibold rounded-t border-b-2 transition
                        {{ $tab === 'away' ? 'border-[#CCFF00] text-[#CCFF00]' : 'border-transparent text-gray-400 hover:text-white' }}">
                    Gostujući
                </button>
                <span class="ml-auto text-xs text-gray-500 pb-3">{{ $league->name }} {{ $season }}</span>
            </div>

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
                            @if($tab === 'all')
                            <th class="px-2 py-2 text-center hidden md:table-cell" title="Forma (zadnjih 5)">Forma</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            // Sort by points desc for home/away tabs
                            $rows = $standings;
                            if ($tab === 'home') {
                                usort($rows, fn($a, $b) => $b['home_points'] <=> $a['home_points'] ?: ($b['home_goals_for'] - $b['home_goals_against']) <=> ($a['home_goals_for'] - $a['home_goals_against']));
                            } elseif ($tab === 'away') {
                                usort($rows, fn($a, $b) => $b['away_points'] <=> $a['away_points'] ?: ($b['away_goals_for'] - $b['away_goals_against']) <=> ($a['away_goals_for'] - $a['away_goals_against']));
                            }
                        @endphp
                        @foreach($rows as $i => $row)
                        @php
                            $desc = strtolower($row['description'] ?? '');
                            $borderColor = match(true) {
                                str_contains($desc, 'champions league') => 'border-l-2 border-blue-500',
                                str_contains($desc, 'europa league')    => 'border-l-2 border-orange-500',
                                str_contains($desc, 'conference')       => 'border-l-2 border-green-500',
                                str_contains($desc, 'relegation')       => 'border-l-2 border-red-500',
                                default                                  => ''
                            };
                            $formChars = str_split($row['form'] ?? '');
                            $nextMatch = $nextMatches[$row['team_id']] ?? null;
                            if ($nextMatch) {
                                $ko = $nextMatch['kick_off'];
                                $days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                                $dayAbbr = ['Ned','Pon','Uto','Sri','Čet','Pet','Sub'];
                                $dow = $dayAbbr[(int)$ko->format('w')];
                                $nextMatchText = 'vs ' . $nextMatch['opponent'] . ' • ' . $dow . ' ' . $ko->format('H:i');
                            } else {
                                $nextMatchText = null;
                            }

                            if ($tab === 'home') {
                                $p  = $row['home_played'];
                                $w  = $row['home_win'];
                                $d  = $row['home_draw'];
                                $l  = $row['home_lose'];
                                $gf = $row['home_goals_for'];
                                $ga = $row['home_goals_against'];
                                $gd = $row['home_goal_diff'];
                                $pts = $row['home_points'];
                            } elseif ($tab === 'away') {
                                $p  = $row['away_played'];
                                $w  = $row['away_win'];
                                $d  = $row['away_draw'];
                                $l  = $row['away_lose'];
                                $gf = $row['away_goals_for'];
                                $ga = $row['away_goals_against'];
                                $gd = $row['away_goal_diff'];
                                $pts = $row['away_points'];
                            } else {
                                $p  = $row['played'];
                                $w  = $row['win'];
                                $d  = $row['draw'];
                                $l  = $row['lose'];
                                $gf = $row['goals_for'];
                                $ga = $row['goals_against'];
                                $gd = $row['goal_diff'];
                                $pts = $row['points'];
                            }
                        @endphp
                        <tr class="{{ $borderColor }} {{ $i % 2 === 0 ? 'bg-[#0f0f0f]' : 'bg-[#161616]' }} border-b border-[#2a2a2a] last:border-0">
                            <td class="px-3 py-2.5 text-center text-gray-400 font-bold">{{ $i + 1 }}</td>
                            <td class="px-3 py-2.5">
                                <div class="flex items-center gap-2">
                                    @if($row['team_logo'])
                                        <img src="{{ $row['team_logo'] }}" alt="{{ $row['team_name'] }}" class="w-5 h-5 object-contain flex-shrink-0" loading="lazy">
                                    @endif
                                    <div class="min-w-0">
                                        <a href="/tim/{{ $row['team_slug'] }}" class="text-white hover:text-[#CCFF00] font-semibold transition truncate block">
                                            {{ $row['team_name'] }}
                                        </a>
                                        @if($nextMatchText)
                                            <span class="text-gray-500 text-[10px] leading-tight truncate block">{{ $nextMatchText }}</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-2 py-2.5 text-center text-gray-400">{{ $p }}</td>
                            <td class="px-2 py-2.5 text-center text-gray-400">{{ $w }}</td>
                            <td class="px-2 py-2.5 text-center text-gray-400">{{ $d }}</td>
                            <td class="px-2 py-2.5 text-center text-gray-400">{{ $l }}</td>
                            <td class="px-2 py-2.5 text-center text-gray-400 hidden sm:table-cell">{{ $gf }}</td>
                            <td class="px-2 py-2.5 text-center text-gray-400 hidden sm:table-cell">{{ $ga }}</td>
                            <td class="px-2 py-2.5 text-center text-gray-400">{{ $gd > 0 ? '+' . $gd : $gd }}</td>
                            <td class="px-2 py-2.5 text-center font-black text-white">{{ $pts }}</td>
                            @if($tab === 'all')
                            <td class="px-2 py-2.5 text-center hidden md:table-cell">
                                @if(!empty($formChars))
                                    <div class="flex gap-0.5 justify-center">
                                        @foreach(array_slice($formChars, -5) as $f)
                                            @php
                                                $fc = match($f) {
                                                    'W' => 'bg-green-600 text-white',
                                                    'D' => 'bg-gray-500 text-white',
                                                    'L' => 'bg-red-600 text-white',
                                                    default => 'bg-gray-700 text-gray-400'
                                                };
                                                $fl = match($f) { 'W' => 'P', 'D' => 'N', 'L' => 'I', default => $f };
                                            @endphp
                                            <span class="{{ $fc }} text-xs font-bold w-5 h-5 rounded flex items-center justify-center leading-none">{{ $fl }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            @endif
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
                @if($tab === 'all')
                <span class="ml-auto"><span class="inline-block w-4 h-4 bg-green-600 rounded text-white text-xs font-bold text-center leading-4 mr-1">P</span>Pobjeda
                <span class="inline-block w-4 h-4 bg-gray-500 rounded text-white text-xs font-bold text-center leading-4 mx-1">N</span>Neriješeno
                <span class="inline-block w-4 h-4 bg-red-600 rounded text-white text-xs font-bold text-center leading-4 mx-1">I</span>Poraz</span>
                @endif
            </div>
        </section>

        {{-- RECENT RESULTS + UPCOMING FIXTURES (2 col) --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

            {{-- RECENT RESULTS --}}
            @if(!empty($recentResults))
            <section class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl overflow-hidden">
                <h2 class="text-base font-bold text-white px-4 py-3 border-b border-[#2a2a2a] bg-[#0f0f0f]">
                    🕐 Zadnji rezultati
                </h2>
                <div class="divide-y divide-[#2a2a2a]">
                    @foreach($recentResults as $m)
                    <div class="px-4 py-2.5 flex items-center gap-2 text-sm hover:bg-[#161616] transition">
                        <span class="text-gray-500 text-xs w-10 flex-shrink-0">{{ $m['date'] }}</span>
                        <div class="flex-1 flex items-center justify-between gap-2 min-w-0">
                            <a href="/tim/{{ $m['home_slug'] }}" class="text-white hover:text-[#CCFF00] truncate text-right flex-1">{{ $m['home'] }}</a>
                            <a href="{{ $m['slug'] ? '/utakmica/' . $m['slug'] : '#' }}" class="font-black text-[#CCFF00] bg-[#1f1f1f] px-2 py-0.5 rounded text-xs flex-shrink-0 hover:bg-[#2a2a2a]">
                                {{ $m['home_score'] }}:{{ $m['away_score'] }}
                                @if(in_array($m['status'], ['AET','PEN']))
                                    <span class="text-gray-400 font-normal">({{ $m['status'] }})</span>
                                @endif
                            </a>
                            <a href="/tim/{{ $m['away_slug'] }}" class="text-white hover:text-[#CCFF00] truncate flex-1">{{ $m['away'] }}</a>
                        </div>
                    </div>
                    @endforeach
                </div>
                <div class="px-4 py-2 border-t border-[#2a2a2a] bg-[#0f0f0f]">
                    <a href="/liga/{{ $leagueSlug }}" class="text-[#CCFF00] text-xs hover:underline">Svi rezultati →</a>
                </div>
            </section>
            @endif

            {{-- UPCOMING FIXTURES --}}
            @if(!empty($upcomingFixtures))
            <section class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl overflow-hidden">
                <h2 class="text-base font-bold text-white px-4 py-3 border-b border-[#2a2a2a] bg-[#0f0f0f]">
                    📅 Naredne utakmice
                </h2>
                <div class="divide-y divide-[#2a2a2a]">
                    @foreach($upcomingFixtures as $m)
                    <div class="px-4 py-2.5 flex items-center gap-2 text-sm hover:bg-[#161616] transition">
                        <span class="text-gray-500 text-xs w-16 flex-shrink-0">{{ $m['kick_off'] }}</span>
                        <div class="flex-1 flex items-center justify-between gap-2 min-w-0">
                            <a href="/tim/{{ $m['home_slug'] }}" class="text-white hover:text-[#CCFF00] truncate text-right flex-1">{{ $m['home'] }}</a>
                            <a href="{{ $m['slug'] ? '/utakmica/' . $m['slug'] : '#' }}" class="font-bold text-gray-400 bg-[#1f1f1f] px-2 py-0.5 rounded text-xs flex-shrink-0 hover:bg-[#2a2a2a]">vs</a>
                            <a href="/tim/{{ $m['away_slug'] }}" class="text-white hover:text-[#CCFF00] truncate flex-1">{{ $m['away'] }}</a>
                        </div>
                    </div>
                    @endforeach
                </div>
                <div class="px-4 py-2 border-t border-[#2a2a2a] bg-[#0f0f0f]">
                    <a href="/liga/{{ $leagueSlug }}" class="text-[#CCFF00] text-xs hover:underline">Raspored →</a>
                </div>
            </section>
            @endif

        </div>

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

        {{-- SEO CONTENT BLOCK --}}
        <section class="bg-[#111] border border-[#2a2a2a] rounded-xl p-5 space-y-4">
            <h2 class="text-base font-bold text-white">O {{ $league->name }} tablici {{ $season }}</h2>
            <p class="text-gray-400 text-sm leading-relaxed">
                {{ $seoDescription }}
                Tablica se ažurira nakon svake odigrane utakmice.
                Pratite poredak, golove i statistike za sve klubove na rezultati.net.
            </p>

            {{-- Standings summary: leader + last --}}
            @if(count($standings) >= 2)
            @php
                $leader = $standings[0];
                $last   = end($standings);
            @endphp
            <p class="text-gray-400 text-sm leading-relaxed">
                Trenutno vodi
                <a href="/tim/{{ $leader['team_slug'] }}" class="text-white font-semibold hover:text-[#CCFF00]">{{ $leader['team_name'] }}</a>
                sa <strong class="text-white">{{ $leader['points'] }} bodova</strong>
                iz {{ $leader['played'] }} odigranih utakmica ({{ $leader['win'] }}P / {{ $leader['draw'] }}N / {{ $leader['lose'] }}I,
                golovi {{ $leader['goals_for'] }}:{{ $leader['goals_against'] }}).
                Na začelju tablice nalazi se
                <a href="/tim/{{ $last['team_slug'] }}" class="text-white font-semibold hover:text-[#CCFF00]">{{ $last['team_name'] }}</a>
                sa {{ $last['points'] }} bodova.
            </p>
            @endif

            <div class="flex gap-4 flex-wrap pt-1">
                <a href="/liga/{{ $leagueSlug }}" class="inline-flex items-center gap-1 text-[#CCFF00] hover:underline text-sm font-semibold">
                    ⚽ {{ $league->name }} rezultati uživo →
                </a>
                <a href="/strijelci" class="inline-flex items-center gap-1 text-gray-400 hover:text-white text-sm">
                    📊 Svi strijelci →
                </a>
            </div>
        </section>
    @endif

</div>
