<div>
    <a href="/igraci/balkan" class="inline-flex items-center gap-2 text-gray-400 hover:text-white text-sm mb-4 transition">
        &larr; Balkanski igrači
    </a>

    {{-- Player header --}}
    <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-6 mb-4">
        <div class="flex items-start gap-5">
            <img src="{{ $player->photo_url }}" class="w-24 h-24 rounded-full object-cover bg-[#2a2a2a] flex-shrink-0" alt="{{ $player->name }}" onerror="this.src='https://media.api-sports.io/football/players/0.png'">
            <div class="flex-1">
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-2xl">{{ $player->country_flag }}</span>
                    <h1 class="text-2xl font-black text-white">{{ $player->name }}</h1>
                </div>
                <div class="flex items-center gap-2 mb-3">
                    @if($player->current_club_logo)
                        <img src="{{ $player->current_club_logo }}" class="w-6 h-6 object-contain" alt="">
                    @endif
                    <span class="text-gray-300 font-semibold">{{ $player->current_club }}</span>
                    <span class="text-gray-500 text-sm">&bull; {{ $player->current_league }}</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="bg-[#2a2a2a] text-gray-300 text-xs px-3 py-1 rounded-full">{{ $player->position_label }}</span>
                    <span class="bg-[#2a2a2a] text-gray-300 text-xs px-3 py-1 rounded-full">{{ $player->country_name }}</span>
                    @if($player->date_of_birth)
                        <span class="bg-[#2a2a2a] text-gray-300 text-xs px-3 py-1 rounded-full">
                            {{ \Carbon\Carbon::parse($player->date_of_birth)->format('d.m.Y') }}
                            ({{ \Carbon\Carbon::parse($player->date_of_birth)->age }} god.)
                        </span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Bio --}}
    @if($player->bio)
    <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-5 mb-4">
        <h2 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-3">O igraču</h2>
        <p class="text-gray-300 text-sm leading-relaxed">{{ $player->bio }}</p>
    </div>
    @endif

    {{-- Share --}}
    <div class="flex items-center gap-3 mt-2">
        @php
            $url = urlencode('https://rezultati.net/igraci/' . $player->slug);
            $text = urlencode($player->name . ' | rezultati.net');
        @endphp
        <a href="https://wa.me/?text={{ $text }}%20{{ $url }}" target="_blank"
           class="flex items-center gap-2 px-4 py-2 bg-[#25D366] text-white text-sm font-bold rounded-lg hover:opacity-90 transition">
            📱 WhatsApp
        </a>
        <a href="/igraci/balkan" class="px-4 py-2 bg-[#1a1a1a] border border-[#2a2a2a] text-gray-300 text-sm font-bold rounded-lg hover:bg-[#222] transition">
            ← Svi igrači
        </a>
    </div>
</div>
