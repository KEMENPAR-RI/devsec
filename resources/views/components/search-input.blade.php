@props(['model' => 'search', 'placeholder' => 'Cari...'])

{{--
    Reusable debounced search field - wire:model.live.debounce so the query only
    fires 400ms after the user stops typing, not on every keystroke.
--}}
<div class="relative flex-1 sm:max-w-xs" wire:key="search-input-{{ $model }}">
    <x-phosphor-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
    <input
        type="text"
        wire:model.live.debounce.400ms="{{ $model }}"
        placeholder="{{ $placeholder }}"
        {{ $attributes->merge(['class' => 'w-full rounded-lg border border-zinc-200 bg-white py-2 pl-9 pr-8 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder:text-zinc-400']) }}
    >
    <div wire:loading.flex wire:target="{{ $model }}" class="absolute right-3 top-1/2 hidden -translate-y-1/2">
        <x-phosphor-spinner class="h-4 w-4 animate-spin text-zinc-400" />
    </div>
</div>
