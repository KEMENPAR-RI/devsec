{{--
    One settings section as its own card. Each section on the Pengaturan page gets
    a card of its own instead of sharing one long card split by rules, so a tab
    reads as a short list of topics rather than a single wall of fields.

    Usage: <x-settings-card title="Backup Database" icon="phosphor-database">
      x-slot:action - optional control pinned to the right of the title (a toggle)
      default slot - the fields themselves
    </x-settings-card>
--}}
@props([
    'title' => null,
    'icon' => null,
    'description' => null,
])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-5 rounded-2xl border border-zinc-200 bg-white p-5 sm:p-6 dark:border-zinc-800 dark:bg-zinc-900']) }}>
    @if ($title)
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    @if ($icon)
                        <x-dynamic-component :component="$icon" class="h-4.5 w-4.5 shrink-0 text-accent-500" />
                    @endif
                    {{ $title }}
                </div>
                @if ($description)
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
                @endif
            </div>
            @isset($action)
                <div class="shrink-0">{{ $action }}</div>
            @endisset
        </div>
    @endif

    {{ $slot }}
</div>
