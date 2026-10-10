@php
use App\Models\Fixture;
// Key derby matchups [home_id, away_id, name]
$derbies = [
    [1506, 1507, '🔥 Dinamo vs Hajduk'],
    [1603, 1604, '⚡ Zvezda vs Partizan'],
    [1525, 1526, '💥 Sarajevo vs Željezničar'],
];
$nextDerby = null;
foreach ($derbies as [$homeId, $awayId, $name]) {
    $f = Fixture::where(function($q) use ($homeId, $awayId) {
            $q->where(function($q2) use ($homeId, $awayId) {
                $q2->where('home_team_id', $homeId)->where('away_team_id', $awayId);
            })->orWhere(function($q2) use ($homeId, $awayId) {
                $q2->where('home_team_id', $awayId)->where('away_team_id', $homeId);
            });
        })
        ->where('status_short', 'NS')
        ->where('kick_off', '>=', now())
        ->orderBy('kick_off')
        ->first();
    if ($f) {
        $nextDerby = ['fixture' => $f, 'name' => $name];
        break;
    }
}
@endphp

@if($nextDerby)
@php $kickoff = \Carbon\Carbon::parse($nextDerby['fixture']->kick_off); @endphp
<div class="bg-gradient-to-r from-[#1a1a1a] to-[#222] border border-[#CCFF00] border-opacity-30 rounded-xl p-4 mb-4"
     x-data="{
        diff: {{ $kickoff->diffInSeconds(now()) }},
        get days() { return Math.floor(this.diff / 86400) },
        get hours() { return Math.floor((this.diff % 86400) / 3600) },
        get mins() { return Math.floor((this.diff % 3600) / 60) },
        get secs() { return this.diff % 60 },
     }"
     x-init="setInterval(() => { if(diff > 0) diff-- }, 1000)">
    <div class="text-xs font-bold text-[#CCFF00] uppercase tracking-wider mb-2">{{ $nextDerby['name'] }}</div>
    <div class="text-xs text-gray-500 mb-3">{{ $kickoff->format('d.m.Y H:i') }} &bull; {{ $nextDerby['fixture']->league?->name }}</div>
    <div class="flex gap-2">
        <div class="flex-1 text-center bg-[#0f0f0f] rounded-lg py-2">
            <div class="text-2xl font-black text-white" x-text="String(days).padStart(2,'0')">00</div>
            <div class="text-[10px] text-gray-500">Dana</div>
        </div>
        <div class="flex-1 text-center bg-[#0f0f0f] rounded-lg py-2">
            <div class="text-2xl font-black text-white" x-text="String(hours).padStart(2,'0')">00</div>
            <div class="text-[10px] text-gray-500">Sati</div>
        </div>
        <div class="flex-1 text-center bg-[#0f0f0f] rounded-lg py-2">
            <div class="text-2xl font-black text-white" x-text="String(mins).padStart(2,'0')">00</div>
            <div class="text-[10px] text-gray-500">Min</div>
        </div>
        <div class="flex-1 text-center bg-[#0f0f0f] rounded-lg py-2">
            <div class="text-2xl font-black text-[#CCFF00]" x-text="String(secs).padStart(2,'0')">00</div>
            <div class="text-[10px] text-gray-500">Sek</div>
        </div>
    </div>
</div>
@endif
