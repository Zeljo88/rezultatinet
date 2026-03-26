@extends('admin.layout')

@section('title', 'Blog postovi')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold text-gray-800">Blog postovi</h1>
    <a href="{{ route('admin.posts.create') }}"
       class="bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg px-5 py-2.5 text-sm transition flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Novi post
    </a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    @if($posts->isEmpty())
        <div class="text-center py-16 text-gray-400">
            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <p>Nema postova. <a href="{{ route('admin.posts.create') }}" class="text-blue-600 hover:underline">Kreirajte prvi!</a></p>
        </div>
    @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600 w-12">#</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Naslov</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600 hidden md:table-cell">Datum</th>
                    <th class="text-center px-4 py-3 font-semibold text-gray-600">Status</th>
                    <th class="text-right px-4 py-3 font-semibold text-gray-600">Akcije</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($posts as $post)
                <tr class="hover:bg-gray-50 transition">
                    <td class="px-4 py-3 text-gray-400">{{ $post->id }}</td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-800">{{ $post->title }}</div>
                        <div class="text-xs text-gray-400 mt-0.5">/blog/{{ $post->slug }}</div>
                    </td>
                    <td class="px-4 py-3 text-gray-500 hidden md:table-cell">
                        {{ $post->created_at->format('d.m.Y H:i') }}
                    </td>
                    <td class="px-4 py-3 text-center">
                        @if($post->published)
                            <span class="inline-flex items-center gap-1 bg-green-100 text-green-700 text-xs font-medium px-2.5 py-1 rounded-full">
                                <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>
                                Objavljeno
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 bg-yellow-100 text-yellow-700 text-xs font-medium px-2.5 py-1 rounded-full">
                                <span class="w-1.5 h-1.5 bg-yellow-500 rounded-full"></span>
                                Nacrt
                            </span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <a href="{{ route('blog.post', $post->slug) }}" target="_blank"
                               class="text-gray-400 hover:text-blue-600 transition p-1.5 rounded hover:bg-blue-50" title="Pregled">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </a>
                            <a href="{{ route('admin.posts.edit', $post->id) }}"
                               class="text-gray-400 hover:text-indigo-600 transition p-1.5 rounded hover:bg-indigo-50" title="Uredi">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </a>
                            <form method="POST" action="{{ route('admin.posts.destroy', $post->id) }}"
                                  onsubmit="return confirm('Sigurno obrisati post?')" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-gray-400 hover:text-red-600 transition p-1.5 rounded hover:bg-red-50" title="Obriši">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

        @if($posts->hasPages())
        <div class="px-4 py-3 border-t border-gray-200">
            {{ $posts->links() }}
        </div>
        @endif
    @endif
</div>
@endsection
