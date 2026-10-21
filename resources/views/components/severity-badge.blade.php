@props(['severity' => null, 'score' => null])

{{-- Risk level of a content-change event. null = recorded before risk scoring existed (legacy), shown neutral. --}}
@php
    $config = match ($severity) {
        'critical' => ['label' => 'Kritis', 'icon' => 'phosphor-warning-octagon-fill', 'class' => 'bg-red-600 text-white ring-red-700/40 dark:bg-red-500 dark:ring-red-400/40', 'title' => 'Risiko kritis - kemungkinan besar halaman disusupi.'],
        'warning' => ['label' => 'Peringatan', 'icon' => 'phosphor-warning-fill', 'class' => 'bg-amber-50 text-amber-800 ring-amber-600/30 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30', 'title' => 'Ada sinyal mencurigakan yang perlu dicek.'],
        'info' => ['label' => 'Info', 'icon' => 'phosphor-info', 'class' => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/30', 'title' => 'Perubahan dengan risiko rendah.'],
        'none' => ['label' => 'Tanpa risiko', 'icon' => 'phosphor-check', 'class' => 'bg-zinc-100 text-zinc-600 ring-zinc-500/20 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-600/40', 'title' => 'Tidak ada sinyal risiko.'],
        default => ['label' => 'Lama', 'icon' => 'phosphor-clock-counter-clockwise', 'class' => 'bg-white text-zinc-500 ring-zinc-300 dark:bg-zinc-900 dark:text-zinc-400 dark:ring-zinc-700', 'title' => 'Event tercatat sebelum penilaian risiko aktif - tingkat risiko tidak diketahui.'],
    };
@endphp

<span
    title="{{ $config['title'] }}"
    {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset '.$config['class']]) }}
>
    <x-dynamic-component :component="$config['icon']" class="h-3.5 w-3.5" aria-hidden="true" />
    <span class="sr-only">Tingkat risiko:</span>
    {{ $config['label'] }}
    @if ($severity !== null && $score !== null)
        <span class="font-mono font-medium tabular-nums opacity-80" aria-label="skor {{ (int) $score }}">&middot; {{ (int) $score }}</span>
    @endif
</span>
