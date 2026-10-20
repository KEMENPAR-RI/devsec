@php
    // Every value from the target site below is printed with {{ }} only and never as a link (arsitektur §6).
    $typeLabels = [
        'added' => ['label' => 'Halaman baru', 'icon' => 'phosphor-file-plus', 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/30'],
        'changed' => ['label' => 'Berubah', 'icon' => 'phosphor-git-diff', 'class' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/30'],
        'removed' => ['label' => 'Hilang', 'icon' => 'phosphor-file-x', 'class' => 'bg-zinc-100 text-zinc-600 ring-zinc-500/20 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-600/40'],
    ];
    $kindLabels = ['text' => 'Teks', 'element' => 'Non-teks', 'mixed' => 'Teks + non-teks'];
    $itemKinds = [
        'text' => ['label' => 'Teks', 'icon' => 'phosphor-text-aa'],
        'script' => ['label' => 'Script eksternal', 'icon' => 'phosphor-file-code'],
        'iframe' => ['label' => 'Iframe', 'icon' => 'phosphor-puzzle-piece'],
        'link' => ['label' => 'Link domain lain', 'icon' => 'phosphor-link'],
        'meta' => ['label' => 'Meta', 'icon' => 'phosphor-code'],
        'redirect' => ['label' => 'Domain final berpindah', 'icon' => 'phosphor-arrow-bend-down-right'],
    ];
    $changeLabels = [
        'added' => ['label' => 'Ditambah', 'class' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'],
        'removed' => ['label' => 'Dihapus', 'class' => 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400'],
        'modified' => ['label' => 'Diubah', 'class' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400'],
    ];
    $unavailableReasons = [
        'no_baseline' => 'belum ada salinan HTML sebelumnya (crawl pertama sejak fitur aktif)',
        'too_large' => 'halaman terlalu besar untuk disimpan',
        'mismatch' => 'salinan HTML sebelumnya tidak cocok dengan hash-nya',
        'missing' => 'salinan HTML sebelumnya tidak ditemukan',
    ];
    $precisionLabels = [
        'exact' => null,
        'element' => ['label' => 'perkiraan', 'title' => 'Teks tidak bisa dicocokkan per byte; yang ditampilkan adalah baris awal elemennya.'],
        'unknown' => ['label' => 'tidak diketahui', 'title' => 'Posisi elemen di source tidak bisa dipastikan.'],
    ];

    $status = $change->http_status;
    $statusTone = match (true) {
        $status === null => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
        $status < 300 => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
        $status < 400 => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-400',
        default => 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400',
    };
    $typeMeta = $typeLabels[$change->type] ?? $typeLabels['changed'];

    $items = collect($detail['items'] ?? []);
    $isHidden = fn (array $item) => (bool) (($item['before']['hidden'] ?? false) || ($item['after']['hidden'] ?? false));
    $filters = [
        '' => ['label' => 'Semua', 'count' => $items->count()],
        'text' => ['label' => 'Teks', 'count' => $items->where('kind', 'text')->count()],
        'element' => ['label' => 'Non-teks', 'count' => $items->where('kind', '!=', 'text')->count()],
        'hidden' => ['label' => 'Tersembunyi', 'count' => $items->filter($isHidden)->count()],
    ];
    $activeFilter = array_key_exists($filter, $filters) ? $filter : '';
    $visibleItems = match ($activeFilter) {
        'text' => $items->where('kind', 'text'),
        'element' => $items->where('kind', '!=', 'text'),
        'hidden' => $items->filter($isHidden),
        default => $items,
    };
    $counts = $detail['counts'] ?? [];
    $undetailed = (int) ($detail['undetailed'] ?? 0);

    $label = 'text-[11px] font-medium uppercase tracking-wide text-zinc-400';
    $mono = 'font-mono text-xs text-zinc-800 select-all break-all dark:text-zinc-200';
@endphp

<div class="mx-auto flex max-w-7xl flex-col gap-6">
    {{-- Header --}}
    <div class="enter flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <a href="{{ route('perubahan-konten') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-zinc-500 transition hover:text-accent-600 dark:text-zinc-400 dark:hover:text-accent-400">
                <x-phosphor-arrow-left class="h-3.5 w-3.5" />
                Perubahan Konten
            </a>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Detail Forensik</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $typeMeta['class'] }}">
                    <x-dynamic-component :component="$typeMeta['icon']" class="h-3.5 w-3.5" />
                    {{ $typeMeta['label'] }}
                </span>
                @if ($change->content_kind)
                    <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $kindLabels[$change->content_kind] ?? $change->content_kind }}</span>
                @endif
                @if ($change->website)
                    <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $change->website->name }}</span>
                @endif
            </div>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2 self-start">
            @can('perubahan-konten.view')
                {{-- Plain link, no wire:navigate: the response is a file download. --}}
                <a
                    href="{{ route('perubahan-konten.bukti', $change) }}"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-accent-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-accent-700"
                >
                    <x-phosphor-download-simple class="h-4 w-4" />
                    Unduh bukti
                </a>
            @endcan
            @can('perubahan-konten.delete')
                <x-confirm-dialog
                    title="Hapus event ini?"
                    message="Catatan perubahan beserta detail forensiknya akan dihapus permanen."
                    confirm-text="Ya, Hapus"
                    variant="danger"
                    target="delete"
                >
                    <x-slot:trigger>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3.5 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-red-500/30 dark:text-red-400 dark:hover:bg-red-500/10">
                            <x-phosphor-trash class="h-4 w-4" />
                            Hapus
                        </button>
                    </x-slot:trigger>
                    <form wire:submit="delete" x-ref="form"></form>
                </x-confirm-dialog>
            @endcan
        </div>
    </div>

    {{-- Request / response summary --}}
    <section class="enter rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900" aria-labelledby="ringkasan-title">
        <h2 id="ringkasan-title" class="border-b border-zinc-100 px-5 py-3.5 text-sm font-semibold text-zinc-900 dark:border-zinc-800 dark:text-white">Ringkasan respons</h2>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-4 px-5 py-4 sm:grid-cols-2 lg:grid-cols-6">
            <div class="min-w-0 sm:col-span-2 lg:col-span-3">
                <dt class="{{ $label }}">URL diminta</dt>
                <dd class="mt-1 {{ $mono }}"><x-visible-text :text="$change->url" /></dd>
            </div>
            <div class="min-w-0 sm:col-span-2 lg:col-span-3">
                <dt class="{{ $label }}">URL final</dt>
                @if ($change->final_url)
                    <dd class="mt-1 {{ $mono }}"><x-visible-text :text="$change->final_url" /></dd>
                @else
                    <dd class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Sama dengan URL diminta</dd>
                @endif
            </div>
            <div>
                <dt class="{{ $label }}">Status HTTP</dt>
                <dd class="mt-1">
                    <span class="inline-flex rounded-md px-2 py-0.5 font-mono text-xs font-semibold {{ $statusTone }}">{{ $status ?? 'tidak tersedia' }}</span>
                </dd>
            </div>
            <div>
                <dt class="{{ $label }}">Redirect</dt>
                <dd class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">
                    @if ($change->redirect_count === null)
                        <span class="text-zinc-500 dark:text-zinc-400">tidak tersedia</span>
                    @elseif ($change->redirect_count > 0)
                        Dialihkan {{ $change->redirect_count }}&times;
                    @else
                        Tidak ada
                    @endif
                </dd>
            </div>
            <div>
                <dt class="{{ $label }}">Terdeteksi</dt>
                <dd class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">{{ app_datetime($change->detected_at) }}</dd>
            </div>
            <div>
                <dt class="{{ $label }}">Crawl sebelumnya</dt>
                <dd class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">{{ $change->previous_crawled_at ? app_datetime($change->previous_crawled_at) : 'tidak tersedia' }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="{{ $label }}" title="Header Last-Modified dari server situs - tidak selalu akurat">Last-Modified</dt>
                <dd class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">{{ $change->page_last_modified_at ? app_datetime($change->page_last_modified_at) : 'tidak dikirim server' }}</dd>
            </div>
            @foreach ([['Hash body sebelum', $change->body_hash_before], ['Hash body sesudah', $change->body_hash_after]] as [$hashLabel, $hash])
                <div class="min-w-0 sm:col-span-2 lg:col-span-3" x-data="{ copied: false }">
                    <dt class="{{ $label }}">
                        {{ $hashLabel }}
                        <span class="normal-case tracking-normal text-zinc-500 dark:text-zinc-400">({{ $change->hash_algorithm ?: 'algoritma tidak tersedia' }})</span>
                    </dt>
                    @if ($hash)
                        <dd class="mt-1 flex items-start gap-1.5">
                            <code x-ref="hash" class="{{ $mono }} min-w-0">{{ $hash }}</code>
                            <button
                                type="button"
                                @click="navigator.clipboard?.writeText($refs.hash.textContent).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                                class="shrink-0 rounded p-0.5 text-zinc-400 transition hover:text-zinc-700 dark:hover:text-zinc-200"
                                aria-label="Salin {{ strtolower($hashLabel) }}"
                            >
                                <x-phosphor-copy class="h-3.5 w-3.5" x-show="! copied" />
                                <x-phosphor-check class="h-3.5 w-3.5 text-emerald-600" x-show="copied" x-cloak />
                            </button>
                        </dd>
                    @else
                        <dd class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">tidak tersedia</dd>
                    @endif
                </div>
            @endforeach
        </dl>
    </section>

    @if ($detail === null)
        @php
            // Reason supplied by Show::render(); anything unknown gets the generic text.
            $missingReasons = [
                'legacy' => ['Event dibuat sebelum fitur forensik aktif', 'Lokasi elemen dan cuplikan HTML tidak direkam untuk event lama. Potongan teks yang berubah tetap bisa dilihat di daftar Perubahan Konten.'],
                'new_page' => ['Halaman baru — belum ada pembanding', 'Halaman ini baru ditemukan, jadi belum ada versi sebelumnya untuk dirinci.'],
                'removed_page' => ['Halaman hilang — tidak ada konten sesudah', 'Halaman tidak lagi bisa diambil, jadi tidak ada konten sesudah untuk dirinci.'],
                'corrupt' => ['Data forensik rusak/tidak terbaca', 'Data forensik event ini ada, tetapi tidak bisa dibaca. Ringkasan di atas tetap berasal dari event.'],
            ];
            [$missingLabel, $missingBody] = $missingReasons[$unavailableReason ?? ''] ?? [null, 'Lokasi elemen dan cuplikan HTML tidak tersedia untuk event ini.'];
        @endphp
        <div class="enter flex items-start gap-3 rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 px-5 py-6 dark:border-zinc-700 dark:bg-zinc-900/60">
            <x-phosphor-info class="mt-0.5 h-5 w-5 shrink-0 text-zinc-400" />
            <div>
                <p class="text-sm font-semibold text-zinc-900 dark:text-white">Detail forensik tidak tersedia</p>
                @if ($missingLabel)
                    <p class="mt-1 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $missingLabel }}</p>
                @endif
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $missingBody }} File bukti tetap bisa diunduh dengan data ringkasan di atas.</p>
            </div>
        </div>
    @else
        @if (($detail['unavailable'] ?? null) !== null)
            <div class="enter flex items-start gap-2 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300" role="status">
                <x-phosphor-warning class="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    @if ($detail['unavailable'] === 'too_large')
                        Halaman terlalu besar, jadi lokasi dan cuplikan HTML tidak disimpan. Hash dan ringkasan di atas tetap valid.
                    @else
                        Detail forensik gagal dibuat saat event direkam. Event dan ringkasan di atas tetap tersimpan.
                    @endif
                </span>
            </div>
        @endif

        @if ($reason = $detail['before_unavailable'] ?? null)
            <div class="enter flex items-start gap-2 rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-800 dark:bg-sky-500/10 dark:text-sky-300" role="status">
                <x-phosphor-info class="mt-0.5 h-4 w-4 shrink-0" />
                <span>Sisi "sebelum" tidak tersedia: {{ $unavailableReasons[$reason] ?? $reason }}. Lokasi dan cuplikan sebelum tidak ditebak.</span>
            </div>
        @endif

        <section class="enter rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900" aria-labelledby="perubahan-title">
            <div class="flex flex-col gap-3 border-b border-zinc-100 px-5 py-4 lg:flex-row lg:items-center lg:justify-between dark:border-zinc-800">
                <div class="min-w-0">
                    <h2 id="perubahan-title" class="text-sm font-semibold text-zinc-900 dark:text-white">Rincian perubahan</h2>
                    <p class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                        <span>Teks <span class="font-medium text-emerald-700 dark:text-emerald-400">+{{ $counts['text_added'] ?? 0 }}</span> / <span class="font-medium text-red-700 dark:text-red-400">&minus;{{ $counts['text_removed'] ?? 0 }}</span></span>
                        <span>Elemen <span class="font-medium text-emerald-700 dark:text-emerald-400">+{{ $counts['elements_added'] ?? 0 }}</span> / <span class="font-medium text-red-700 dark:text-red-400">&minus;{{ $counts['elements_removed'] ?? 0 }}</span> / <span class="font-medium text-amber-700 dark:text-amber-400">~{{ $counts['elements_modified'] ?? 0 }}</span></span>
                        <span>Total {{ $counts['total'] ?? $items->count() }}</span>
                        @if ($undetailed > 0)
                            <span class="font-medium text-zinc-700 dark:text-zinc-300">+{{ $undetailed }} perubahan lain tidak dirinci</span>
                        @endif
                    </p>
                </div>
                <div class="-mx-1 flex gap-1 overflow-x-auto px-1" role="group" aria-label="Filter rincian">
                    @foreach ($filters as $key => $f)
                        <button
                            type="button"
                            wire:click="$set('filter', '{{ $key }}')"
                            aria-pressed="{{ $activeFilter === $key ? 'true' : 'false' }}"
                            @class([
                                'inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition',
                                'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $activeFilter === $key,
                                'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => $activeFilter !== $key,
                            ])
                        >
                            {{ $f['label'] }}
                            <span class="tabular-nums opacity-60">{{ $f['count'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="divide-y divide-zinc-100 transition-opacity dark:divide-zinc-800" wire:loading.delay.class="opacity-40">
                @forelse ($visibleItems as $item)
                    @php
                        $kind = $itemKinds[$item['kind'] ?? ''] ?? ['label' => $item['kind'] ?? '-', 'icon' => 'phosphor-code'];
                        $chg = $changeLabels[$item['change'] ?? ''] ?? $changeLabels['modified'];
                        $value = $item['value'] ?? null;
                        $valueTokens = $item['value_tokens'] ?? [];
                        $hasLocations = ($item['kind'] ?? '') !== 'redirect';
                    @endphp

                    <article class="px-5 py-4" wire:key="item-{{ $item['n'] ?? $loop->index }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-xs text-zinc-400">#{{ $item['n'] ?? $loop->iteration }}</span>
                            <span class="inline-flex items-center gap-1 text-sm font-semibold text-zinc-900 dark:text-white">
                                <x-dynamic-component :component="$kind['icon']" class="h-4 w-4 text-zinc-400" />
                                {{ $kind['label'] }}
                                @if (($item['field'] ?? null) === 'title')
                                    <span class="font-normal text-zinc-500 dark:text-zinc-400">(judul)</span>
                                @endif
                            </span>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $chg['class'] }}">{{ $chg['label'] }}</span>
                            @if ($isHidden($item))
                                <span class="inline-flex items-center gap-1 rounded-full bg-fuchsia-50 px-2 py-0.5 text-xs font-medium text-fuchsia-700 dark:bg-fuchsia-500/10 dark:text-fuchsia-300" title="Elemen disembunyikan dari pengunjung (aturan yang sama dengan deteksi judol)">
                                    <x-phosphor-eye-slash class="h-3.5 w-3.5" />
                                    Tersembunyi
                                </span>
                            @endif
                        </div>

                        @if (filled($item['text'] ?? null))
                            <p @class([
                                'mt-2 rounded-md border-l-2 px-2.5 py-1.5 text-sm whitespace-pre-wrap break-words',
                                'border-red-400 bg-red-50 text-red-900 dark:border-red-500/60 dark:bg-red-500/10 dark:text-red-200' => ($item['change'] ?? '') === 'removed',
                                'border-emerald-400 bg-emerald-50 text-emerald-900 dark:border-emerald-500/60 dark:bg-emerald-500/10 dark:text-emerald-200' => ($item['change'] ?? '') !== 'removed',
                            ])><x-visible-text :tokens="$item['text_tokens'] ?? null" :text="$item['text']" /></p>
                        @endif

                        @if (filled($item['identity'] ?? null))
                            <p class="mt-2 {{ $mono }}"><x-visible-text :tokens="$item['identity_tokens'] ?? null" :text="$item['identity']" /></p>
                        @endif

                        @if (is_array($value))
                            <div class="mt-2 flex flex-col gap-1 text-xs">
                                @foreach (['before' => 'Lama', 'after' => 'Baru'] as $vSide => $vLabel)
                                    <div class="flex min-w-0 items-start gap-2">
                                        <span class="w-10 shrink-0 pt-1 {{ $label }}">{{ $vLabel }}</span>
                                        @if (filled($value[$vSide] ?? null))
                                            <code @class([
                                                'min-w-0 flex-1 rounded-md px-2 py-1 font-mono break-all whitespace-pre-wrap',
                                                'bg-red-50 text-red-800 dark:bg-red-500/10 dark:text-red-300' => $vSide === 'before',
                                                'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' => $vSide === 'after',
                                            ])><x-visible-text :tokens="$valueTokens[$vSide] ?? null" :text="$value[$vSide]" /></code>
                                        @else
                                            <span class="pt-1 text-zinc-400">tidak ada</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($hasLocations)
                            <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
                                @foreach (['before' => 'Sebelum', 'after' => 'Sesudah'] as $side => $sideLabel)
                                    @php
                                        $loc = $item[$side] ?? null;
                                        $sideReason = $side === 'before' ? ($item['before_unavailable'] ?? null) : null;
                                        $notApplicable = ($side === 'before' && ($item['change'] ?? '') === 'added')
                                            || ($side === 'after' && ($item['change'] ?? '') === 'removed')
                                            || $sideReason === 'not_applicable';
                                        $precisionKey = $loc['line_precision'] ?? 'unknown';
                                        $precision = array_key_exists($precisionKey, $precisionLabels) ? $precisionLabels[$precisionKey] : $precisionLabels['unknown'];
                                    @endphp

                                    <div class="min-w-0 rounded-xl border border-zinc-200 dark:border-zinc-800">
                                        <div class="flex items-center justify-between gap-2 border-b border-zinc-100 px-3 py-2 dark:border-zinc-800">
                                            <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-200">{{ $sideLabel }}</span>
                                            @if (is_array($loc) && ($loc['hidden'] ?? false))
                                                <span class="inline-flex items-center gap-1 text-[11px] font-medium text-fuchsia-700 dark:text-fuchsia-300"><x-phosphor-eye-slash class="h-3 w-3" /> tersembunyi</span>
                                            @endif
                                        </div>

                                        @if (! is_array($loc))
                                            <p class="px-3 py-3 text-xs text-zinc-400">
                                                @if (isset($unavailableReasons[$sideReason]))
                                                    Tidak tersedia ({{ $unavailableReasons[$sideReason] }})
                                                @elseif ($notApplicable)
                                                    Tidak ada
                                                @else
                                                    Tidak tersedia
                                                @endif
                                            </p>
                                        @else
                                            <dl class="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-x-2 gap-y-1.5 px-3 py-2.5 text-xs">
                                                <dt class="text-zinc-400">Baris</dt>
                                                <dd class="flex flex-wrap items-center gap-1.5 text-zinc-800 dark:text-zinc-200">
                                                    <span class="font-mono font-semibold">{{ $loc['line'] ?? '-' }}</span>
                                                    @if ($precision)
                                                        <span class="rounded bg-amber-50 px-1.5 py-px text-[11px] text-amber-700 dark:bg-amber-500/10 dark:text-amber-300" title="{{ $precision['title'] }}">&asymp; {{ $precision['label'] }}</span>
                                                    @endif
                                                </dd>
                                                <dt class="text-zinc-400">Tag</dt>
                                                <dd class="font-mono text-zinc-800 dark:text-zinc-200">&lt;{{ $loc['tag'] ?? '?' }}&gt;</dd>
                                                @if (filled($loc['attrs'] ?? null))
                                                    <dt class="text-zinc-400">Atribut</dt>
                                                    <dd class="min-w-0 space-y-0.5">
                                                        @foreach ($loc['attrs'] as $attr => $attrValue)
                                                            <div class="font-mono break-all text-zinc-800 dark:text-zinc-200"><span class="text-zinc-500 dark:text-zinc-400">{{ $attr }}=</span><x-visible-text :tokens="$loc['attrs_tokens'][$attr] ?? null" :text="$attrValue" /></div>
                                                        @endforeach
                                                    </dd>
                                                @endif
                                                <dt class="text-zinc-400">XPath</dt>
                                                <dd class="{{ $mono }}">{{ $loc['xpath'] ?? '-' }}</dd>
                                                @if (filled($loc['css'] ?? null))
                                                    <dt class="text-zinc-400" title="Best-effort: DOM browser (HTML5) bisa berbeda, mis. tbody">CSS</dt>
                                                    <dd class="{{ $mono }}">{{ $loc['css'] }}</dd>
                                                @endif
                                            </dl>

                                            @if (($loc['snippet'] ?? '') !== '' || isset($loc['snippet_tokens']))
                                                <div class="border-t border-zinc-100 dark:border-zinc-800" x-data="{ wrap: false }">
                                                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-3 py-1.5 text-[11px] text-zinc-500 dark:text-zinc-400">
                                                        <span class="flex flex-wrap gap-x-2">
                                                            <span>Cuplikan HTML mentah</span>
                                                            @if ($loc['snippet_starts_inside'] ?? false)
                                                                <span class="text-amber-700 dark:text-amber-400">dimulai di tengah elemen</span>
                                                            @endif
                                                            @if ($loc['snippet_truncated'] ?? false)
                                                                <span class="text-amber-700 dark:text-amber-400">terpotong &mdash; panjang asli {{ number_format((int) ($loc['snippet_length'] ?? 0), 0, ',', '.') }} karakter</span>
                                                            @endif
                                                            @if (($loc['snippet_source'] ?? 'raw') === 'dom')
                                                                <span title="Penutup elemen tidak ditemukan di source; cuplikan disusun ulang dari DOM">dari DOM</span>
                                                            @endif
                                                        </span>
                                                        <button type="button" @click="wrap = ! wrap" :aria-pressed="wrap.toString()" class="rounded px-1.5 py-0.5 font-medium text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800">
                                                            <span x-text="wrap ? 'Satu baris' : 'Bungkus baris'">Bungkus baris</span>
                                                        </button>
                                                    </div>
                                                    {{-- Inert text inside <pre>; keep <x-visible-text> on the same line so no whitespace is added. --}}
                                                    <pre class="max-h-80 overflow-auto rounded-b-xl bg-zinc-900/95 px-3 py-2.5 font-mono text-[11px] leading-relaxed text-zinc-200 dark:bg-zinc-950" :class="wrap ? 'whitespace-pre-wrap break-all' : 'whitespace-pre'" tabindex="0" aria-label="Cuplikan HTML {{ strtolower($sideLabel) }}"><code><x-visible-text :tokens="$loc['snippet_tokens'] ?? null" :text="$loc['snippet'] ?? ''" /></code></pre>
                                                </div>
                                            @endif
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="flex flex-col items-center gap-2 px-5 py-12 text-center">
                        <x-phosphor-list-magnifying-glass class="h-8 w-8 text-zinc-300 dark:text-zinc-600" />
                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $items->isEmpty() ? 'Tidak ada perubahan yang dirinci' : 'Tidak ada perubahan untuk filter ini' }}</p>
                        <p class="max-w-xs text-sm text-zinc-500 dark:text-zinc-400">{{ $items->isEmpty() ? 'Semua perubahan event ini hanya tercatat sebagai hitungan.' : 'Pilih filter lain untuk melihat rincian.' }}</p>
                    </div>
                @endforelse

                @if ($undetailed > 0 && $visibleItems->isNotEmpty())
                    <p class="px-5 py-3 text-center text-xs text-zinc-500 dark:text-zinc-400">+{{ $undetailed }} perubahan lain tidak dirinci (melewati batas rincian per event).</p>
                @endif
            </div>
        </section>
    @endif
</div>
