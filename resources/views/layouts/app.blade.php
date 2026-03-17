<!DOCTYPE html>
<html lang="bs" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Rezultati uživo') — rezultati.net</title>
    <meta name="description" content="@yield('description', 'Live rezultati fudbala, košarke i tenisa. HNL, Liga prvaka, SuperLiga i još stotine liga uživo.')">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-brand-dark text-white font-sans min-h-full">

    {{-- NAV --}}
    <nav class="bg-brand-card border-b border-brand-border sticky top-0 z-50">
        <div class="max-w-6xl mx-auto px-4">
            <div class="flex items-center justify-between h-14">
                {{-- Logo --}}
                <a href="/" class="flex items-center gap-2">
                    <span class="text-brand-lime font-black text-xl tracking-tight">rezultati</span>
                    <span class="text-white font-black text-xl tracking-tight">.net</span>
                </a>

                {{-- Sport tabs --}}
                <div class="flex items-center gap-1">
                    <a href="/nogomet" class="px-3 py-1.5 rounded text-sm font-semibold {{ request()->is('nogomet*') ? 'bg-brand-lime text-black' : 'text-brand-muted hover:text-white' }} transition">
                        ⚽ Fudbal
                    </a>
                    <a href="/kosarka" class="px-3 py-1.5 rounded text-sm font-semibold {{ request()->is('kosarka*') ? 'bg-brand-lime text-black' : 'text-brand-muted hover:text-white' }} transition">
                        ��� Košarka
                    </a>
                    <a href="/tenis" class="px-3 py-1.5 rounded text-sm font-semibold {{ request()->is('tenis*') ? 'bg-brand-lime text-black' : 'text-brand-muted hover:text-white' }} transition">
                        ��� Tenis
                    </a>
                </div>

                {{-- Date nav --}}
                <div class="hidden md:flex items-center gap-2 text-sm text-brand-muted">
                    <a href="/jucer" class="hover:text-white transition">Jučer</a>
                    <span class="text-brand-lime font-bold">Danas</span>
                    <a href="/sutra" class="hover:text-white transition">Sutra</a>
                </div>
            </div>
        </div>
    </nav>

    {{-- CONTENT --}}
    <main class="max-w-6xl mx-auto px-4 py-4">
        @yield('content')
    </main>

    {{-- FOOTER --}}
    <footer class="border-t border-brand-border mt-12 py-6 text-center text-brand-muted text-sm">
        <p>© {{ date('Y') }} rezultati.net — Rezultati uživo</p>
        <p class="mt-1 text-xs">18+ | Klađenje može biti štetno za zdravlje. Igrajte odgovorno.</p>
    </footer>

    @livewireScripts
</body>
</html>
