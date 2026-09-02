@props(['status'])

{{--
    Renders the colored status dot for a website's Website::statusKey() - the
    single source of truth for status colors/labels, so every list/detail view
    (Situs, Dashboard, detail situs) stays in sync when a status is added/changed.
--}}
@php
    $config = [
        'up' => ['class' => 'bg-emerald-500', 'label' => 'Up'],
        'down' => ['class' => 'bg-red-500', 'label' => 'Down'],
        'offline' => ['class' => 'bg-zinc-400 dark:bg-zinc-500', 'label' => 'Offline - down lebih dari 24 jam'],
        'internal' => ['class' => 'bg-amber-500', 'label' => 'Terbatas - kemungkinan hanya bisa diakses dari jaringan internal/instansi'],
        'unknown' => ['class' => 'bg-zinc-300 dark:bg-zinc-600', 'label' => 'Belum diketahui'],
    ][$status] ?? ['class' => 'bg-zinc-300 dark:bg-zinc-600', 'label' => 'Belum diketahui'];
@endphp

<span
    title="{{ $config['label'] }}"
    {{ $attributes->merge(['class' => "h-2.5 w-2.5 shrink-0 rounded-full {$config['class']}"]) }}
></span>
