<!DOCTYPE html>
<html lang="bs" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin') — rezultati.net</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full">
    <nav class="bg-gray-900 text-white px-6 py-3 flex items-center justify-between shadow">
        <div class="flex items-center gap-4">
            <span class="font-bold text-lg">rezultati.net</span>
            <span class="text-gray-400 text-sm">Admin</span>
        </div>
        <div class="flex items-center gap-4">
            <a href="{{ route('admin.posts.index') }}" class="text-sm text-gray-300 hover:text-white">Blog postovi</a>
            <form method="POST" action="{{ route('admin.logout') }}" class="inline">
                @csrf
                <button type="submit" class="text-sm text-gray-400 hover:text-red-400 transition">Odjava</button>
            </form>
        </div>
    </nav>

    <main class="max-w-6xl mx-auto px-4 py-8">
        @if(session('success'))
            <div class="mb-6 bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 text-sm flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm">
                {{ session('error') }}
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
