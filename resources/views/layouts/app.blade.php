<!DOCTYPE html>
<html lang="bs" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Rezultati uživo | Nogomet, Košarka, Tenis') — rezultati.net</title>
    <meta name="description" content="@yield('description', 'Pratite rezultate uživo za nogomet, košarku i tenis. HNL, Champions liga, ABA liga i još stotine natjecanja — sve na jednom mjestu, u stvarnom vremenu.')">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:title" content="@yield('title', 'Rezultati uživo | Nogomet, Košarka, Tenis')">
    <meta property="og:description" content="@yield('description', 'Pratite rezultate uživo za nogomet, košarku i tenis.')">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:site_name" content="rezultati.net">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="shortcut icon" href="/favicon.ico">
    @vite(["resources/css/app.css", "resources/js/app.js"])
    @livewireStyles
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-854YSPE0YX"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag("js", new Date());
        gtag("config", "G-854YSPE0YX");
    </script>
    <style>
        .league-scroll::-webkit-scrollbar { display: none; }
        .league-scroll { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-[#0f0f0f] text-white font-sans min-h-full">

    {{-- TOP NAV --}}
    <nav class="bg-[#1a1a1a] border-b border-[#2a2a2a] sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex items-center justify-between h-14">
                <a href="/" class="flex items-center gap-1 flex-shrink-0">
                    <span class="text-[#CCFF00] font-black text-xl tracking-tight">rezultati</span><span class="text-white font-black text-xl tracking-tight">.net</span>
                </a>
                <div class="flex items-center gap-1">
                    <a href="/" class="px-3 py-1.5 rounded text-sm font-semibold {{ request()->is('/') ? 'bg-[#CCFF00] text-black' : 'text-gray-400 hover:text-white' }} transition">⚽ Fudbal</a>
                    <a href="/kosarka" class="px-3 py-1.5 rounded text-sm font-semibold {{ request()->is('kosarka*') ? 'bg-[#CCFF00] text-black' : 'text-gray-400 hover:text-white' }} transition">🏀 Kosarka</a>
                    <a href="/tenis" class="px-3 py-1.5 rounded text-sm font-semibold {{ request()->is('tenis*') ? 'bg-[#CCFF00] text-black' : 'text-gray-400 hover:text-white' }} transition">🎾 Tenis</a>
                </div>
                <a href="/pretraga" class="text-gray-400 hover:text-white transition p-2 rounded-lg hover:bg-[#2a2a2a]" title="Pretraga">🔍</a>
                <div class="hidden md:flex items-center gap-3 text-sm">
                    <a href="/jucer" class="text-gray-400 hover:text-white transition">Jucer</a>
                    <a href="/" class="text-[#CCFF00] font-bold hover:text-white transition">Danas</a>
                    <a href="/sutra" class="text-gray-400 hover:text-white transition">Sutra</a>
                </div>
            </div>
        </div>

        {{-- MOBILE LEAGUE SCROLL BAR --}}
        <div class="lg:hidden border-t border-[#2a2a2a] overflow-x-auto league-scroll">
            <div class="flex items-center gap-1 px-3 py-2 w-max">
                <span class="text-gray-600 text-xs mr-1 flex-shrink-0">Lige:</span>
                <a href="/liga/hnl" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/hnl') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    <img src="https://media.api-sports.io/football/leagues/210.png" class="w-3.5 h-3.5 object-contain"> HNL
                </a>
                <a href="/liga/superliga-srbija" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/superliga-srbija') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    <img src="https://media.api-sports.io/football/leagues/286.png" class="w-3.5 h-3.5 object-contain"> Superliga SRB
                </a>
                <a href="/liga/premijer-liga-bih" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/premijer-liga-bih') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    <img src="https://media.api-sports.io/football/leagues/315.png" class="w-3.5 h-3.5 object-contain"> Premijer BiH
                </a>
                <a href="/liga/champions-liga" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/champions-liga') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    <img src="https://media.api-sports.io/football/leagues/2.png" class="w-3.5 h-3.5 object-contain"> Champions Liga
                </a>
                <a href="/liga/europa-liga" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/europa-liga') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    <img src="https://media.api-sports.io/football/leagues/3.png" class="w-3.5 h-3.5 object-contain"> Europa Liga
                </a>
                <a href="/liga/premier-league" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/premier-league') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    <img src="https://media.api-sports.io/football/leagues/39.png" class="w-3.5 h-3.5 object-contain"> Premier League
                </a>
                <a href="/liga/la-liga" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/la-liga') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    <img src="https://media.api-sports.io/football/leagues/140.png" class="w-3.5 h-3.5 object-contain"> La Liga
                </a>
                <a href="/liga/serie-a" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/serie-a') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    <img src="https://media.api-sports.io/football/leagues/135.png" class="w-3.5 h-3.5 object-contain"> Serie A
                </a>
                <a href="/liga/bundesliga" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/bundesliga') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    <img src="https://media.api-sports.io/football/leagues/78.png" class="w-3.5 h-3.5 object-contain"> Bundesliga
                </a>
                <a href="/liga/ligue-1" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/ligue-1') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    <img src="https://media.api-sports.io/football/leagues/61.png" class="w-3.5 h-3.5 object-contain"> Ligue 1
                </a>
            </div>
        </div>
    </nav>

    {{-- MAIN LAYOUT --}}
    <div class="max-w-7xl mx-auto px-4 py-4 flex gap-4">

        {{-- LEFT SIDEBAR (desktop only) --}}
        <aside class="hidden lg:block w-56 flex-shrink-0" style="padding-top: 88px">
            <div class="mb-4">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider px-2 mb-2">⚽ Fudbal</p>

                <p class="text-[10px] text-[#CCFF00] font-bold uppercase tracking-wider px-2 mt-3 mb-1">Balkan</p>
                <a href="/liga/hnl" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/hnl') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    <img src="https://media.api-sports.io/football/leagues/210.png" class="w-4 h-4 object-contain" alt=""> HNL
                </a>
                <a href="/liga/superliga-srbija" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/superliga-srbija') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    <img src="https://media.api-sports.io/football/leagues/286.png" class="w-4 h-4 object-contain" alt=""> Superliga Srbija
                </a>
                <a href="/liga/premijer-liga-bih" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/premijer-liga-bih') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    <img src="https://media.api-sports.io/football/leagues/315.png" class="w-4 h-4 object-contain" alt=""> Premijer Liga BiH
                </a>
                <a href="/liga/prva-liga-srbija" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/prva-liga-srbija') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    <img src="https://media.api-sports.io/football/leagues/287.png" class="w-4 h-4 object-contain" alt=""> Prva Liga Srbija
                </a>
                <a href="/liga/first-nl-hrvatska" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/first-nl-hrvatska') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    <img src="https://media.api-sports.io/football/leagues/211.png" class="w-4 h-4 object-contain" alt=""> Prva NL Hrvatska
                </a>

                <p class="text-[10px] text-[#CCFF00] font-bold uppercase tracking-wider px-2 mt-3 mb-1">Europa</p>
                <a href="/liga/champions-liga" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/champions-liga') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    <img src="https://media.api-sports.io/football/leagues/2.png" class="w-4 h-4 object-contain" alt=""> Champions Liga
                </a>
                <a href="/liga/europa-liga" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/europa-liga') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    <img src="https://media.api-sports.io/football/leagues/3.png" class="w-4 h-4 object-contain" alt=""> Europa Liga
                </a>
                <a href="/liga/konferencijska-liga" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/konferencijska-liga') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    <img src="https://media.api-sports.io/football/leagues/848.png" class="w-4 h-4 object-contain" alt=""> Konferencijska
                </a>
                <a href="/liga/premier-league" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/premier-league') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    <img src="https://media.api-sports.io/football/leagues/39.png" class="w-4 h-4 object-contain" alt=""> Premier League
                </a>
                <a href="/liga/la-liga" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/la-liga') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    <img src="https://media.api-sports.io/football/leagues/140.png" class="w-4 h-4 object-contain" alt=""> La Liga
                </a>
                <a href="/liga/serie-a" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/serie-a') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    <img src="https://media.api-sports.io/football/leagues/135.png" class="w-4 h-4 object-contain" alt=""> Serie A
                </a>
                <a href="/liga/bundesliga" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/bundesliga') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    <img src="https://media.api-sports.io/football/leagues/78.png" class="w-4 h-4 object-contain" alt=""> Bundesliga
                </a>
                <a href="/liga/ligue-1" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/ligue-1') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    <img src="https://media.api-sports.io/football/leagues/61.png" class="w-4 h-4 object-contain" alt=""> Ligue 1
                </a>
            </div>

                            <a href="/strijelci" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm text-gray-300 mb-1">
                    ⚽ Strijelci
                </a>

            <div class="mb-4">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider px-2 mb-2">🏀 Kosarka</p>
                <a href="/kosarka" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm text-gray-300">ABA Liga</a>
                <a href="/kosarka" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm text-gray-300">NBA</a>
                <a href="/kosarka" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm text-gray-300">EuroLeague</a>
            </div>

            <div>
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider px-2 mb-2">🎾 Tenis</p>
                <a href="/tenis" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm text-gray-300">ATP</a>
                <a href="/tenis" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm text-gray-300">WTA</a>
            </div>
        </aside>

        {{-- MAIN CONTENT --}}
        <main class="flex-1 min-w-0">
            @hasSection('content')
                @yield('content')
            @else
                {{ $slot }}
            @endif
        </main>

    </div>

    <footer class="border-t border-[#2a2a2a] mt-12 py-6 text-center text-gray-500 text-sm">
        <p>&copy; {{ date('Y') }} rezultati.net &mdash; Rezultati uzivo</p>
        <p class="mt-1 text-xs opacity-60">18+ | Kladenje moze biti stetno za zdravlje. Igrajte odgovorno.</p>
    </footer>

    @livewireScripts
</body>
</html>
