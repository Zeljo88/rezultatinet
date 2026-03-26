<!DOCTYPE html>
<html lang="bs" class="h-full bg-gray-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — rezultati.net</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full flex items-center justify-center">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-lg p-8">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Admin Panel</h1>
            <p class="text-gray-500 text-sm mt-1">rezultati.net</p>
        </div>

        @if ($errors->has('password'))
            <div class="mb-4 bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm">
                {{ $errors->first('password') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.login.post') }}">
            @csrf
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Lozinka</label>
                <input type="password" name="password" autofocus
                    class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    placeholder="Unesite admin lozinku">
            </div>
            <button type="submit"
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg px-4 py-2.5 text-sm transition">
                Prijavi se
            </button>
        </form>
    </div>
</body>
</html>
