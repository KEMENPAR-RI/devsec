@props(['href', 'active' => false, 'icon' => null])

<a
    href="{{ $href }}"
    wire:navigate
    @class([
        'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
        'bg-accent-50 text-accent-700 dark:bg-accent-500/10 dark:text-accent-400' => $active,
        'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100' => ! $active,
    ])
>
    @if ($icon)
        <x-dynamic-component
            :component="'phosphor-'.$icon"
            @class([
                'h-5 w-5 shrink-0',
                'text-accent-600 dark:text-accent-400' => $active,
                'text-zinc-400 group-hover:text-zinc-500 dark:group-hover:text-zinc-300' => ! $active,
            ])
        />
    @endif
    <span>{{ $slot }}</span>
</a>
