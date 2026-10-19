@props(['priority' => false, 'compact' => false, 'secondary' => false])

{{-- Queue category chip. `compact` fits beside a list row's name; `secondary` also renders the Sekunder chip. --}}
@if ($priority)
    <span title="Situs prioritas - dicek lebih dulu di antrean saat server sibuk." class="inline-flex shrink-0 items-center gap-1 rounded-full bg-accent-50 {{ $compact ? 'px-1.5 py-0.5 text-[11px]' : 'px-2 py-0.5 text-xs' }} font-medium text-accent-700 dark:bg-accent-500/10 dark:text-accent-400">
        <x-phosphor-arrow-fat-up-fill class="h-3 w-3" aria-hidden="true" />
        Prioritas
    </span>
@elseif ($secondary)
    <span title="Situs sekunder - dicek setelah situs prioritas saat antrean padat." class="inline-flex shrink-0 items-center rounded-full bg-zinc-100 {{ $compact ? 'px-1.5 py-0.5 text-[11px]' : 'px-2 py-0.5 text-xs' }} font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
        Sekunder
    </span>
@endif
