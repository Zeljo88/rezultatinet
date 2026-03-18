<div>
    {{-- Hero --}}
    <div class="mb-6">
        <h1 class="text-2xl font-black text-white">Balkanski igrači <span class="text-[#CCFF00]">u inozemstvu</span></h1>
        <p class="text-gray-500 text-sm mt-1">Pratite naše zvijezde u top europskim i svjetskim ligama</p>
        <div class="flex gap-4 mt-3 text-sm">
            <span class="text-[#CCFF00] font-bold">{{ $players->count() }}</span><span class="text-gray-500">aktivnih igrača</span>
            <span class="text-[#CCFF00] font-bold">{{ $players->pluck('current_league')->unique()->count() }}</span><span class="text-gray-500">liga</span>
            <span class="text-[#CCFF00] font-bold">4</span><span class="text-gray-500">države</span>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-2 mb-5">
        {{-- Nationality --}}
        <div class="flex gap-1">
            @foreach(['all'=>'Svi','HR'=>'🇭🇷 HR','RS'=>'🇷🇸 SRB','BA'=>'🇧🇦 BiH','MK'=>'🇲🇰 MK'] as $val => $label)
            <button wire:click="$set('nationality','{{ $val }}')"
                class="px-3 py-1.5 rounded-full text-xs font-bold transition cursor-pointer {{ $nationality === $val ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">
                {{ $label }}
            </button>
            @endforeach
        </div>
        {{-- Position --}}
        <div class="flex gap-1">
            @foreach(['all'=>'Sve poz.','Goalkeeper'=>'Golmani','Defender'=>'Obrana','Midfielder'=>'Vez','Forward'=>'Napad'] as $val => $label)
            <button wire:click="$set('position','{{ $val }}')"
                class="px-3 py-1.5 rounded-full text-xs font-bold transition cursor-pointer {{ $position === $val ? 'bg-[#CCFF00] text-black' : 'bg-[#1a1a1a] text-gray-400 hover:text-white border border-[#2a2a2a]' }}">
                {{ $label }}
            </button>
            @endforeach
        </div>
    </div>

    @if($players->isEmpty())
        <div class="bg-[#1a1a1a] border border-[#2a2a2a] rounded-xl p-12 text-center">
            <div class="text-5xl mb-3">🔍</div>
            <p class="text-gray-400">Nema igrača za odabrane filtere.</p>
        </div>
    @else
        {{-- Featured players --}}
        @php $featured = $players->where('is_featured', true); $rest = $players->where('is_featured', false); @endphp

        @if($featured->isNotEmpty() && $nationality === 'all' && $position === 'all')
        <div class="mb-6">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">⭐ Istaknuti igrači</p>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                @foreach($featured as $player)
                <a href="/igraci/{{ $player->slug }}" class="bg-[#1a1a1a] border border-[#2a2a2a] hover:border-[#CCFF00] rounded-xl p-4 text-center transition cursor-pointer group">
                    <div class="relative mb-3">
                        @if($player->current_club_logo)
                            <img src="{{ $player->current_club_logo }}" class="w-5 h-5 object-contain absolute top-0 left-0" alt="">
                        @endif
                        <span class="absolute top-0 right-0 text-sm">{{ $player->country_flag }}</span>
                        <img src="{{ $player->photo_url }}" class="w-16 h-16 rounded-full mx-auto object-cover bg-[#2a2a2a]" alt="{{ $player->name }}" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                    </div>
                    <p class="font-bold text-white text-sm group-hover:text-[#CCFF00] transition">{{ $player->name }}</p>
                    <p class="text-gray-500 text-xs mt-0.5">{{ $player->current_club }}</p>
                    <span class="inline-block mt-1 text-[10px] bg-[#2a2a2a] text-gray-400 px-2 py-0.5 rounded-full">{{ $player->position_label }}</span>
                </a>
                @endforeach
            </div>
        </div>
        @endif

        {{-- All players list --}}
        <div class="border border-[#2a2a2a] rounded-xl overflow-hidden">
            @php $list = ($nationality !== 'all' || $position !== 'all') ? $players : $rest; @endphp
            @if($list->isEmpty() && $featured->isNotEmpty())
                <div class="p-4 text-center text-gray-500 text-sm">Svi igrači prikazani gore.</div>
            @else
            @foreach($list as $i => $player)
            <a href="/igraci/{{ $player->slug }}"
               class="flex items-center gap-3 px-4 py-3 hover:bg-[#222] transition border-b border-[#2a2a2a] last:border-0 cursor-pointer {{ $i % 2 === 0 ? 'bg-[#0f0f0f]' : 'bg-[#161616]' }}">
                <img src="{{ $player->photo_url }}" class="w-9 h-9 rounded-full object-cover bg-[#2a2a2a] flex-shrink-0" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-semibold text-white text-sm">{{ $player->name }}</span>
                        <span class="text-xs">{{ $player->country_flag }}</span>
                    </div>
                    <div class="text-xs text-gray-500">{{ $player->current_club }} &bull; {{ $player->current_league }}</div>
                </div>
                @if($player->current_club_logo)
                    <img src="{{ $player->current_club_logo }}" class="w-7 h-7 object-contain flex-shrink-0" alt="">
                @endif
                <span class="text-[10px] bg-[#2a2a2a] text-gray-400 px-2 py-0.5 rounded-full flex-shrink-0">{{ $player->position_label }}</span>
                <span class="text-gray-600 text-xs">→</span>
            </a>
            @endforeach
            @endif
        </div>
    @endif
</div>
