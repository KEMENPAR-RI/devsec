@props(['align' => 'right'])

<div
    x-show="open"
    x-cloak
    x-transition:enter="transition ease-out duration-150"
    x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
    x-transition:enter-end="opacity-100 scale-100 translate-y-0"
    x-transition:leave="transition ease-in duration-100"
    x-transition:leave-start="opacity-100 scale-100 translate-y-0"
    x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
    {{ $attributes->merge(['class' => ($align === 'right' ? 'right-0' : 'left-0') . ' absolute z-50 mt-2 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-lg shadow-zinc-900/5 dark:border-zinc-800 dark:bg-zinc-900']) }}
>
    {{ $slot }}
</div>
