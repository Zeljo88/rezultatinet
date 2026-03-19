<div>
@php
    $liveStatuses = ['1H','2H','ET','BT','P','LIVE'];
    $isLive = in_array($fixture->status_short, $liveStatuses);
    $isHT   = $fixture->status_short === 'HT';
    $isFT   = in_array($fixture->status_short, ['FT','AET','PEN']);
    $hasScore = $isLive || $isHT || $isFT;
    $score = $fixture->score;
    if ($isLive || $isHT) {
        $scoreHome = $score ? ($score->goals_home !== null ? $score->goals_home : ($score->home_fulltime !== null ? $score->home_fulltime : 0)) : 0;
        $scoreAway = $score ? ($score->goals_away !== null ? $score->goals_away : ($score->away_fulltime !== null ? $score->away_fulltime : 0)) : 0;
    } elseif ($isFT) {
        $scoreHome = $score ? ($score->home_fulltime !== null ? $score->home_fulltime : ($score->goals_home !== null ? $score->goals_home : 0)) : 0;
        $scoreAway = $score ? ($score->away_fulltime !== null ? $score->away_fulltime : ($score->goals_away !== null ? $score->goals_away : 0)) : 0;
    } else {
        $scoreHome = 0;
        $scoreAway = 0;
    }

    // SportsEvent JSON-LD Schema
    $schemaData = [
        '@context'   => 'https://schema.org',
        '@type'      => 'SportsEvent',
        'name'       => $fixture->homeTeam->name . ' vs ' . $fixture->awayTeam->name,
        'startDate'  => \Carbon\Carbon::parse($fixture->kick_off)->toIso8601String(),
        'location'   => [
            '@type' => 'Place',
            'name'  => $fixture->venue_name ?? 'Unknown Venue',
        ],
        'competitor' => [
            ['@type' => 'SportsTeam', 'name' => $fixture->homeTeam->name],
            ['@type' => 'SportsTeam', 'name' => $fixture->awayTeam->name],
        ],
        'sport' => 'Football',
        'url'   => url()->current(),
    ];
    if ($fixture->score) {
        $schemaData['homeTeam'] = ['@type' => 'SportsTeam', 'name' => $fixture->homeTeam->name];
        $schemaData['awayTeam'] = ['@type' => 'SportsTeam', 'name' => $fixture->awayTeam->name];
        if ($isFT) {
            $schemaData['name'] .= ' ' . $scoreHome . '-' . $scoreAway;
        }
    }
@endphp

    {{-- SportsEvent JSON-LD — injected as first child, stays inside root div --}}
    <script type="application/ld+json">
    {!! json_encode($schemaData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) !!}
    </script>

    {{-- Back --}}
    <a href="/" class="inline-flex items-center gap-2 text-gray-400 hover:text-white text-sm mb-4 transition cursor-pointer">
        &larr; Nazad
    </a>

    {{-- Match header --}}
    <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-6 mb-4">
        <div class="text-center text-xs text-gray-500 mb-4">
            {{ $fixture->league->name }} &bull; {{ $fixture->round }}
        </div>
        <div class="flex items-center justify-between gap-4">
            <div class="flex-1 text-center">
                @if($fixture->homeTeam->logo_url)
                    <img src="{{ $fixture->homeTeam->logo_url }}" class="w-16 h-16 mx-auto mb-2 object-contain" alt="{{ $fixture->homeTeam->name }}">
                @endif
                <a href="/tim/{{ $fixture->homeTeam->slug }}" class="font-bold text-white text-lg hover:text-[#CCFF00] transition">{{ $fixture->homeTeam->name }}</a>
            </div>
            <div class="text-center min-w-[120px]">
                @if($hasScore)
                    <div class="text-5xl font-black {{ $isLive ? 'text-[#CCFF00]' : 'text-white' }}">
                        <span id="score-home">{{ $scoreHome }}</span><span class="text-gray-500 text-3xl mx-1">-</span><span id="score-away">{{ $scoreAway }}</span>
                    </div>
                    @if($score && $score->home_halftime !== null && !in_array($fixture->status_short, ["1H", "NS"]))
                        <div class="text-xs text-gray-500 mt-1">Poluvrijeme: {{ $score->home_halftime }} - {{ $score->away_halftime }}</div>
                    @endif
                @else
                    <div class="text-3xl font-black text-gray-500">{{ \Carbon\Carbon::parse($fixture->kick_off)->format('H:i') }}</div>
                @endif
                <div class="mt-2">
                    @if($isLive)
                        <span class="inline-flex items-center gap-1 bg-[#FF3B30] text-white text-xs font-bold px-2 py-1 rounded">
                            <span class="w-1.5 h-1.5 bg-white rounded-full animate-pulse"></span>{{ $fixture->elapsed_minute }}{{ $fixture->elapsed_extra ? '+' . $fixture->elapsed_extra : '' }}'
                        </span>
                    @elseif($isHT)
                        <span class="text-yellow-400 text-sm font-bold">Poluvrijeme</span>
                    @elseif($isFT)
                        <span class="text-gray-400 text-sm">Kraj utakmice</span>
                    @else
                        <span class="text-gray-500 text-xs">{{ \Carbon\Carbon::parse($fixture->kick_off)->format('d.m.Y') }}</span>
                    @endif
                </div>
            </div>
            <div class="flex-1 text-center">
                @if($fixture->awayTeam->logo_url)
                    <img src="{{ $fixture->awayTeam->logo_url }}" class="w-16 h-16 mx-auto mb-2 object-contain" alt="{{ $fixture->awayTeam->name }}">
                @endif
                <a href="/tim/{{ $fixture->awayTeam->slug }}" class="font-bold text-white text-lg hover:text-[#CCFF00] transition">{{ $fixture->awayTeam->name }}</a>
            </div>
        </div>
    </div>

    {{-- Share buttons --}}
    <div class="flex items-center justify-center gap-3 mt-4 mb-2">
        @php
            $matchTitle = $fixture->homeTeam->name . ' ' . ($hasScore ? $scoreHome . ' - ' . $scoreAway : 'vs') . ' ' . $fixture->awayTeam->name;
            $matchUrl = urlencode('https://rezultati.net/utakmica/' . $fixture->id);
            $matchText = urlencode($matchTitle . ' | rezultati.net');
        @endphp
        <a href="https://wa.me/?text={{ $matchText }}%20{{ $matchUrl }}" target="_blank"
           class="flex items-center gap-2 px-4 py-2 bg-[#25D366] text-white text-sm font-bold rounded-lg hover:opacity-90 transition">
            📱 WhatsApp
        </a>
        <a href="https://twitter.com/intent/tweet?text={{ $matchText }}&url={{ $matchUrl }}" target="_blank"
           class="flex items-center gap-2 px-4 py-2 bg-[#1DA1F2] text-white text-sm font-bold rounded-lg hover:opacity-90 transition">
            🐦 Twitter
        </a>
        <button onclick="navigator.clipboard.writeText('https://rezultati.net/utakmica/{{ $fixture->id }}').then(() => alert('Link kopiran!'))"
           class="flex items-center gap-2 px-4 py-2 bg-[#2a2a2a] text-gray-300 text-sm font-bold rounded-lg hover:bg-[#333] transition">
            🔗 Kopiraj link
        </button>
    </div>

    {{-- Tabs: Dogadjaji / Sastavi / H2H --}}
    <div class="flex gap-2 mb-4 flex-wrap">
        <button wire:click="setTab('events')" class="px-4 py-2 rounded-lg text-sm font-bold transition cursor-pointer {{ $activeTab === 'events' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">
            📋 Dogadjaji
        </button>
        @if($homeLineup || $awayLineup)
        <button wire:click="setTab('lineups')" class="px-4 py-2 rounded-lg text-sm font-bold transition cursor-pointer {{ $activeTab === 'lineups' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">
            👥 Sastavi
        </button>
        @endif
        <button wire:click="setTab('h2h')" class="px-4 py-2 rounded-lg text-sm font-bold transition cursor-pointer {{ $activeTab === 'h2h' ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">
            ⚔️ H2H
        </button>
    </div>

    {{-- EVENTS TAB --}}
    @if($activeTab === 'events')
        @if($fixture->events->count() > 0)
        <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-4 mb-4">
            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-3">Dogadjaji</h3>
            @foreach($fixture->events->sortBy('elapsed_minute') as $event)
            @php
                $isHome = isset($event->team_id) && $event->team_id === $fixture->home_team_id;
                $icon = match(true) {
                    $event->type === 'Goal' && $event->detail === 'Own Goal' => '⚽️',
                    $event->type === 'Goal' && $event->detail === 'Penalty' => '🅿️',
                    $event->type === 'Goal' && $event->detail === 'Missed Penalty' => '❌',
                    $event->type === 'Goal' => '⚽',
                    $event->type === 'Card' && $event->detail === 'Yellow Card' => '🟨',
                    $event->type === 'Card' => '🟥',
                    $event->type === 'subst' => '🔄',
                    $event->type === 'Var' => '📺',
                    default => '📋'
                };
                $label = match(true) {
                    $event->type === 'Goal' && $event->detail === 'Own Goal' => 'Autogol',
                    $event->type === 'Goal' && $event->detail === 'Penalty' => 'Penal (Gol)',
                    $event->type === 'Goal' && $event->detail === 'Missed Penalty' => 'Promašen penal',
                    $event->type === 'Goal' => 'Gol',
                    $event->type === 'Card' && $event->detail === 'Yellow Card' => 'Žuti karton',
                    $event->type === 'Card' && $event->detail === 'Red Card' => 'Crveni karton',
                    $event->type === 'Card' => 'Karton',
                    $event->type === 'subst' => 'Zamjena',
                    $event->type === 'Var' => 'VAR',
                    default => $event->type
                };
            @endphp
            <div class="flex items-center gap-3 py-2 border-b border-[#2a2a2a] last:border-0">
                <span class="text-xs text-gray-500 w-8 text-right">{{ $event->elapsed_minute }}{{ $event->elapsed_extra ? '+' . $event->elapsed_extra : '' }}'</span>
                <span class="text-sm">{{ $icon }}</span>
                @if($isHome)
                    <div class="flex flex-col flex-1">
                        <span class="text-sm text-white">{{ $event->player_name }}</span>
                        <span class="text-xs text-gray-400">{{ $label }}</span>
                    </div>
                    <span class="flex-1"></span>
                @else
                    <span class="flex-1"></span>
                    <div class="flex flex-col flex-1 text-right">
                        <span class="text-sm text-white">{{ $event->player_name }}</span>
                        <span class="text-xs text-gray-400">{{ $label }}</span>
                    </div>
                @endif
            </div>
            @endforeach
        </div>
        @else
        <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-8 text-center mb-4">
            <p class="text-gray-500 text-sm">Nema dostupnih dogadjaja.</p>
        </div>
        @endif
    @endif

    {{-- SASTAVI (LINEUPS) TAB --}}
    @if($activeTab === 'lineups')
    <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-4 mb-4">
        <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-4">Postave ekipa</h3>

        @if($homeLineup || $awayLineup)
        {{-- Formations row --}}
        @if($homeLineup?->formation || $awayLineup?->formation)
        <div class="flex justify-between items-center mb-4 px-2">
            <span class="text-sm font-bold text-[#CCFF00]">{{ $homeLineup?->formation ?? '—' }}</span>
            <span class="text-xs text-gray-500">Formacija</span>
            <span class="text-sm font-bold text-[#CCFF00]">{{ $awayLineup?->formation ?? '—' }}</span>
        </div>
        @endif

        {{-- Starting XI --}}
        <div class="grid grid-cols-2 gap-4 mb-6">
            {{-- Home team --}}
            <div>
                <p class="text-xs text-[#CCFF00] font-bold mb-3 truncate">{{ $fixture->homeTeam->name }}</p>
                @if($homeLineup)
                    @foreach($homeLineup->startxi ?? [] as $player)
                    <div class="flex items-center gap-2 py-1.5 border-b border-[#2a2a2a]">
                        <span class="text-xs text-gray-500 w-5 text-right flex-shrink-0">{{ $player['number'] ?? '' }}</span>
                        <span class="text-sm text-white truncate flex-1">{{ $player['name'] ?? '' }}</span>
                        <span class="text-xs text-gray-600 flex-shrink-0">{{ $player['pos'] ?? '' }}</span>
                    </div>
                    @endforeach
                    @if($homeLineup->coach_name)
                        <div class="flex items-center gap-2 pt-2 mt-1">
                            <span class="text-xs text-gray-600">🧑‍💼</span>
                            <span class="text-xs text-gray-500 truncate">{{ $homeLineup->coach_name }}</span>
                        </div>
                    @endif
                @else
                    <p class="text-xs text-gray-600 italic">Nema podataka</p>
                @endif
            </div>

            {{-- Away team --}}
            <div>
                <p class="text-xs text-[#CCFF00] font-bold mb-3 truncate">{{ $fixture->awayTeam->name }}</p>
                @if($awayLineup)
                    @foreach($awayLineup->startxi ?? [] as $player)
                    <div class="flex items-center gap-2 py-1.5 border-b border-[#2a2a2a]">
                        <span class="text-xs text-gray-500 w-5 text-right flex-shrink-0">{{ $player['number'] ?? '' }}</span>
                        <span class="text-sm text-white truncate flex-1">{{ $player['name'] ?? '' }}</span>
                        <span class="text-xs text-gray-600 flex-shrink-0">{{ $player['pos'] ?? '' }}</span>
                    </div>
                    @endforeach
                    @if($awayLineup->coach_name)
                        <div class="flex items-center gap-2 pt-2 mt-1">
                            <span class="text-xs text-gray-600">🧑‍💼</span>
                            <span class="text-xs text-gray-500 truncate">{{ $awayLineup->coach_name }}</span>
                        </div>
                    @endif
                @else
                    <p class="text-xs text-gray-600 italic">Nema podataka</p>
                @endif
            </div>
        </div>

        {{-- Substitutes --}}
        @php
            $homeSubs = $homeLineup?->substitutes ?? [];
            $awaySubs = $awayLineup?->substitutes ?? [];
            $hasSubs  = count($homeSubs) > 0 || count($awaySubs) > 0;
        @endphp
        @if($hasSubs)
        <div class="border-t border-[#2a2a2a] pt-4">
            <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Rezerve</h4>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    @foreach($homeSubs as $player)
                    <div class="flex items-center gap-2 py-1 border-b border-[#222]">
                        <span class="text-xs text-gray-600 w-5 text-right flex-shrink-0">{{ $player['number'] ?? '' }}</span>
                        <span class="text-xs text-gray-400 truncate flex-1">{{ $player['name'] ?? '' }}</span>
                        <span class="text-xs text-gray-700 flex-shrink-0">{{ $player['pos'] ?? '' }}</span>
                    </div>
                    @endforeach
                </div>
                <div>
                    @foreach($awaySubs as $player)
                    <div class="flex items-center gap-2 py-1 border-b border-[#222]">
                        <span class="text-xs text-gray-600 w-5 text-right flex-shrink-0">{{ $player['number'] ?? '' }}</span>
                        <span class="text-xs text-gray-400 truncate flex-1">{{ $player['name'] ?? '' }}</span>
                        <span class="text-xs text-gray-700 flex-shrink-0">{{ $player['pos'] ?? '' }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        @else
        <p class="text-gray-500 text-sm text-center py-4">Postave još nisu objavljene.</p>
        @endif
    </div>
    @endif

    {{-- H2H TAB --}}
    @if($activeTab === 'h2h')
        <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-4 mb-4">
            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-3">
                Medusobni susreti — {{ $fixture->homeTeam->name }} vs {{ $fixture->awayTeam->name }}
            </h3>
            @if(empty($h2h))
                <p class="text-gray-500 text-sm text-center py-4">Nema podataka o ranijim susretima.</p>
            @else
                @foreach($h2h as $match)
                @php
                    $homeWon = $match['score_home'] > $match['score_away'];
                    $awayWon = $match['score_away'] > $match['score_home'];
                @endphp
                <a href="/utakmica/{{ $match['id'] }}" class="flex items-center gap-3 py-2.5 border-b border-[#2a2a2a] last:border-0 hover:bg-[#222] rounded px-2 transition cursor-pointer">
                    <span class="text-xs text-gray-500 w-20 flex-shrink-0">{{ \Carbon\Carbon::parse($match['kick_off'])->format('d.m.Y') }}</span>
                    <div class="flex-1 flex items-center gap-2 justify-end">
                        @if($match['home_team_logo'])
                            <img src="{{ $match['home_team_logo'] }}" class="w-4 h-4 object-contain" alt="">
                        @endif
                        <span class="text-sm {{ $homeWon ? 'text-white font-bold' : 'text-gray-400' }}">{{ $match['home_team_name'] }}</span>
                    </div>
                    <div class="flex items-center gap-1 min-w-[60px] justify-center">
                        <span class="text-sm font-black {{ $homeWon ? 'text-[#CCFF00]' : 'text-white' }}">{{ $match['score_home'] ?? '?' }}</span>
                        <span class="text-gray-500 text-xs">-</span>
                        <span class="text-sm font-black {{ $awayWon ? 'text-[#CCFF00]' : 'text-white' }}">{{ $match['score_away'] ?? '?' }}</span>
                    </div>
                    <div class="flex-1 flex items-center gap-2">
                        @if($match['away_team_logo'])
                            <img src="{{ $match['away_team_logo'] }}" class="w-4 h-4 object-contain" alt="">
                        @endif
                        <span class="text-sm {{ $awayWon ? 'text-white font-bold' : 'text-gray-400' }}">{{ $match['away_team_name'] }}</span>
                    </div>
                </a>
                @endforeach

                {{-- H2H summary --}}
                @php
                    $homeWins = collect($h2h)->filter(fn($m) => $m['score_home'] > $m['score_away'])->count();
                    $awayWins = collect($h2h)->filter(fn($m) => $m['score_away'] > $m['score_home'])->count();
                    $draws    = collect($h2h)->filter(fn($m) => $m['score_home'] === $m['score_away'])->count();
                @endphp
                <div class="mt-4 flex justify-around text-center border-t border-[#2a2a2a] pt-4">
                    <div>
                        <div class="text-2xl font-black text-[#CCFF00]">{{ $homeWins }}</div>
                        <div class="text-xs text-gray-500 mt-1">{{ $fixture->homeTeam->name }}</div>
                    </div>
                    <div>
                        <div class="text-2xl font-black text-gray-400">{{ $draws }}</div>
                        <div class="text-xs text-gray-500 mt-1">Nerješeno</div>
                    </div>
                    <div>
                        <div class="text-2xl font-black text-[#CCFF00]">{{ $awayWins }}</div>
                        <div class="text-xs text-gray-500 mt-1">{{ $fixture->awayTeam->name }}</div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <livewire:match-prediction :fixture-id="$fixture->id" :home-team="$fixture->homeTeam->name ?? 'Domacin'" :away-team="$fixture->awayTeam->name ?? 'Gost'" />

    {{-- Venue --}}
    @if($fixture->venue_name)
    <div class="text-center text-xs text-gray-500">
        🏟 {{ $fixture->venue_name }}
        @if($fixture->referee) &bull; Sudija: {{ $fixture->referee }} @endif
    </div>
    @endif

    <x-affiliate-banner ad-slot="match-bottom" extra-class="mt-4" />

    @if($isLive)
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof window.Echo === 'undefined') return;
        window.Echo.channel('fixture.{{ $fixture->id }}')
            .listen('.score.updated', (data) => {
                const homeScore = document.getElementById('score-home');
                const awayScore = document.getElementById('score-away');
                const minute = document.getElementById('match-minute');
                if (homeScore) homeScore.textContent = data.home_goals !== undefined ? data.home_goals : (data.goals_home ?? 0);
                if (awayScore) awayScore.textContent = data.away_goals !== undefined ? data.away_goals : (data.goals_away ?? 0);
                if (minute && data.minute !== undefined) minute.textContent = data.minute;
            });
    });
    </script>
    @endif

</div>
