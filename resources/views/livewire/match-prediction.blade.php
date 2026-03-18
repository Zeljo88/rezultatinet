<div class="bg-[#1a1a2e] border border-white/5 rounded-xl p-5 mb-4">
    <h3 class="text-sm font-bold uppercase tracking-wider mb-4" style="color:#CCFF00">Ko ce pobijediti?</h3>

    @if(!$hasVoted and !$actualResult)
        <div class="grid grid-cols-3 gap-2">
            <button wire:click="vote('home')" wire:loading.attr="disabled" wire:target="vote"
                class="flex flex-col items-center justify-center gap-1 py-3 px-2 rounded-lg bg-white/5 hover:bg-[#CCFF00] hover:text-black text-white font-bold text-sm transition-all duration-200 cursor-pointer border border-white/10 hover:border-[#CCFF00]">
                <span class="text-xs font-normal text-gray-400 truncate w-full text-center">{{ $homeTeam }}</span>
                <span>1</span>
            </button>
            <button wire:click="vote('draw')" wire:loading.attr="disabled" wire:target="vote"
                class="flex flex-col items-center justify-center gap-1 py-3 px-2 rounded-lg bg-white/5 hover:bg-[#CCFF00] hover:text-black text-white font-bold text-sm transition-all duration-200 cursor-pointer border border-white/10 hover:border-[#CCFF00]">
                <span class="text-xs font-normal text-gray-400 text-center">Remi</span>
                <span>X</span>
            </button>
            <button wire:click="vote('away')" wire:loading.attr="disabled" wire:target="vote"
                class="flex flex-col items-center justify-center gap-1 py-3 px-2 rounded-lg bg-white/5 hover:bg-[#CCFF00] hover:text-black text-white font-bold text-sm transition-all duration-200 cursor-pointer border border-white/10 hover:border-[#CCFF00]">
                <span class="text-xs font-normal text-gray-400 truncate w-full text-center">{{ $awayTeam }}</span>
                <span>2</span>
            </button>
        </div>
        <div wire:loading wire:target="vote" class="mt-3 text-center text-xs text-gray-400">
            <span class="animate-pulse">Glasanje...</span>
        </div>

    @elseif($actualResult and $stats['total'] > 0)
        @php
            $winningPct = $stats[$actualResult];
            $userWon = ($hasVoted and $userVote === $actualResult);
            $labels = ['home' => $homeTeam, 'draw' => 'Remi', 'away' => $awayTeam];
        @endphp

        <div class="mb-4 rounded-lg p-3 text-center {{ $userWon ? 'bg-[#CCFF00]/10 border border-[#CCFF00]/30' : 'bg-white/5 border border-white/10' }}">
            @if($hasVoted)
                @if($userWon)
                    <div class="text-[#CCFF00] font-bold text-sm mb-1">Pogodio/la si!</div>
                @else
                    <div class="text-gray-400 font-bold text-sm mb-1">Nisi pogodio/la</div>
                @endif
            @endif
            <div class="text-white font-bold text-lg">{{ $winningPct }}% navijaca je pogodilo!</div>
            <div class="text-gray-400 text-xs mt-1">Pobijedio: <span class="text-white font-semibold">{{ $labels[$actualResult] }}</span></div>
        </div>

        <div wire:key="bars-post-{{ $fixtureId }}"
             x-data="{ show: false }"
             x-init="$nextTick(function(){ setTimeout(function(){ show = true }, 50) })"
             class="space-y-3">
            @foreach(['home' => $homeTeam, 'draw' => 'Remi', 'away' => $awayTeam] as $key => $label)
                @php $isWinner = ($key === $actualResult); $isUserVote = ($hasVoted and $userVote === $key); @endphp
                <div>
                    <div class="flex justify-between text-xs mb-1">
                        <span class="{{ $isWinner ? 'text-[#CCFF00] font-bold' : 'text-gray-300' }} truncate max-w-[140px]">
                            {{ $label }}
                            @if($isWinner) <span>&#10003;</span> @endif
                            @if($isUserVote and !$isWinner) <span class="text-gray-500 ml-1">(tvoj glas)</span> @endif
                            @if($isUserVote and $isWinner) <span class="text-[#CCFF00] ml-1">(tvoj glas)</span> @endif
                        </span>
                        <span class="font-bold {{ $isWinner ? 'text-[#CCFF00]' : 'text-gray-400' }}">{{ $stats[$key] }}%</span>
                    </div>
                    <div class="h-2 bg-white/10 rounded-full overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-700 ease-out {{ $isWinner ? 'bg-[#CCFF00]' : 'bg-white/20' }}"
                             x-bind:style="show ? 'width: {{ $stats[$key] }}%' : 'width: 0%'"></div>
                    </div>
                </div>
            @endforeach
            <div class="text-center text-xs text-gray-500 mt-2">
                {{ $stats['total'] }} {{ $stats['total'] === 1 ? 'glas' : ($stats['total'] < 5 ? 'glasa' : 'glasova') }}
            </div>
        </div>

    @else
        @php $maxPct = max($stats['home'], $stats['draw'], $stats['away']); @endphp
        <div wire:key="bars-live-{{ $fixtureId }}"
             x-data="{ show: false }"
             x-init="$nextTick(function(){ setTimeout(function(){ show = true }, 50) })"
             class="space-y-3">
            @foreach(['home' => $homeTeam, 'draw' => 'Remi', 'away' => $awayTeam] as $key => $label)
                <div>
                    <div class="flex justify-between text-xs mb-1">
                        <span class="text-gray-300 truncate max-w-[120px]">{{ $label }}
                            @if($userVote === $key) <span class="text-[#CCFF00]">&#10003;</span> @endif
                        </span>
                        <span class="font-bold {{ ($stats[$key] === $maxPct and $maxPct > 0) ? 'text-[#CCFF00]' : 'text-gray-300' }}">{{ $stats[$key] }}%</span>
                    </div>
                    <div class="h-2 bg-white/10 rounded-full overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-700 ease-out {{ ($stats[$key] === $maxPct and $maxPct > 0) ? 'bg-[#CCFF00]' : 'bg-white/30' }}"
                             x-bind:style="show ? 'width: {{ $stats[$key] }}%' : 'width: 0%'"></div>
                    </div>
                </div>
            @endforeach
            <div class="text-center text-xs text-gray-500 mt-2">
                {{ $stats['total'] }} {{ $stats['total'] === 1 ? 'glas' : ($stats['total'] < 5 ? 'glasa' : 'glasova') }}
            </div>
        </div>
    @endif
</div>
