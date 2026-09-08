@props(['status'])

{{--
    Renders the colored status dot for a website's Website::statusKey() - the
    single source of truth for status colors/labels, so every list/detail view
    (Situs, Dashboard, detail situs) stays in sync when a status is added/changed.

    Red is reserved for "down right now", in either flavour: a plain outage and one
    that degraded into itself look equally urgent to whoever is on call. What
    separates them is the label, which is what an operator reads before deciding
    who to call - the hosting provider or the network side.
--}}
@php
    $config = [
        'up' => ['class' => 'bg-emerald-500', 'label' => 'Online'],
        'spike_moderate' => ['class' => 'bg-yellow-400', 'label' => 'Lonjakan sedang - masih online tapi lebih lambat dari biasanya'],
        'spike_severe' => ['class' => 'bg-orange-500', 'label' => 'Lonjakan parah - masih online tapi jauh lebih lambat dari biasanya'],
        'attack' => ['class' => 'bg-red-500', 'label' => 'Down setelah melambat - dugaan kelebihan beban / serangan DDoS, perlu konfirmasi access log'],
        'down' => ['class' => 'bg-red-500', 'label' => 'Down - berhenti merespons tanpa didahului lonjakan'],
        'offline' => ['class' => 'bg-zinc-400 dark:bg-zinc-500', 'label' => 'Offline - down lebih dari 24 jam'],
        'internal' => ['class' => 'bg-amber-500', 'label' => 'Terbatas - kemungkinan hanya bisa diakses dari jaringan internal/instansi'],
        'unknown' => ['class' => 'bg-zinc-300 dark:bg-zinc-600', 'label' => 'Belum diketahui'],
    ][$status] ?? ['class' => 'bg-zinc-300 dark:bg-zinc-600', 'label' => 'Belum diketahui'];
@endphp

<span
    title="{{ $config['label'] }}"
    {{ $attributes->merge(['class' => "h-2.5 w-2.5 shrink-0 rounded-full {$config['class']}"]) }}
></span>
