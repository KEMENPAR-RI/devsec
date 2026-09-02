@props([
    'title',            // site name
    'href' => null,     // link to that site's detail page
    'day',              // Carbon|string - the group's date
    'count' => 0,       // how many rows are inside
    'unit' => 'entri',  // what those rows are called ("insiden", "deteksi", ...)
    'open' => false,    // start expanded (used for the newest group)
    'tone' => 'zinc',   // header icon tone: zinc | red | amber | emerald
    'icon' => 'phosphor-stack',
])

{{--
    One collapsible (site + date) group for the list pages. The header carries the
    summary so a collapsed group still says what happened; the default slot holds
    the individual rows, revealed on expand.

    The "Lihat Situs" link is a sibling of the toggle button, never nested inside
    it - an <a> within a <button> is invalid HTML and browsers handle it badly.

    x-slot:summary - status badges shown on the header line
--}}
<div x-data="{ open: @js($open) }" class="transition-colors">
    <div class="flex items-stretch">
        <button
            type="button"
            @click="open = !open"
            class="flex min-w-0 flex-1 items-center gap-3 py-3.5 pl-5 pr-3 text-left transition hover:bg-zinc-50 dark:hover:bg-zinc-800/40"
        >
            <span @class([
                'flex h-9 w-9 shrink-0 items-center justify-center rounded-full',
                'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400' => $tone === 'red',
                'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' => $tone === 'amber',
                'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' => $tone === 'emerald',
                'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => $tone === 'zinc',
            ])>
                <x-dynamic-component :component="$icon" class="h-4 w-4" />
            </span>

            <span class="min-w-0 flex-1">
                <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $title }}</span>
                    <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                        {{ $count }} {{ $unit }}
                    </span>
                    @isset($summary)
                        {{ $summary }}
                    @endisset
                </span>
                <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                    {{ $day instanceof \Carbon\CarbonInterface ? $day->translatedFormat('l, d F Y') : $day }}
                </span>
            </span>

            <x-phosphor-caret-down class="h-4 w-4 shrink-0 text-zinc-400 transition-transform duration-200" x-bind:class="open ? 'rotate-180' : ''" />
        </button>

        @if ($href)
            <a
                href="{{ $href }}"
                wire:navigate
                class="hidden shrink-0 items-center pl-3 pr-5 text-xs font-medium text-accent-600 transition hover:text-accent-700 sm:flex dark:text-accent-400 dark:hover:text-accent-300"
            >
                Lihat Situs
            </a>
        @endif
    </div>

    <div x-show="open" x-cloak x-transition class="border-t border-zinc-100 bg-zinc-50/40 dark:border-zinc-800 dark:bg-zinc-950/30">
        {{ $slot }}
    </div>
</div>
