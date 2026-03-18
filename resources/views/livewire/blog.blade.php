<div>
    <div class="mb-6">
        <h1 class="text-2xl font-black text-white">Blog <span class="text-[#CCFF00]">& vijesti</span></h1>
        <p class="text-gray-500 text-sm mt-1">Analize, vodiči i vijesti iz svijeta sporta</p>
    </div>

    @if($posts->isEmpty())
        <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-12 text-center">
            <div class="text-5xl mb-3">📝</div>
            <p class="text-gray-400">Uskoro — prvi članci dolaze!</p>
        </div>
    @else
        <div class="grid gap-4">
            @foreach($posts as $post)
            <a href="/blog/{{ $post->slug }}" class="block bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-5 hover:border-[#CCFF00] transition cursor-pointer">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        @if($post->keyword)
                            <span class="text-[10px] font-bold text-[#CCFF00] uppercase tracking-wider">{{ $post->keyword }}</span>
                        @endif
                        <h2 class="text-lg font-bold text-white mt-1 mb-2">{{ $post->title }}</h2>
                        <p class="text-gray-500 text-sm leading-relaxed">{{ $post->excerpt }}</p>
                    </div>
                    <span class="text-[#CCFF00] text-xl flex-shrink-0">→</span>
                </div>
                <div class="mt-3 text-xs text-gray-600">
                    {{ $post->created_at->format('d.m.Y') }}
                </div>
            </a>
            @endforeach
        </div>
    @endif
</div>
