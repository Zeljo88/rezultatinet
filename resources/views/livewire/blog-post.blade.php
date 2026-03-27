@php
$articleSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'NewsArticle',
    'headline' => $post->title,
    'description' => $post->meta_description ?? '',
    'datePublished' => $post->created_at->toIso8601String(),
    'dateModified' => $post->updated_at->toIso8601String(),
    'author' => ['@type' => 'Organization', 'name' => 'rezultati.net', 'url' => 'https://rezultati.net'],
    'publisher' => ['@type' => 'Organization', 'name' => 'rezultati.net', 'url' => 'https://rezultati.net'],
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => 'https://rezultati.net/blog/' . $post->slug],
    'keywords' => $post->keyword ?? '',
    'inLanguage' => 'bs',
];

$articleSchema['image'] = $post->getOgImageUrl();

$kw = strtolower($post->keyword ?? '');
$titleLower = strtolower($post->title ?? '');
if (str_contains($kw, 'gdje gledati') || str_contains($kw, 'gdje')) {
    $category = '📺 TV Vodič';
} elseif (str_contains($kw, 'champions') || str_contains($titleLower, 'champions')) {
    $category = '🏆 Champions Liga';
} elseif (str_contains($kw, 'hnl') || str_contains($titleLower, 'hnl')) {
    $category = '⚽ HNL';
} else {
    $category = '⚽ Fudbal';
}
$readTime = max(1, (int) ceil(str_word_count(strip_tags($post->content ?? '')) / 200));
@endphp
<script type="application/ld+json">
{!! json_encode($articleSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
<div>
    <a href="/blog" class="inline-flex items-center gap-2 text-gray-400 hover:text-white text-sm mb-4 transition">
        &larr; Nazad na blog
    </a>

    <article class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-6">
        <div class="flex items-center gap-3 mb-3">
            <span class="text-xs font-bold text-[#CCFF00] uppercase tracking-wider">{{ $category }}</span>
            <span class="text-xs text-gray-600">•</span>
            <span class="text-xs text-gray-600">{{ $readTime }} min čitanja</span>
            <span class="text-xs text-gray-600">•</span>
            <span class="text-xs text-gray-600">{{ $post->created_at->format('d. M Y.') }}</span>
        </div>
        <h1 class="text-2xl font-black text-white mb-6">{{ $post->title }}</h1>

        @if($post->featured_image)
            <img src="{{ asset($post->featured_image) }}" alt="{{ $post->title }}" class="w-full rounded-lg mb-6 object-cover max-h-96" width="1200" height="630" fetchpriority="high">
        @else
            <img src="{{ asset('images/og/football-default.jpg') }}" alt="{{ $post->title }}" class="w-full rounded-lg mb-6 object-cover max-h-96" width="1200" height="630" fetchpriority="high">
        @endif

        <div class="text-gray-300 leading-relaxed space-y-4
            [&_h1]:hidden
            [&_h2]:text-white [&_h2]:text-xl [&_h2]:font-bold [&_h2]:mt-5 [&_h2]:mb-2 [&_h2]:border-b [&_h2]:border-[#2a2a2a] [&_h2]:pb-1
            [&_h3]:text-[#CCFF00] [&_h3]:text-base [&_h3]:font-bold [&_h3]:mt-4 [&_h3]:mb-1
            [&_p]:mb-3 [&_p]:leading-7
            [&_a]:text-[#CCFF00] [&_a]:underline [&_a]:hover:text-white
            [&_strong]:text-white [&_strong]:font-bold">
            {!! $post->content !!}
        </div>
    </article>


    {{-- Facebook Follow CTA --}}
    <div class="mt-10 mb-2 rounded-2xl overflow-hidden border border-[#1877F2]/30 bg-gradient-to-br from-[#0d1b2e] to-[#111] relative">
        <div class="absolute inset-0 opacity-5" style="background-image: repeating-linear-gradient(45deg,#1877F2 0,#1877F2 1px,transparent 0,transparent 50%);background-size:16px 16px"></div>
        <div class="relative flex flex-col sm:flex-row items-center gap-6 p-7">
            {{-- Facebook icon --}}
            <div class="shrink-0 w-16 h-16 bg-[#1877F2] rounded-2xl flex items-center justify-center shadow-lg shadow-blue-900/40">
                <svg class="w-9 h-9" viewBox="0 0 24 24" fill="white">
                    <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.513c-1.491 0-1.956.93-1.956 1.884v2.25h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                </svg>
            </div>
            {{-- Text --}}
            <div class="flex-1 text-center sm:text-left">
                <p class="text-[#CCFF00] text-xs font-bold uppercase tracking-widest mb-1">Ostani u toku</p>
                <h3 class="text-white text-xl font-black mb-1">Prati nas na Facebooku</h3>
                <p class="text-gray-400 text-sm leading-relaxed">Budi prvi koji sazna rezultate, recape i vijesti iz svijeta fudbala.</p>
            </div>
            {{-- CTA Button --}}
            <div class="shrink-0">
                <a href="https://www.facebook.com/rezultatiNet"
                   target="_blank" rel="noopener"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-[#1877F2] hover:bg-[#1565d8] text-white font-bold rounded-xl transition-all duration-200 shadow-lg shadow-blue-900/30 hover:shadow-blue-800/50 text-sm whitespace-nowrap">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.513c-1.491 0-1.956.93-1.956 1.884v2.25h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                    </svg>
                    Prati @rezultatiNet →
                </a>
            </div>
        </div>
    </div>

    {{-- Social Share --}}
    @php
        $shareUrl = urlencode('https://rezultati.net/blog/' . $post->slug);
        $shareTitle = urlencode($post->title . ' | rezultati.net');
        $rawUrl = 'https://rezultati.net/blog/' . $post->slug;
    @endphp
    <div class="share-section mt-8 pt-6 border-t border-gray-700">
        <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-4">Podijeli članak</h3>
        <div class="grid grid-cols-2 sm:flex sm:flex-wrap gap-3">

            {{-- Facebook --}}
            <a href="https://www.facebook.com/sharer/sharer.php?u={{ $shareUrl }}"
               target="_blank" rel="noopener"
               class="flex items-center justify-center gap-2 px-4 py-2.5 bg-[#1877F2] text-white text-sm font-bold rounded-lg hover:opacity-90 transition">
                <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.513c-1.491 0-1.956.93-1.956 1.884v2.25h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                </svg>
                Facebook
            </a>

            {{-- WhatsApp --}}
            <a href="https://wa.me/?text={{ $shareTitle }}%20{{ $shareUrl }}"
               target="_blank" rel="noopener"
               class="flex items-center justify-center gap-2 px-4 py-2.5 bg-[#25D366] text-white text-sm font-bold rounded-lg hover:opacity-90 transition">
                <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                </svg>
                WhatsApp
            </a>

            {{-- Twitter/X --}}
            <a href="https://twitter.com/intent/tweet?url={{ $shareUrl }}&text={{ $shareTitle }}"
               target="_blank" rel="noopener"
               class="flex items-center justify-center gap-2 px-4 py-2.5 bg-black text-white text-sm font-bold rounded-lg hover:opacity-80 transition border border-gray-700">
                <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.73-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                </svg>
                X / Twitter
            </a>

            {{-- Telegram --}}
            <a href="https://t.me/share/url?url={{ $shareUrl }}&text={{ $shareTitle }}"
               target="_blank" rel="noopener"
               class="flex items-center justify-center gap-2 px-4 py-2.5 bg-[#26A5E4] text-white text-sm font-bold rounded-lg hover:opacity-90 transition">
                <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/>
                </svg>
                Telegram
            </a>

            {{-- Copy Link --}}
            <button onclick="copyBlogLink('{{ $rawUrl }}')"
                    id="copy-link-btn"
                    class="flex items-center justify-center gap-2 px-4 py-2.5 bg-[#CCFF00] text-black text-sm font-bold rounded-lg hover:opacity-90 transition col-span-2 sm:col-span-1">
                <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                </svg>
                <span id="copy-link-text">Kopiraj link</span>
            </button>

        </div>
    </div>

    <script>
    function copyBlogLink(url) {
        navigator.clipboard.writeText(url).then(function() {
            var btn = document.getElementById('copy-link-btn');
            var txt = document.getElementById('copy-link-text');
            if (txt) {
                txt.textContent = 'Kopirano!';
                btn.classList.add('opacity-75');
                setTimeout(function() {
                    txt.textContent = 'Kopiraj link';
                    btn.classList.remove('opacity-75');
                }, 2000);
            }
        }).catch(function() {
            var el = document.createElement('textarea');
            el.value = url;
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
            var txt = document.getElementById('copy-link-text');
            if (txt) {
                txt.textContent = 'Kopirano!';
                setTimeout(function() { txt.textContent = 'Kopiraj link'; }, 2000);
            }
        });
    }
    </script>
</div>
