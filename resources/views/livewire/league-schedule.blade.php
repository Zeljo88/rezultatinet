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
            <li class="text-white font-semibold">Raspored</li>
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
                    {{ $leagueName }} Raspored {{ $season }}
                </h1>
                <p class="text-sm text-gray-400 mt-1">Sljedeće i prethodne utakmice u {{ $leagueName }} sezoni {{ $season }}.</p>
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
            <span class="px-3 py-1.5 rounded-full text-xs font-semibold bg-[#CCFF00] text-black">
                📅 Raspored
            </span>
            <a href="/liga/{{ $slug }}/strijelci" class="px-3 py-1.5 rounded-full text-xs font-semibold bg-[#2a2a2a] text-gray-300 hover:text-white transition">
                🥅 Strijelci
            </a>
        </div>
    </div>

    {{-- UPCOMING FIXTURES --}}
    <section class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl overflow-hidden">
        <h2 class="text-base font-bold text-white px-4 py-3 border-b border-[#2a2a2a] bg-[#0f0f0f]">
            📅 Sljedeće utakmice
        </h2>
        @if(empty($upcomingFixtures))
            <p class="text-gray-500 text-sm px-4 py-6">Nema zakazanih utakmica.</p>
        @else
            <div class="divide-y divide-[#2a2a2a]">
                @foreach($upcomingFixtures as $f)
                <div class="flex items-center gap-3 px-4 py-3 hover:bg-[#222] transition">
                    {{-- Date/time --}}
                    <div class="flex-shrink-0 w-20 text-center">
                        <div class="text-xs text-gray-500">{{ \Carbon\Carbon::parse($f['kick_off'])->format('d.m.Y') }}</div>
                        <div class="text-sm font-bold text-[#CCFF00]">{{ \Carbon\Carbon::parse($f['kick_off'])->format('H:i') }}</div>
                        @if($f['round'])
                            <div class="text-[10px] text-gray-600 truncate">{{ $f['round'] }}</div>
                        @endif
                    </div>

                    {{-- Home team --}}
                    <div class="flex-1 flex items-center justify-end gap-2 min-w-0">
                        <span class="text-sm font-semibold text-white truncate text-right">{{ $f['home_team_name'] }}</span>
                        @if($f['home_team_logo'])
                            <img src="{{ $f['home_team_logo'] }}" alt="" class="w-6 h-6 object-contain flex-shrink-0" loading="lazy">
                        @endif
                    </div>

                    {{-- Score / VS --}}
                    <div class="flex-shrink-0 w-12 text-center">
                        <span class="text-sm font-black text-gray-400">vs</span>
                    </div>

                    {{-- Away team --}}
                    <div class="flex-1 flex items-center gap-2 min-w-0">
                        @if($f['away_team_logo'])
                            <img src="{{ $f['away_team_logo'] }}" alt="" class="w-6 h-6 object-contain flex-shrink-0" loading="lazy">
                        @endif
                        <span class="text-sm font-semibold text-white truncate">{{ $f['away_team_name'] }}</span>
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- RECENT FIXTURES --}}
    <section class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl overflow-hidden">
        <h2 class="text-base font-bold text-white px-4 py-3 border-b border-[#2a2a2a] bg-[#0f0f0f]">
            📋 Prethodne utakmice
        </h2>
        @if(empty($recentFixtures))
            <p class="text-gray-500 text-sm px-4 py-6">Nema odigranih utakmica.</p>
        @else
            <div class="divide-y divide-[#2a2a2a]">
                @foreach($recentFixtures as $f)
                @php
                    $homeSlug = $f['home_team_slug'] ?? '';
                    $awaySlug = $f['away_team_slug'] ?? '';
                    $dateStr  = $f['kick_off'] ? \Carbon\Carbon::parse($f['kick_off'])->format('d-m-Y') : null;
                    $matchUrl = ($homeSlug && $awaySlug && $dateStr)
                        ? "/utakmica/{$homeSlug}-vs-{$awaySlug}-{$dateStr}"
                        : null;
                @endphp
                <a href="{{ $matchUrl ?? '#' }}" class="flex items-center gap-3 px-4 py-3 hover:bg-[#222] transition {{ $matchUrl ? '' : 'cursor-default' }}">
                    {{-- Date --}}
                    <div class="flex-shrink-0 w-20 text-center">
                        <div class="text-xs text-gray-500">{{ \Carbon\Carbon::parse($f['kick_off'])->format('d.m.Y') }}</div>
                        <div class="text-[10px] text-gray-600">FT</div>
                    </div>

                    {{-- Home team --}}
                    <div class="flex-1 flex items-center justify-end gap-2 min-w-0">
                        <span class="text-sm font-semibold text-white truncate text-right">{{ $f['home_team_name'] }}</span>
                        @if($f['home_team_logo'])
                            <img src="{{ $f['home_team_logo'] }}" alt="" class="w-6 h-6 object-contain flex-shrink-0" loading="lazy">
                        @endif
                    </div>

                    {{-- Score --}}
                    <div class="flex-shrink-0 w-16 text-center">
                        <span class="text-sm font-black text-white">
                            {{ $f['score_home'] ?? '?' }} : {{ $f['score_away'] ?? '?' }}
                        </span>
                    </div>

                    {{-- Away team --}}
                    <div class="flex-1 flex items-center gap-2 min-w-0">
                        @if($f['away_team_logo'])
                            <img src="{{ $f['away_team_logo'] }}" alt="" class="w-6 h-6 object-contain flex-shrink-0" loading="lazy">
                        @endif
                        <span class="text-sm font-semibold text-white truncate">{{ $f['away_team_name'] }}</span>
                    </div>
                </a>
                @endforeach
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
