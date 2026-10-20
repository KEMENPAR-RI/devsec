@php
    $typeLabels = [
        'added' => ['label' => 'Halaman baru', 'icon' => 'phosphor-file-plus', 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/30'],
        'changed' => ['label' => 'Berubah', 'icon' => 'phosphor-git-diff', 'class' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/30'],
        'removed' => ['label' => 'Hilang', 'icon' => 'phosphor-file-x', 'class' => 'bg-zinc-100 text-zinc-600 ring-zinc-500/20 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-600/40'],
    ];
    $removedReasons = [
        'http_404' => 'Halaman merespons HTTP 404 (tidak ditemukan).',
        'http_410' => 'Halaman merespons HTTP 410 (sudah dihapus).',
        'not_reached' => 'Tidak terjangkau crawl lengkap beberapa kali berturut-turut.',
    ];
    $hasFilter = $site !== '' || $type !== '' || $search !== '' || $to !== '';
@endphp

<div class="mx-auto flex max-w-7xl flex-col gap-6">
    {{-- Header --}}
    <div class="enter flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Perubahan Konten</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Riwayat halaman yang muncul, berubah, atau hilang antar crawl - lengkap dengan potongan teks yang ditambah dan dihapus. Tanggal, jam, dan angka yang berubah sendiri tidak dicatat.</p>
        </div>
        @can('perubahan-konten.delete')
            <div class="flex shrink-0 flex-wrap items-center gap-2 self-start sm:self-auto">
                <x-confirm-dialog
                    title="Hapus riwayat sesuai filter?"
                    message="Semua riwayat perubahan yang cocok dengan filter aktif (situs, tanggal, tipe, URL) akan dihapus permanen. Snapshot halaman tidak ikut terhapus."
                    confirm-text="Ya, Hapus"
                    variant="danger"
                    target="deleteFiltered"
                >
                    <x-slot:trigger>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3.5 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-red-500/30 dark:text-red-400 dark:hover:bg-red-500/10">
                            <x-phosphor-trash class="h-4 w-4" />
                            Hapus Riwayat
                        </button>
                    </x-slot:trigger>
                    <form wire:submit="deleteFiltered" x-ref="form"></form>
                </x-confirm-dialog>
            </div>
        @endcan
    </div>

    <x-list-card
        :is-empty="$changes->isEmpty()"
        empty-icon="phosphor-clock-clockwise"
        :empty-title="$hasFilter ? 'Tidak ada perubahan yang cocok' : 'Belum ada perubahan konten'"
        :empty-message="$hasFilter ? 'Coba longgarkan filter situs, tanggal, atau tipe.' : 'Perubahan akan tercatat mulai crawl kedua - crawl pertama tiap situs hanya merekam kondisi awal.'"
    >
        <x-slot:toolbar>
            <x-search-input model="search" placeholder="Cari URL halaman..." class="sm:max-w-xs" />
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-2">
                <label class="sr-only" for="filter-site">Situs</label>
                <select id="filter-site" wire:model.live="site" class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                    <option value="">Semua Situs</option>
                    @foreach ($websites as $website)
                        <option value="{{ $website->uuid }}">{{ $website->name }}</option>
                    @endforeach
                </select>
                <label class="sr-only" for="filter-type">Tipe perubahan</label>
                <select id="filter-type" wire:model.live="type" class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                    <option value="">Semua Tipe</option>
                    <option value="added">Halaman baru</option>
                    <option value="changed">Berubah</option>
                    <option value="removed">Hilang</option>
                </select>
                <div class="flex items-center gap-1.5">
                    <label class="sr-only" for="filter-from">Dari tanggal</label>
                    <input id="filter-from" type="date" wire:model.live="from" class="min-w-0 flex-1 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 @error('from') border-red-400 @enderror">
                    <span class="text-xs text-zinc-400">s/d</span>
                    <label class="sr-only" for="filter-to">Sampai tanggal</label>
                    <input id="filter-to" type="date" wire:model.live="to" class="min-w-0 flex-1 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 @error('to') border-red-400 @enderror">
                </div>
            </div>
            @if ($errors->hasAny(['site', 'type', 'from', 'to', 'search']))
                <p class="w-full text-xs text-red-600 dark:text-red-400">
                    {{ $errors->first('from') ?: ($errors->first('to') ?: ($errors->first('site') ?: ($errors->first('type') ?: $errors->first('search')))) }}
                </p>
            @endif
        </x-slot:toolbar>

        <div class="divide-y divide-zinc-100 transition-opacity dark:divide-zinc-800" wire:loading.class="opacity-40" wire:target="search, site, type, from, to, gotoPage, nextPage, previousPage">
            @foreach ($changes as $change)
                @php
                    $meta = $typeLabels[$change->type] ?? $typeLabels['changed'];
                    $isOpen = $expanded === $change->uuid;
                    $pageUrl = safe_image_urls($change->url);
                    $added = $change->added ?? [];
                    $removed = $change->removed ?? [];
                    $metaChanges = $change->meta_changes ?? [];
                @endphp

                <section class="px-5 py-4" wire:key="perubahan-{{ $change->uuid }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $meta['class'] }}">
                                    <x-dynamic-component :component="$meta['icon']" class="h-3.5 w-3.5" />
                                    {{ $meta['label'] }}
                                </span>
                                @if ($change->website)
                                    <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $change->website->name }}</span>
                                @endif
                                @if ($change->type === 'changed' && $change->change_ratio !== null)
                                    <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400" title="Perkiraan porsi teks halaman yang berubah">
                                        ~{{ round((float) $change->change_ratio * 100) }}% teks
                                    </span>
                                @endif
                                @if ($change->judolDetection)
                                    <a href="{{ route('deteksi-judol', ['search' => $change->judolDetection->url]) }}" wire:navigate class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 hover:underline dark:bg-red-500/10 dark:text-red-400">
                                        <x-phosphor-shield-warning class="h-3.5 w-3.5" />
                                        Temuan judol
                                    </a>
                                @endif
                                @if ($change->defacementFinding)
                                    <a href="{{ route('defacement', ['site' => $change->website?->uuid, 'status' => '']) }}" wire:navigate class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 hover:underline dark:bg-red-500/10 dark:text-red-400">
                                        <x-phosphor-warning-octagon class="h-3.5 w-3.5" />
                                        Temuan defacement
                                    </a>
                                @endif
                            </div>

                            @if ($pageUrl)
                                <a href="{{ $pageUrl }}" target="_blank" rel="noopener noreferrer" class="mt-1.5 flex min-w-0 items-center gap-1 font-mono text-xs text-zinc-500 hover:text-accent-600 dark:text-zinc-400 dark:hover:text-accent-400" title="{{ $change->url }}">
                                    <span class="truncate">{{ $change->url }}</span>
                                    <x-phosphor-arrow-square-out class="h-3 w-3 shrink-0" />
                                </a>
                            @else
                                <p class="mt-1.5 truncate font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $change->url }}</p>
                            @endif

                            {{-- When it happened: the change landed somewhere between the two crawls. --}}
                            <dl class="mt-2 flex flex-col gap-1 text-xs text-zinc-500 sm:flex-row sm:flex-wrap sm:gap-x-4 dark:text-zinc-400">
                                <div class="flex items-center gap-1.5">
                                    <x-phosphor-clock-clockwise class="h-3.5 w-3.5 shrink-0 text-zinc-400" />
                                    @if ($change->previous_crawled_at)
                                        <dt class="sr-only">Rentang waktu</dt>
                                        <dd>Berubah antara <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ app_datetime($change->previous_crawled_at) }}</span> dan <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ app_datetime($change->detected_at) }}</span></dd>
                                    @else
                                        <dt class="sr-only">Terdeteksi</dt>
                                        <dd>Terdeteksi <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ app_datetime($change->detected_at) }}</span></dd>
                                    @endif
                                </div>
                                @if ($change->page_last_modified_at)
                                    <div class="flex items-center gap-1.5" title="Header Last-Modified dari server situs - tidak selalu akurat">
                                        <x-phosphor-calendar-blank class="h-3.5 w-3.5 shrink-0 text-zinc-400" />
                                        <dt>Last-Modified:</dt>
                                        <dd class="font-medium text-zinc-700 dark:text-zinc-300">{{ app_datetime($change->page_last_modified_at) }}</dd>
                                    </div>
                                @endif
                            </dl>
                        </div>

                        <div class="flex shrink-0 items-center gap-1.5">
                            @if ($change->type !== 'removed' || $metaChanges)
                                <button
                                    type="button"
                                    wire:click="toggle('{{ $change->uuid }}')"
                                    aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-2.5 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                >
                                    <x-loading-label target="toggle('{{ $change->uuid }}')" spinner="h-3.5 w-3.5" gap="gap-1.5">
                                        {{ $isOpen ? 'Tutup' : 'Lihat Perubahan' }}
                                        <x-phosphor-caret-down @class(['h-3.5 w-3.5 transition', 'rotate-180' => $isOpen]) />
                                    </x-loading-label>
                                </button>
                            @endif
                            @can('perubahan-konten.delete')
                                <x-confirm-dialog
                                    title="Hapus riwayat ini?"
                                    message="Catatan perubahan halaman ini akan dihapus permanen."
                                    confirm-text="Ya, Hapus"
                                    variant="danger"
                                    target="delete('{{ $change->uuid }}')"
                                >
                                    <x-slot:trigger>
                                        <button type="button" title="Hapus riwayat" aria-label="Hapus riwayat" class="inline-flex items-center rounded-lg border border-zinc-200 px-2.5 py-1.5 text-xs text-zinc-600 transition hover:bg-red-50 hover:text-red-600 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-red-500/10 dark:hover:text-red-400">
                                            <x-phosphor-trash class="h-3.5 w-3.5" />
                                        </button>
                                    </x-slot:trigger>
                                    <form wire:submit="delete('{{ $change->uuid }}')" x-ref="form"></form>
                                </x-confirm-dialog>
                            @endcan
                        </div>
                    </div>

                    @if ($change->type === 'removed' && ($reason = $metaChanges['reason'] ?? null))
                        <p class="mt-2 flex items-start gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                            <x-phosphor-info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                            {{ $removedReasons[$reason] ?? $reason }}
                        </p>
                    @endif

                    @if ($isOpen)
                        {{-- Diff: crawled text, always escaped. --}}
                        <div class="mt-3 space-y-3 rounded-xl bg-zinc-50 px-4 py-3.5 dark:bg-zinc-800/40">
                            @foreach (['title' => 'Judul halaman', 'description' => 'Deskripsi meta'] as $field => $fieldLabel)
                                @if (is_array($metaChanges[$field] ?? null))
                                    <div>
                                        <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">{{ $fieldLabel }}</p>
                                        <div class="mt-1.5 flex flex-col gap-1 text-sm">
                                            <p class="break-words rounded-md bg-red-50 px-2 py-1 text-red-800 line-through decoration-red-400/60 dark:bg-red-500/10 dark:text-red-300">{{ $metaChanges[$field]['before'] ?? '' ?: '(kosong)' }}</p>
                                            <p class="break-words rounded-md bg-emerald-50 px-2 py-1 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $metaChanges[$field]['after'] ?? '' ?: '(kosong)' }}</p>
                                        </div>
                                    </div>
                                @endif
                            @endforeach

                            @if ($removed)
                                <div>
                                    <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">Dihapus ({{ count($removed) }})</p>
                                    <ul class="mt-1.5 space-y-1">
                                        @foreach ($removed as $chunk)
                                            <li class="flex gap-2 rounded-md border-l-2 border-red-400 bg-red-50 px-2.5 py-1.5 text-sm text-red-900 dark:border-red-500/60 dark:bg-red-500/10 dark:text-red-200">
                                                <x-phosphor-minus class="mt-1 h-3 w-3 shrink-0" aria-hidden="true" />
                                                <span class="sr-only">Dihapus:</span>
                                                <span class="min-w-0 whitespace-pre-wrap break-words">{{ $chunk }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if ($added)
                                <div>
                                    <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">Ditambah ({{ count($added) }})</p>
                                    <ul class="mt-1.5 space-y-1">
                                        @foreach ($added as $chunk)
                                            <li class="flex gap-2 rounded-md border-l-2 border-emerald-400 bg-emerald-50 px-2.5 py-1.5 text-sm text-emerald-900 dark:border-emerald-500/60 dark:bg-emerald-500/10 dark:text-emerald-200">
                                                <x-phosphor-plus class="mt-1 h-3 w-3 shrink-0" aria-hidden="true" />
                                                <span class="sr-only">Ditambah:</span>
                                                <span class="min-w-0 whitespace-pre-wrap break-words">{{ $chunk }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if (! $added && ! $removed && ! isset($metaChanges['title']) && ! isset($metaChanges['description']))
                                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $change->type === 'added' ? 'Halaman baru ditemukan - belum ada versi sebelumnya untuk dibandingkan.' : 'Tidak ada potongan teks yang tersimpan untuk perubahan ini.' }}
                                </p>
                            @endif
                        </div>
                    @endif
                </section>
            @endforeach
        </div>

        <x-slot:footer>
            {{ $changes->links() }}
        </x-slot:footer>
    </x-list-card>
</div>
