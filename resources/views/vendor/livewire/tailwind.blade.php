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

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-1.5 text-xs text-zinc-400 dark:text-zinc-600">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
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
                @endif
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

            <div wire:loading wire:target="previousPage,nextPage,gotoPage" class="ml-1" role="status" aria-label="Memuat halaman">
                <x-phosphor-spinner class="h-4 w-4 animate-spin text-accent-500" />
            </div>
        </div>
    </nav>
@endif
