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
        ->where('kick_off', '>', now('UTC'))
        ->orderBy('kick_off')
        ->first();
    if ($f) {
        $nextDerby = ['fixture' => $f, 'name' => $name];
        break;
    }
}
@endphp

@if($nextDerby)
@php $kickoff = \App\Support\MatchTime::local($nextDerby['fixture']->kick_off); @endphp
<div class="bg-gradient-to-r from-[#1a1a1a] to-[#222] border border-[#CCFF00] border-opacity-30 rounded-xl p-4 mb-4"
     x-data="{
        kickoffMs: {{ $kickoff->getTimestamp() * 1000 }},
        serverMs: {{ now('UTC')->getTimestampMs() }},
        anchor: 0,
        diff: 0,
        timer: null,
        request: null,
        disposed: false,
        resume: null,
        restored: null,
        tick() {
            const remaining = this.kickoffMs - (this.serverMs + Math.max(0, performance.now() - this.anchor));
            this.diff = Number.isFinite(remaining) ? Math.max(0, Math.ceil(remaining / 1000)) : 0;
        },
        async resync() {
            this.tick();
            if (document.hidden || this.request || this.disposed) return;
            // One existing-page HEAD on resume, never polling or a new endpoint.
            // Needed on platforms whose monotonic clock pauses during OS sleep.
            const controller = new AbortController();
            this.request = controller;
            const timeout = setTimeout(() => controller.abort(), 10000);
            try {
                const response = await fetch(location.href, {
                    method: 'HEAD', cache: 'no-store', credentials: 'same-origin',
                    redirect: 'error', signal: controller.signal,
                });
                const received = performance.now();
                const server = Date.parse(response.headers.get('Date'));
                if (response.ok && Number.isFinite(server) && !controller.signal.aborted && !this.disposed) {
                    this.serverMs = server;
                    this.anchor = received;
                    this.tick();
                }
            } catch (_) {
                // Offline: retain the monotonic anchor; retry on the next resume.
            } finally {
                clearTimeout(timeout);
                this.request = null;
            }
        },
        init() {
            // Include deferred Alpine initialization / response download time,
            // without ever using the browser wall clock or its initial skew.
            const navigation = performance.getEntriesByType('navigation')[0];
            this.anchor = navigation ? navigation.responseStart : performance.now();
            this.tick();
            // Keep ticking at zero: a later server resync may correct the anchor.
            this.timer = setInterval(() => this.tick(), 1000);
            this.resume = () => { if (!document.hidden) this.resync() };
            this.restored = event => { if (event.persisted) this.resync() };
            document.addEventListener('visibilitychange', this.resume);
            window.addEventListener('pageshow', this.restored);
        },
        destroy() {
            this.disposed = true;
            clearInterval(this.timer);
            this.request?.abort();
            document.removeEventListener('visibilitychange', this.resume);
            window.removeEventListener('pageshow', this.restored);
        },
        get days() { return Math.floor(this.diff / 86400) },
        get hours() { return Math.floor((this.diff % 86400) / 3600) },
        get mins() { return Math.floor((this.diff % 3600) / 60) },
        get secs() { return this.diff % 60 },
     }"
     x-cloak x-show="diff > 0">
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
