<!DOCTYPE html>
<html lang="bs" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Rezultati uzivo') â€” rezultati.net</title>
    <meta name="description" content="@yield('description', 'Live rezultati fudbala, kosarke i tenisa.')">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <!-- Google Analytics -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-854YSPE0YX"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag("js", new Date());
        gtag("config", "G-854YSPE0YX");
    </script>
</head>
<body class="bg-[#0f0f0f] text-white font-sans min-h-full">
    <nav class="bg-[#1a1a1a] border-b border-[#2a2a2a] sticky top-0 z-50">
        <div class="max-w-5xl mx-auto px-4">
            <div class="flex items-center justify-between h-14">
                <a href="/" class="flex items-center gap-1">
                    <span class="text-[#CCFF00] font-black text-xl tracking-tight">rezultati</span><span class="text-white font-black text-xl tracking-tight">.net</span>
                </a>
                <div class="flex items-center gap-1">
                    <a href="/" class="px-3 py-1.5 rounded text-sm font-semibold {{ request()->is('/') ? 'bg-[#CCFF00] text-black' : 'text-gray-400 hover:text-white' }} transition">
                        âš½ Fudbal
                    </a>
                    <a href="/kosarka" class="px-3 py-1.5 rounded text-sm font-semibold {{ request()->is('kosarka*') ? 'bg-[#CCFF00] text-black' : 'text-gray-400 hover:text-white' }} transition">
                        í¿€ Kosarka
                    </a>
                    <a href="/tenis" class="px-3 py-1.5 rounded text-sm font-semibold {{ request()->is('tenis*') ? 'bg-[#CCFF00] text-black' : 'text-gray-400 hover:text-white' }} transition">
                        í¾¾ Tenis
                    </a>
                </div>
                <div class="hidden md:flex items-center gap-3 text-sm">
                    <a href="/jucer" class="text-gray-400 hover:text-white transition">Jucer</a>
                    <span class="text-[#CCFF00] font-bold">Danas</span>
                    <a href="/sutra" class="text-gray-400 hover:text-white transition">Sutra</a>
                </div>
            </div>
        </div>
    </nav>
    <main class="max-w-5xl mx-auto px-4 py-4">@yield('content')</main>
    <footer class="border-t border-[#2a2a2a] mt-12 py-6 text-center text-gray-500 text-sm">
        <p>&copy; {{ date('Y') }} rezultati.net &mdash; Rezultati uzivo</p>
        <p class="mt-1 text-xs opacity-60">18+ | Kladenje moze biti stetno za zdravlje. Igrajte odgovorno.</p>
    </footer>
    @livewireScripts
</body>
</html>
