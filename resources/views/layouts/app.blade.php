<!DOCTYPE html>
<html lang="bs" class="h-full overflow-x-hidden">
<head>
    @php
        $schemaBlocks   = $schemaBlocks ?? [];
        $canonicalUrl   = $canonicalUrl ?? null;
        $resolvedTitle  = $metaTitle ?? null;
        $resolvedDesc   = $metaDescription ?? null;
        $resolvedImage  = ($ogImage ?? null) ?: asset('images/og/default.jpg');
    @endphp
    <meta charset="UTF-8">
    {{-- Performance: preconnect & DNS prefetch --}}
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="dns-prefetch" href="https://media.api-sports.io">
    <link rel="dns-prefetch" href="https://www.googletagmanager.com">
    <link rel="dns-prefetch" href="https://connect.facebook.net">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $resolvedTitle ?: $__env->yieldContent('title', 'Rezultati uživo | Nogomet, HNL, Liga prvaka — rezultati.net') }}</title>
    <meta name="description" content="{{ $resolvedDesc ?: $__env->yieldContent('meta_description', 'Pratite live rezultate na rezultati.net') }}">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ $canonicalUrl ?? url()->current() }}">
    <link rel="alternate" hreflang="bs" href="{{ url()->current() }}">
    <link rel="alternate" hreflang="hr" href="{{ url()->current() }}">
    <link rel="alternate" hreflang="sr" href="{{ url()->current() }}">
    <link rel="alternate" hreflang="x-default" href="{{ url()->current() }}">
    <meta property="og:title" content="{{ $resolvedTitle ?? 'Rezultati uživo — rezultati.net' }}">
    <meta property="og:description" content="{{ $resolvedDesc ?? 'Pratite live rezultate na rezultati.net' }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ $resolvedImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:locale" content="bs_BA">
    <meta property="og:site_name" content="rezultati.net">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#CCFF00">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Rezultati">
    <script>if('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js');</script>

    {{-- Instrument Sans from Google Fonts with font-display: swap --}}
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;600;700;800;900&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;600;700;800;900&display=swap"></noscript>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak] { display: none !important; }</style>
    <script>
        window.addEventListener("load", function() {
            var s = document.createElement("script");
            s.async = true;
            s.src = "https://www.googletagmanager.com/gtag/js?id=G-854YSPE0YX";
            document.head.appendChild(s);
        });
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag("js", new Date());
        gtag("config", "G-854YSPE0YX", {"transport_type": "beacon"});
    </script>
    <style>
        .league-scroll::-webkit-scrollbar { display: none; }
        .league-scroll { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@rezultatinet">
    <meta name="twitter:title" content="{{ $resolvedTitle ?? 'Rezultati uživo — rezultati.net' }}">
    <meta name="twitter:description" content="{{ $resolvedDesc ?? 'Pratite live rezultate na rezultati.net' }}">
    <meta name="twitter:image" content="{{ $resolvedImage }}">
    @verbatim
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebSite",
      "url": "https://rezultati.net",
      "name": "rezultati.net",
      "description": "Live rezultati nogometa, košarke i tenisa za Balkan region",
      "potentialAction": {
        "@type": "SearchAction",
        "target": "https://rezultati.net/pretraga?q={search_term_string}",
        "query-input": "required name=search_term_string"
      }
    }
    </script>
    @endverbatim
    @foreach($schemaBlocks as $schemaBlock)
    <script type="application/ld+json">
    {!! $schemaBlock !!}
    </script>
    @endforeach
    @stack('schema')
    <!-- RSS Auto-discovery -->
    <link rel="alternate" type="application/rss+xml" title="rezultati.net RSS Feed" href="{{ url('/feed') }}">
</head>
<body class="bg-[#0f0f0f] text-white font-sans min-h-full overflow-x-hidden">

    {{-- TOP NAV --}}
    <nav class="bg-[#1a1a1a] border-b border-[#2a2a2a] sticky top-0 z-50 max-w-full overflow-x-hidden" x-data="{ mobileOpen: false }">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex items-center justify-between h-14">
                <a href="/" class="flex items-center gap-1 flex-shrink-0">
                    <span class="text-[#CCFF00] font-black text-xl tracking-tight">rezultati</span><span class="text-white font-black text-xl tracking-tight">.net</span>
                </a>
                <div class="hidden md:flex items-center gap-1">
                    <a href="/" class="px-3 py-1.5 rounded-full text-xs font-bold transition {{ request()->is('/') || request()->is('fudbal*') ? 'bg-[#CCFF00] text-black' : 'text-gray-400 hover:text-white' }}">
                        Fudbal
                    </a>
                    <a href="/kosarka" class="px-3 py-1.5 rounded-full text-xs font-bold transition {{ request()->is('kosarka*') ? 'bg-[#CCFF00] text-black' : 'text-gray-400 hover:text-white' }}">
                        Košarka
                    </a>
                    <a href="/tenis" class="px-3 py-1.5 rounded-full text-xs font-bold transition {{ request()->is('tenis*') ? 'bg-[#CCFF00] text-black' : 'text-gray-400 hover:text-white' }}">
                        Tenis
                    </a>
                    <a href="/blog" class="px-3 py-1.5 rounded-full text-xs font-bold transition {{ request()->is('blog*') ? 'bg-[#CCFF00] text-black' : 'text-gray-400 hover:text-white' }}">
                        Blog
                    </a>
                </div>
                <div class="flex items-center gap-1">
                    <a href="/pretraga" class="text-gray-400 hover:text-white transition p-2 rounded-lg hover:bg-[#2a2a2a]" title="Pretraga">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
                        </svg>
                    </a>
                    <button @click="mobileOpen = !mobileOpen"
                            class="md:hidden p-2 text-gray-400 hover:text-white transition rounded-lg hover:bg-[#2a2a2a]"
                            aria-label="Meni">
                        <svg x-show="!mobileOpen" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                        <svg x-show="mobileOpen" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display:none">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        {{-- MOBILE DROPDOWN MENU --}}
        <div x-show="mobileOpen"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-1"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 -translate-y-1"
             @click.away="mobileOpen = false"
             class="md:hidden bg-[#1a1a1a] border-t border-[#2a2a2a] shadow-xl"
             style="display:none">
            <nav class="flex flex-col py-2 max-w-7xl mx-auto px-4">
                <a href="/" @click="mobileOpen = false"
                   class="flex items-center gap-3 px-3 py-3 rounded-lg text-sm font-semibold transition {{ request()->is('/') || request()->is('fudbal*') ? 'text-[#CCFF00] bg-[#2a2a2a]' : 'text-gray-300 hover:text-white hover:bg-[#2a2a2a]' }}">
                    <span class="text-lg">⚽</span> Fudbal
                </a>
                <a href="/kosarka" @click="mobileOpen = false"
                   class="flex items-center gap-3 px-3 py-3 rounded-lg text-sm font-semibold transition {{ request()->is('kosarka*') ? 'text-[#CCFF00] bg-[#2a2a2a]' : 'text-gray-300 hover:text-white hover:bg-[#2a2a2a]' }}">
                    <span class="text-lg">🏀</span> Košarka
                </a>
                <a href="/tenis" @click="mobileOpen = false"
                   class="flex items-center gap-3 px-3 py-3 rounded-lg text-sm font-semibold transition {{ request()->is('tenis*') ? 'text-[#CCFF00] bg-[#2a2a2a]' : 'text-gray-300 hover:text-white hover:bg-[#2a2a2a]' }}">
                    <span class="text-lg">🎾</span> Tenis
                </a>
                <a href="/blog" @click="mobileOpen = false"
                   class="flex items-center gap-3 px-3 py-3 rounded-lg text-sm font-semibold transition {{ request()->is('blog*') ? 'text-[#CCFF00] bg-[#2a2a2a]' : 'text-gray-300 hover:text-white hover:bg-[#2a2a2a]' }}">
                    <span class="text-lg">📝</span> Blog
                </a>
                <a href="/igraci/balkan" @click="mobileOpen = false"
                   class="flex items-center gap-3 px-3 py-3 rounded-lg text-sm font-semibold transition {{ request()->is('igraci/balkan*') ? 'text-[#CCFF00] bg-[#2a2a2a]' : 'text-gray-300 hover:text-white hover:bg-[#2a2a2a]' }}">
                    <span class="text-lg">🌍</span> Balkanci u EU
                </a>
                <a href="/strijelci" @click="mobileOpen = false"
                   class="flex items-center gap-3 px-3 py-3 rounded-lg text-sm font-semibold transition {{ request()->is('strijelci*') ? 'text-[#CCFF00] bg-[#2a2a2a]' : 'text-gray-300 hover:text-white hover:bg-[#2a2a2a]' }}">
                    <span class="text-lg">🥇</span> Strijelci
                </a>
                <div class="border-t border-[#2a2a2a] my-1"></div>
                <a href="/pretraga" @click="mobileOpen = false"
                   class="flex items-center gap-3 px-3 py-3 rounded-lg text-sm font-semibold text-gray-300 hover:text-white hover:bg-[#2a2a2a] transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
                    </svg> Pretraga
                </a>
            </nav>
        </div>

        {{-- MOBILE LEAGUE SCROLL BAR --}}
        <div class="lg:hidden border-t border-[#2a2a2a] overflow-x-auto league-scroll">
            <div class="flex items-center gap-1 px-3 py-2 w-max">
                <span class="text-gray-600 text-xs mr-1 flex-shrink-0">Lige:</span>
                <a href="/liga/hnl" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/hnl') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    🇭🇷 HNL
                </a>
                <a href="/liga/superliga-srbija" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/superliga-srbija') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    🇷🇸 Superliga Srbija
                </a>
                <a href="/liga/premijer-liga-bih" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/premijer-liga-bih') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    🇧🇦 Premijer Liga BiH
                </a>
                <a href="/liga/hnl-2" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/hnl-2') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    🇭🇷 HNL 2
                </a>
                <a href="/liga/prva-liga-fbih" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/prva-liga-fbih') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    🇧🇦 Prva liga FBiH
                </a>
                <a href="/liga/prva-liga-rs" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/prva-liga-rs') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    🇷🇸 Prva liga RS
                </a>
                <a href="/liga/champions-liga" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/champions-liga') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    ⭐ Champions Liga
                </a>
                <a href="/liga/europa-liga" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/europa-liga') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    🔶 Europa Liga
                </a>
                <a href="/liga/konferencijska-liga" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/konferencijska-liga') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    ⚪ Konferencijska liga
                </a>
                <a href="/liga/premier-league" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/premier-league') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    🏴󠁧󠁢󠁥󠁮󠁧󠁿 Premier League
                </a>
                <a href="/liga/la-liga" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/la-liga') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    🇪🇸 La Liga
                </a>
                <a href="/liga/bundesliga" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/bundesliga') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    🇩🇪 Bundesliga
                </a>
                <a href="/liga/serie-a" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/serie-a') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    🇮🇹 Serie A
                </a>
                <a href="/liga/ligue-1" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold flex-shrink-0 {{ request()->is('liga/ligue-1') ? 'bg-[#CCFF00] text-black' : 'bg-[#2a2a2a] text-gray-300 hover:text-white' }} transition">
                    🇫🇷 Ligue 1
                </a>
            </div>
        </div>
    </nav>

    {{-- MAIN LAYOUT --}}
    <div class="max-w-7xl mx-auto md:px-4 py-4 flex gap-4 overflow-x-hidden">

        {{-- LEFT SIDEBAR (desktop only) --}}
        <aside class="hidden lg:block w-56 flex-shrink-0" style="padding-top: 88px">
            <div class="mb-4">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider px-2 mb-2">⚽ Fudbal</p>

                <p class="text-[10px] text-[#CCFF00] font-bold uppercase tracking-wider px-2 mt-3 mb-1">Balkan</p>
                <a href="/liga/hnl" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/hnl') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    🇭🇷 HNL
                </a>
                <a href="/liga/superliga-srbija" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/superliga-srbija') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    🇷🇸 Superliga Srbija
                </a>
                <a href="/liga/premijer-liga-bih" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/premijer-liga-bih') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    🇧🇦 Premijer Liga BiH
                </a>
                <a href="/liga/hnl-2" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/hnl-2') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    🇭🇷 HNL 2
                </a>
                <a href="/liga/prva-liga-fbih" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/prva-liga-fbih') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    🇧🇦 Prva liga FBiH
                </a>
                <a href="/liga/prva-liga-rs" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/prva-liga-rs') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    🇷🇸 Prva liga RS
                </a>

                <p class="text-[10px] text-[#CCFF00] font-bold uppercase tracking-wider px-2 mt-3 mb-1">Evropa</p>
                <a href="/liga/champions-liga" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/champions-liga') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    ⭐ Champions Liga
                </a>
                <a href="/liga/europa-liga" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/europa-liga') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    🔶 Europa Liga
                </a>
                <a href="/liga/konferencijska-liga" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/konferencijska-liga') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    ⚪ Konferencijska liga
                </a>

                <p class="text-[10px] text-[#CCFF00] font-bold uppercase tracking-wider px-2 mt-3 mb-1">Lige petice</p>
                <a href="/liga/premier-league" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/premier-league') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    🏴󠁧󠁢󠁥󠁮󠁧󠁿 Premier League
                </a>
                <a href="/liga/la-liga" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/la-liga') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    🇪🇸 La Liga
                </a>
                <a href="/liga/bundesliga" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/bundesliga') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    🇩🇪 Bundesliga
                </a>
                <a href="/liga/serie-a" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/serie-a') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    🇮🇹 Serie A
                </a>
                <a href="/liga/ligue-1" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm {{ request()->is('liga/ligue-1') ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }}">
                    🇫🇷 Ligue 1
                </a>
            </div>

                            <a href="/igraci/balkan" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm text-gray-300 mb-1">
                    🌍 Balkanci u EU
                </a>
                <a href="/blog" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm text-gray-300 mb-1">
                    📝 Blog
                </a>
                <a href="/strijelci" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm text-gray-300 mb-1">
                    ⚽ Strijelci
                </a>
                <a href="/sudija" class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#1a1a1a] transition text-sm text-gray-300 mb-1">
                    🏁 Sudije
                </a>

            
            <x-affiliate-banner ad-slot="sidebar-1" extra-class="my-4" />

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

        {{-- RIGHT AFFILIATE SIDEBAR (desktop only) --}}
        <aside class="hidden lg:block w-64 flex-shrink-0">
            <div class="sticky top-[80px]">
                <!-- affiliate sidebar -->

                <!-- Facebook Page Widget -->
                <div class="rounded-lg overflow-hidden mb-4" id="fb-page-wrapper" style="display:none;">
                  <div class="fb-page"
                    data-href="https://www.facebook.com/rezultatinet"
                    data-tabs=""
                    data-width=""
                    data-height="130"
                    data-small-header="true"
                    data-adapt-container-width="true"
                    data-hide-cover="false"
                    data-show-facepile="true">
                  </div>
                </div>

                <a href="https://www.facebook.com/rezultatinet"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="flex items-center justify-center gap-2 w-full bg-[#1877F2] hover:bg-[#1461c8] text-white text-sm font-semibold py-2 px-4 rounded-lg transition-colors mb-4">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="white">
                    <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.235 2.686.235v2.97h-1.513c-1.491 0-1.956.93-1.956 1.874v2.25h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                  </svg>
                  Prati nas na Facebooku
                </a>
            </div>
        </aside>

    </div>

    <footer class="border-t border-[#2a2a2a] mt-12 py-6 text-center text-gray-500 text-sm">
        <p>&copy; {{ date('Y') }} rezultati.net &mdash; Rezultati uzivo</p>
        <p class="mt-1 text-xs opacity-60">18+ | Kladenje moze biti stetno za zdravlje. Igrajte odgovorno.</p>
    </footer>

    @livewireScripts
    <!-- Facebook SDK — deferred to not block render -->
    <div id="fb-root"></div>
    <script>
    // Load Facebook SDK after page load to avoid render blocking
    window.addEventListener("load", function() {
        setTimeout(function() {
            window.fbAsyncInit = function() {
                FB.init({ xfbml: true, version: 'v19.0' });
                FB.Event.subscribe('xfbml.render', function() {
                    var w = document.getElementById('fb-page-wrapper');
                    if (w) w.style.display = '';
                });
            };
            var s = document.createElement("script");
            s.async = true;
            s.defer = true;
            s.crossOrigin = "anonymous";
            s.src = "https://connect.facebook.net/bs_BA/sdk.js#xfbml=1&version=v19.0";
            document.body.appendChild(s);
        }, 3000);
    });
    </script>
</body>
</html>
