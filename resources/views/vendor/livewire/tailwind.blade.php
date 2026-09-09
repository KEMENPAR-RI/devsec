{{--
    Overrides Livewire's own bundled pagination view (unstyled gray, no dark mode)
    to match this app's design system, and adds a loading indicator + disables the
    controls while a page change is in flight - clicking a page number gives
    immediate feedback instead of feeling unresponsive on slower connections.
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination Navigation" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs text-zinc-500 dark:text-zinc-400">
            Menampilkan <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $paginator->firstItem() }}</span>-<span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $paginator->lastItem() }}</span>
            dari <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $paginator->total() }}</span>
        </p>

        <div class="flex items-center gap-1" wire:loading.class="opacity-60" wire:target="previousPage,nextPage,gotoPage">
            @if ($paginator->onFirstPage())
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-300 dark:text-zinc-700">
                    <x-phosphor-caret-left class="h-4 w-4" />
                </span>
            @else
                <button
                    type="button"
                    wire:click="previousPage('{{ $paginator->getPageName() }}')"
                    wire:loading.attr="disabled"
                    wire:target="previousPage,nextPage,gotoPage"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 disabled:cursor-not-allowed dark:text-zinc-400 dark:hover:bg-zinc-800"
                    aria-label="{{ __('pagination.previous') }}"
                >
                    <x-phosphor-caret-left class="h-4 w-4" />
                </button>
            @endif

            @php
                // A fixed window: 2 at the start, 3 around the current page, 2 at the
                // end. Computed here rather than read from $elements because Laravel's
                // own window widens with the page count - past a few dozen pages the row
                // ran off the card and wrapped into a block of numbers nobody aims at.
                $lastPage = $paginator->lastPage();
                // Clamped so the middle three stay three at either end, instead of
                // collapsing to two when the current page is the first or the last.
                $middleStart = max(1, min($paginator->currentPage() - 1, $lastPage - 2));

                $pages = collect([1, 2])
                    ->merge(range($middleStart, min($lastPage, $middleStart + 2)))
                    ->merge([$lastPage - 1, $lastPage])
                    ->filter(fn (int $page) => $page >= 1 && $page <= $lastPage)
                    ->unique()
                    ->sort()
                    ->values();
            @endphp

            @foreach ($pages as $i => $page)
                {{-- A gap in the run is exactly where numbers were skipped. --}}
                @if ($i > 0 && $page - $pages[$i - 1] > 1)
                    <span class="px-1 text-xs text-zinc-400 dark:text-zinc-600">...</span>
                @endif

                <span wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}">
                    @if ($page == $paginator->currentPage())
                        <span aria-current="page" class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg bg-accent-600 px-2 text-xs font-semibold text-white">
                            {{ $page }}
                        </span>
                    @else
                        <button
                            type="button"
                            wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')"
                            wire:loading.attr="disabled"
                            wire:target="previousPage,nextPage,gotoPage"
                            class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-xs font-medium text-zinc-600 transition hover:bg-zinc-100 disabled:cursor-not-allowed dark:text-zinc-300 dark:hover:bg-zinc-800"
                            aria-label="{{ __('Go to page :page', ['page' => $page]) }}"
                        >
                            {{ $page }}
                        </button>
                    @endif
                </span>
            @endforeach

            @if ($paginator->hasMorePages())
                <button
                    type="button"
                    wire:click="nextPage('{{ $paginator->getPageName() }}')"
                    wire:loading.attr="disabled"
                    wire:target="previousPage,nextPage,gotoPage"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 disabled:cursor-not-allowed dark:text-zinc-400 dark:hover:bg-zinc-800"
                    aria-label="{{ __('pagination.next') }}"
                >
                    <x-phosphor-caret-right class="h-4 w-4" />
                </button>
            @else
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-300 dark:text-zinc-700">
                    <x-phosphor-caret-right class="h-4 w-4" />
                </span>
            @endif

            {{-- The slot is always there, only the spinner inside it comes and goes:
            appearing from nothing would widen the row and nudge every page number
            left at the moment one of them was clicked. --}}
            <div class="ml-1 flex h-4 w-4 shrink-0 items-center justify-center" role="status" aria-label="Memuat halaman">
                <x-phosphor-spinner class="h-4 w-4 animate-spin text-accent-500" wire:loading wire:target="previousPage,nextPage,gotoPage" />
            </div>
        </div>
    </nav>
@endif
