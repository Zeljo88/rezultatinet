@props(['adSlot' => 'sidebar-1', 'extraClass' => ''])

@if(!empty(trim($slot)))
<div
    class="affiliate-banner {{ $extraClass }}"
    data-slot="{{ $adSlot }}"
    style="
        border: 1px dashed #3a3a3a;
        border-radius: 8px;
        padding: 10px 12px;
        text-align: center;
        background: #111;
        position: relative;
        min-height: 60px;
        display: flex;
        align-items: center;
        justify-content: center;
    "
>
    <span style="
        position: absolute;
        top: -9px;
        left: 10px;
        background: #111;
        color: #555;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        padding: 0 6px;
    ">Oglas</span>
    {{ $slot }}
</div>
@endif
