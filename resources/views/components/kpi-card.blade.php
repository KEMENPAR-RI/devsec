@props([
    'label',
    'value',
    'icon',
    'tone' => 'accent',
    'href' => null,
    'size' => 'lg',
    'alert' => false,
])

{{-- One KPI tile. Tone classes are spelled out in full so Tailwind can see them. --}}
@php
    $tones = [
        'accent' => 'bg-accent-50 text-accent-600 dark:bg-accent-500/10 dark:text-accent-400',
        'up' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
        'down' => 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400',
        'warning' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
        'muted' => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
    ];
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->class([
        'enter flex min-w-0 flex-col rounded-2xl border bg-white p-5 transition dark:bg-zinc-900',
        'border-red-200 ring-1 ring-red-500/20 dark:border-red-500/30' => $alert,
        'border-zinc-200 dark:border-zinc-800' => ! $alert,
        'hover:border-zinc-300 hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500/40 dark:hover:border-zinc-700' => $href,
    ]) }}
>
    <div class="flex items-start justify-between gap-3">
        <span class="text-xs font-medium leading-snug text-zinc-500 dark:text-zinc-400">{{ $label }}</span>
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $tones[$tone] ?? $tones['accent'] }}">
            <x-dynamic-component :component="'phosphor-'.$icon" class="h-4 w-4" aria-hidden="true" />
        </span>
    </div>
    <span @class([
        'mt-2 font-mono font-semibold tabular-nums text-zinc-900 dark:text-white',
        'text-3xl' => $size === 'lg',
        'text-xl' => $size !== 'lg',
    ])>{{ $value }}</span>
    @if ($slot->isNotEmpty())
        <div class="mt-2 flex flex-wrap gap-1.5 text-[11px] text-zinc-500 dark:text-zinc-400">{{ $slot }}</div>
    @endif
</{{ $tag }}>
