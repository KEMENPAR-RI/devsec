@props([
    'isEmpty' => false,
    'emptyIcon' => 'phosphor-tray',
    'emptyTitle' => null,
    'emptyMessage' => null,
])

{{--
    Reusable shell for every list page (Situs, Insiden, Deteksi Judol, Notifikasi) -
    one card holding the search/filter toolbar, the list content, and pagination,
    so all four stay visually symmetric and share one place to restyle from.

    Usage: <x-list-card :is-empty="$items->isEmpty()" empty-icon="phosphor-globe"
    :empty-title="..." :empty-message="...">
      x-slot:toolbar - the search input, plus any filter selects alongside it
      default slot - the list markup itself
      x-slot:footer - typically {{ $items->links() }}
      x-slot:emptyAction - optional button under the empty message
    </x-list-card>
--}}
<div {{ $attributes->merge(['class' => 'enter rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900']) }}>
    @isset($toolbar)
        <div class="flex flex-col gap-3 border-b border-zinc-100 px-5 py-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between dark:border-zinc-800">
            {{ $toolbar }}
        </div>
    @endisset

    @if ($isEmpty)
        <div class="flex flex-col items-center gap-2 px-5 py-16 text-center">
            <x-dynamic-component :component="$emptyIcon" class="h-8 w-8 text-zinc-300 dark:text-zinc-600" />
            @if ($emptyTitle)
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $emptyTitle }}</h2>
            @endif
            @if ($emptyMessage)
                <p class="max-w-xs text-sm text-zinc-500 dark:text-zinc-400">{{ $emptyMessage }}</p>
            @endif
            @isset($emptyAction)
                <div class="mt-2">{{ $emptyAction }}</div>
            @endisset
        </div>
    @else
        {{ $slot }}
    @endif

    @isset($footer)
        <div class="border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
            {{ $footer }}
        </div>
    @endisset
</div>
