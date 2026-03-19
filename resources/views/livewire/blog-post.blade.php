<div>
    <a href="/blog" class="inline-flex items-center gap-2 text-gray-400 hover:text-white text-sm mb-4 transition">
        &larr; Nazad na blog
    </a>

    <article class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-6">
        @if($post->keyword)
            <span class="text-[10px] font-bold text-[#CCFF00] uppercase tracking-wider">{{ $post->keyword }}</span>
        @endif
        <h1 class="text-2xl font-black text-white mt-2 mb-1">{{ $post->title }}</h1>
        <div class="text-xs text-gray-600 mb-6">{{ $post->created_at->format('d.m.Y') }}</div>

        <div class="prose prose-invert prose-sm max-w-none text-gray-300 leading-relaxed space-y-4">
            {!! $post->content !!}
        </div>
    </article>

    {{-- Share --}}
    <div class="flex items-center gap-3 mt-4">
        @php
            $url = urlencode('https://rezultati.net/blog/' . $post->slug);
            $text = urlencode($post->title . ' | rezultati.net');
        @endphp
        <a href="https://wa.me/?text={{ $text }}%20{{ $url }}" target="_blank"
           class="flex items-center gap-2 px-4 py-2 bg-[#25D366] text-white text-sm font-bold rounded-lg hover:opacity-90 transition">
            📱 Podijeli na WhatsApp
        </a>
        <button onclick="navigator.clipboard.writeText('https://rezultati.net/blog/{{ $post->slug }}').then(() => alert('Link kopiran!'))"
           class="px-4 py-2 bg-[#2a2a2a] text-gray-300 text-sm font-bold rounded-lg hover:bg-[#333] transition">
            🔗 Kopiraj link
        </button>
    </div>
</div>
