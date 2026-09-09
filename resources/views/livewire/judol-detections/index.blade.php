<div class="mx-auto flex max-w-7xl flex-col gap-6" wire:poll.10s.visible>
    @if (session('status'))
        <div class="enter flex items-center gap-2 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
            <x-phosphor-check-circle class="h-4 w-4 shrink-0" />
            {{ session('status') }}
        </div>
    @endif

    {{-- Header --}}
    <div class="enter flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Deteksi Judol</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Titik penyisipan konten judi online, hasil crawl homepage &amp; sitemap tiap situs - satu temuan per bukti, lengkap dengan letak persisnya di halaman.</p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2 self-start sm:self-auto">
            @can('deteksi-judol.delete')
                <x-confirm-dialog
                    title="Hapus Semua Data Deteksi Judol?"
                    message="Kata kunci yang sudah dikonfigurasi tidak ikut terhapus. Tindakan ini tidak bisa dibatalkan."
                    confirm-text="Ya, Hapus Semua"
                    variant="danger"
                    target="deleteAll"
                >
                    <x-slot:trigger>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3.5 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-red-500/30 dark:text-red-400 dark:hover:bg-red-500/10">
                            <x-phosphor-trash class="h-4 w-4" />
                            Hapus Data
                        </button>
                    </x-slot:trigger>
                    <form wire:submit="deleteAll" x-ref="form"></form>
                </x-confirm-dialog>
            @endcan
        </div>
    </div>

    @if ($queueStalled)
        {{-- Not a scan result - a deployment problem that would otherwise be
        invisible: queued crawls piling up with nothing to run them. --}}
        <div class="enter flex flex-col gap-1 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
            <p class="flex items-center gap-2 font-medium">
                <x-phosphor-warning class="h-4 w-4 shrink-0" />
                Pemindaian otomatis tidak berjalan
            </p>
            <p class="text-xs leading-relaxed">
                Ada {{ $queuePending }} pekerjaan menunggu di antrean dan tidak ada yang mengerjakannya, jadi crawl
                otomatis tidak pernah jalan - meski "Cek Judol" dan "Cek URL Tertentu" tetap berfungsi karena
                keduanya jalan langsung saat diklik. Jalankan worker antrean di server
                (<code class="rounded bg-amber-100 px-1 dark:bg-amber-500/20">php artisan queue:work</code> lewat
                supervisor/systemd), atau pakai
                <code class="rounded bg-amber-100 px-1 dark:bg-amber-500/20">php artisan websites:scan-judol --sync</code>
                di cron kalau server tidak bisa menjalankan worker.
            </p>
        </div>
    @endif

    {{-- Kelola kata kunci & ambang batas --}}
    <div class="enter rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900" x-data="{ open: false }">
        <button type="button" @click="open = !open" class="flex w-full items-center justify-between gap-2 px-5 py-4 text-left">
            <span class="flex items-center gap-2 text-sm font-medium text-zinc-700 dark:text-zinc-200">
                <x-phosphor-sliders-horizontal class="h-4 w-4 text-zinc-400" />
                Kelola Kata Kunci & Ambang Batas
                <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $keywords->count() }} kata kunci</span>
            </span>
            <x-phosphor-caret-down class="h-4 w-4 text-zinc-400 transition" x-bind:class="open ? 'rotate-180' : ''" />
        </button>

        <div x-show="open" x-cloak class="border-t border-zinc-100 px-5 py-4 dark:border-zinc-800">
            @if (session('keyword-status'))
                <div class="mb-4 flex items-center gap-2 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                    <x-phosphor-check-circle class="h-4 w-4 shrink-0" />
                    {{ session('keyword-status') }}
                </div>
            @endif

            {{-- Ambang batas --}}
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:gap-3">
                <div class="flex flex-col gap-1.5">
                    <label for="threshold" class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Ambang batas (jumlah kata kunci unik minimal per halaman)</label>
                    <input
                        wire:model="threshold"
                        id="threshold"
                        type="number"
                        min="1"
                        max="20"
                        class="w-32 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white @error('threshold') border-red-400 @enderror"
                    >
                </div>
                @can('deteksi-judol.update')
                    <button
                        type="button"
                        wire:click="saveThreshold"
                        wire:loading.attr="disabled"
                        wire:target="saveThreshold"
                        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-zinc-200 px-3 py-2 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    >
                        <x-loading-label target="saveThreshold" spinner="h-3.5 w-3.5" gap="gap-1.5">
                            Simpan Ambang Batas
                        </x-loading-label>
                    </button>
                @endcan
                @error('threshold')
                    <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            @can('deteksi-judol.update')
                {{-- Tambah kata kunci --}}
                <form wire:submit="addKeyword" class="mt-5 flex items-start gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                    <div class="flex-1">
                        <input
                            wire:model="newKeyword"
                            type="text"
                            placeholder="mis. slot gacor baru"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-500 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white dark:placeholder:text-zinc-400 @error('newKeyword') border-red-400 @enderror"
                        >
                        @error('newKeyword')
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="addKeyword"
                        class="flex shrink-0 items-center gap-1.5 rounded-lg bg-accent-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-accent-700 disabled:cursor-not-allowed disabled:opacity-70"
                    >
                        <x-loading-label target="addKeyword" loading="Menambahkan..." spinner="h-3.5 w-3.5" gap="gap-1.5">
                            <x-phosphor-plus class="h-3.5 w-3.5 shrink-0" />
                            <span>Tambah</span>
                        </x-loading-label>
                    </button>
                    {{-- A new spam wave arrives as a list, not as one phrase at a time. --}}
                    <button
                        type="button"
                        wire:click="openBulkKeywords"
                        class="flex shrink-0 items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-2 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        title="Tempel banyak kata kunci sekaligus"
                    >
                        <x-phosphor-list-plus class="h-3.5 w-3.5" />
                        Massal
                    </button>
                </form>
            @endcan

            {{-- Daftar kata kunci --}}
            <div class="mt-4 flex max-h-72 flex-wrap gap-1.5 overflow-y-auto rounded-lg border border-zinc-100 p-3 dark:border-zinc-800">
                @forelse ($keywords as $keyword)
                    <span @class([
                        'group inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition',
                        'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => $keyword->is_active,
                        'bg-zinc-50 text-zinc-400 line-through dark:bg-zinc-800/40 dark:text-zinc-600' => ! $keyword->is_active,
                    ])>
                        @can('deteksi-judol.update')
                            <button
                                type="button"
                                wire:click="toggleKeyword('{{ $keyword->uuid }}')"
                                wire:loading.attr="disabled"
                                wire:target="toggleKeyword('{{ $keyword->uuid }}')"
                                :title="'{{ $keyword->is_active ? 'Nonaktifkan' : 'Aktifkan' }}'"
                            >
                                {{ $keyword->keyword }}
                            </button>
                        @else
                            {{-- Read-only: the keyword stays visible, it just cannot be toggled. --}}
                            <span>{{ $keyword->keyword }}</span>
                        @endcan
                        @can('deteksi-judol.delete')
                            <button
                                type="button"
                                wire:click="removeKeyword('{{ $keyword->uuid }}')"
                                wire:loading.attr="disabled"
                                wire:target="removeKeyword('{{ $keyword->uuid }}')"
                                class="text-zinc-400 opacity-0 transition hover:text-red-500 group-hover:opacity-100"
                                title="Hapus"
                            >
                                <x-phosphor-x class="h-3 w-3" wire:loading.remove wire:target="removeKeyword('{{ $keyword->uuid }}')" />
                                <x-phosphor-spinner class="h-3 w-3 animate-spin" wire:loading wire:target="removeKeyword('{{ $keyword->uuid }}')" />
                            </button>
                        @endcan
                    </span>
                @empty
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Belum ada kata kunci.</p>
                @endforelse
            </div>
        </div>
    </div>


    @php
        $emptyMessage = $search
            ? "Tidak ada titik penyisipan yang cocok dengan \"{$search}\"."
            : 'Belum ada konten judi online yang terdeteksi.';
    @endphp

    {{-- Hasil deteksi, dikelompokkan per bukti temuan.

         Grouped on the EVIDENCE rather than on the page. One edit by an attacker
         lands in several regions and shows up on dozens of URLs, and reporting that
         as dozens of rows buries the only fact worth acting on. So the evidence is
         stated once, already open - an operator should not have to click to find out
         what was found - and what hangs underneath it is the list of places to go
         and clean, each with the exact node it sits on. --}}
    <x-list-card
        :is-empty="$groups->isEmpty()"
        empty-icon="phosphor-shield-check"
        :empty-message="$emptyMessage"
    >
        <x-slot:toolbar>
            <x-search-input model="search" placeholder="Cari domain, kata kunci, lokasi, atau URL..." class="sm:max-w-sm" />
            <p class="hidden text-xs text-zinc-400 sm:block">
                Satu entri = satu temuan. Di bawahnya: tempat persis penyisipannya.
            </p>
        </x-slot:toolbar>

        <div class="divide-y divide-zinc-100 transition-opacity dark:divide-zinc-800" wire:loading.class="opacity-40" wire:target="search">
            @foreach ($groups as $group)
                @php
                    // The group's collapse state hangs off its first finding's uuid -
                    // the group key itself is a hash with no row behind it.
                    $anchorKey = $group['findings']->first()->uuid;
                    $isExpanded = $expandedFinding === $anchorKey;
                    $storedUrls = $group['urls'];
                @endphp

                <section class="px-5 py-4" wire:key="grup-{{ $group['key'] }}">
                    {{-- Ringkasan temuan --}}
                    <div class="flex flex-wrap items-center gap-2">
                        <span @class([
                            'rounded-full px-2.5 py-1 text-xs font-medium',
                            'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' => $group['status'] === 'new',
                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $group['status'] !== 'new',
                        ])>
                            {{ $group['status'] === 'new' ? 'Baru' : 'Sudah Ditangani' }}
                        </span>

                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">
                            {{ $group['attack_label'] }}
                        </span>

                        @if ($group['url_count'] > 1)
                            <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-500/10 dark:text-red-400">
                                {{ $group['url_count'] }} halaman
                            </span>
                        @endif

                        <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            {{ $group['findings']->count() }} titik penyisipan
                        </span>

                        <span class="ml-auto font-mono text-xs text-zinc-400">
                            {{ app_datetime($group['last_seen_at']) }}
                        </span>
                    </div>

                    @if ($group['website'])
                        <a href="{{ route('situs.show', $group['website']) }}" wire:navigate class="mt-1 inline-block text-xs text-zinc-400 hover:text-accent-600 dark:hover:text-accent-400">
                            {{ $group['website']->name }}
                        </a>
                    @endif

                    {{-- Detail temuan. Deliberately not behind a disclosure: this is the
                         answer to "apa yang ditemukan", and it is the reason the row is
                         on screen at all. --}}
                    <div class="mt-3 space-y-3 rounded-xl bg-zinc-50 px-4 py-3.5 dark:bg-zinc-800/40">
                        @if ($group['keywords'])
                            <div>
                                <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">Kata kunci ditemukan</p>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($group['keywords'] as $keyword)
                                        <span class="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-900 dark:text-zinc-300">{{ $keyword }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if ($group['domains'])
                            <div>
                                <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">Tautan ke situs judi</p>
                                {{-- The domains, not the full URLs: the same site is usually
                                linked from several paths, and the domain is what has to be
                                searched for in the source and stripped out. --}}
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($group['domains'] as $domain)
                                        <span class="rounded-md bg-red-50 px-2 py-1 font-mono text-xs text-red-700 dark:bg-red-500/10 dark:text-red-400">{{ $domain }}</span>
                                    @endforeach
                                </div>

                                {{-- The anchor text is often the only thing that gives an
                                     injection away: a domain like wicc2024.com reads as a
                                     conference until you see it sits under "Live Draw Macau". --}}
                                @if ($group['anchors'])
                                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                                        @foreach (array_slice($group['anchors'], 0, 6) as $anchor)
                                            <span class="rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ $anchor }}</span>
                                        @endforeach
                                        @if (count($group['anchors']) > 6)
                                            <span class="px-1 py-0.5 text-xs text-zinc-400">+{{ count($group['anchors']) - 6 }} lainnya</span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if ($group['images'])
                            <div>
                                <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">Gambar ditemukan di titik ini</p>
                                <div class="mt-1.5 flex flex-wrap gap-2">
                                    @foreach ($group['images'] as $image)
                                        @php $source = safe_image_urls($image['source']); @endphp
                                        <div x-data="{ broken: false }" class="w-32" wire:key="gambar-{{ $image['finding']->uuid }}-{{ $image['index'] }}">
                                            {{-- Proxied through this origin: the page's CSP is
                                            img-src 'self', so the remote URL would never load. --}}
                                            <img
                                                x-show="!broken"
                                                {{-- x-on:, not @error - Blade owns @error and would
                                                read it as the validation directive. --}}
                                                x-on:error="broken = true"
                                                src="{{ route('deteksi-judol.gambar', ['finding' => $image['finding'], 'index' => $image['index']]) }}"
                                                alt="Gambar pada titik penyisipan"
                                                loading="lazy"
                                                class="h-20 w-32 rounded-lg border border-zinc-200 bg-white object-cover dark:border-zinc-700 dark:bg-zinc-900"
                                            >
                                            <div x-show="broken" x-cloak class="flex h-20 w-32 items-center justify-center rounded-lg border border-dashed border-zinc-300 px-2 text-center text-[11px] leading-tight text-zinc-400 dark:border-zinc-700">
                                                Gambar tidak bisa diambil
                                            </div>
                                            @if ($source)
                                                <a href="{{ $source }}" target="_blank" rel="noopener" class="mt-1 block truncate font-mono text-[10px] text-zinc-400 hover:text-accent-600 dark:hover:text-accent-400" title="{{ $source }}">
                                                    {{ $source }}
                                                </a>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Titik penyisipan: WHERE the evidence above sits, one entry per
                         place that has to be cleaned. --}}
                    <div class="mt-4 space-y-2.5">
                        @foreach ($group['findings'] as $finding)
                            <div class="rounded-xl border border-zinc-200 px-3.5 py-3 dark:border-zinc-800" wire:key="titik-{{ $finding->uuid }}">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:gap-3">
                                    <x-phosphor-crosshair class="mt-0.5 hidden h-4 w-4 shrink-0 text-amber-500 sm:block" />

                                    <div class="min-w-0 flex-1">
                                        <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-zinc-900 dark:text-white">
                                            {{ $finding->region_label }}
                                            @if ($finding->region_selector)
                                                <span class="rounded-md bg-zinc-100 px-1.5 py-0.5 font-mono text-xs font-normal text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $finding->region_selector }}</span>
                                            @endif
                                            @if ($finding->affected_count > 1)
                                                <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-normal text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $finding->affected_count }} halaman</span>
                                            @endif
                                            @if (($elementCount = $finding->affectedElementCount()) > 1)
                                                <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-normal text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">{{ $elementCount }} elemen</span>
                                            @endif
                                            @if ($finding->status !== 'new')
                                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-normal text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">Sudah ditangani</span>
                                            @endif
                                        </p>

                                    </div>

                                    @can('deteksi-judol.update')
                                        <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                                            @if ($finding->status === 'new')
                                                <button
                                                    type="button"
                                                    wire:click="markFindingReviewed('{{ $finding->uuid }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="markFindingReviewed('{{ $finding->uuid }}')"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-2.5 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                                >
                                                    <x-loading-label target="markFindingReviewed('{{ $finding->uuid }}')" spinner="h-3.5 w-3.5" gap="gap-1.5">
                                                        Sudah Ditangani
                                                    </x-loading-label>
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="markFindingIgnored('{{ $finding->uuid }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="markFindingIgnored('{{ $finding->uuid }}')"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-2.5 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                                >
                                                    <x-loading-label target="markFindingIgnored('{{ $finding->uuid }}')" spinner="h-3.5 w-3.5" gap="gap-1.5">
                                                        Abaikan
                                                    </x-loading-label>
                                                </button>
                                            @endif
                                            @can('deteksi-judol.delete')
                                                <x-confirm-dialog
                                                    title="Hapus titik penyisipan ini?"
                                                    message="Temuan di {{ $finding->region_label }} akan dihapus permanen. Halaman yang terdampak tidak ikut terhapus."
                                                    confirm-text="Ya, Hapus"
                                                    variant="danger"
                                                    target="deleteFinding('{{ $finding->uuid }}')"
                                                >
                                                    <x-slot:trigger>
                                                        <button type="button" title="Hapus titik penyisipan" class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-2.5 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-red-50 hover:text-red-600 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-red-500/10 dark:hover:text-red-400">
                                                            <x-phosphor-trash class="h-3.5 w-3.5" />
                                                        </button>
                                                    </x-slot:trigger>
                                                    <form wire:submit="deleteFinding('{{ $finding->uuid }}')" x-ref="form"></form>
                                                </x-confirm-dialog>
                                            @endcan
                                        </div>
                                    @endcan
                                </div>

                                {{-- Every element this injection touched, each with the route
                                     down to it and the markup around it, escaped and with the
                                     injected part marked. This is what "letak persisnya" means
                                     without a headless browser: the nodes themselves, not a
                                     picture of the page - and ALL of them, because a list that
                                     shows one of eight gets seven of them left behind.

                                     Grouped by markup rather than listed flat: sibling elements
                                     inside one block share a container, so the snippet would
                                     otherwise repeat identically and bury the paths, which are
                                     the only thing that differs. See
                                     JudolFinding::evidenceOccurrences(). --}}
                                @foreach ($finding->evidenceOccurrences() as $occurrence)
                                    <div class="mt-2.5">
                                        @if ($occurrence['paths'])
                                            <ul class="space-y-0.5" title="Jalur elemen di dalam halaman - tempel ke DevTools atau cari di berkas tema">
                                                @foreach ($occurrence['paths'] as $path)
                                                    <li class="flex items-start gap-1.5 break-all font-mono text-[11px] leading-relaxed text-zinc-500 dark:text-zinc-400">
                                                        <x-phosphor-arrow-elbow-down-right class="mt-0.5 h-3 w-3 shrink-0 text-zinc-400 dark:text-zinc-600" />
                                                        <span>{{ $path }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif

                                        <x-evidence-snippet
                                            class="mt-1.5"
                                            :html="$occurrence['html']"
                                            :terms="$finding->highlightTerms()"
                                        />
                                    </div>
                                @endforeach

                                @if ($error = ($recheckErrors[$finding->uuid] ?? null))
                                    <p class="mt-2 flex items-start gap-1.5 text-xs text-red-600 dark:text-red-400">
                                        <x-phosphor-warning-circle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                        {{ $error }}
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    {{-- Stated once for the group: a sitewide injection is one fix in the
                         template, and repeating it under every region implies otherwise. --}}
                    <p class="mt-3 flex items-start gap-1.5 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
                        <x-phosphor-wrench class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        <span>{{ $group['findings']->first()->remediation() }}</span>
                    </p>

                    @if ($storedUrls)
                        <button
                            type="button"
                            wire:click="toggleFinding('{{ $anchorKey }}')"
                            class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-accent-600 hover:underline dark:text-accent-400"
                        >
                            {{ $isExpanded ? 'Sembunyikan' : 'Lihat' }} {{ $group['url_count'] }} halaman terdampak
                            <x-phosphor-caret-down @class(['h-3 w-3 transition', 'rotate-180' => $isExpanded]) />
                        </button>

                        @if ($isExpanded)
                            <ul class="mt-2 max-h-60 space-y-1 overflow-y-auto rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/50">
                                @foreach ($storedUrls as $entry)
                                    <li class="flex items-center gap-2">
                                        <a href="{{ $entry['url'] }}" target="_blank" rel="noopener" class="min-w-0 flex-1 truncate font-mono text-xs text-zinc-600 hover:text-accent-600 dark:text-zinc-300 dark:hover:text-accent-400">
                                            {{ $entry['url'] }}
                                        </a>
                                        @can('deteksi-judol.update')
                                            <button
                                                type="button"
                                                wire:click="recheckUrl('{{ $entry['finding']->uuid }}', {{ $entry['index'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="recheckUrl('{{ $entry['finding']->uuid }}', {{ $entry['index'] }})"
                                                title="Cek ulang URL ini dengan daftar kata kunci saat ini - kalau sudah bersih, deteksi lamanya otomatis dihapus"
                                                class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-zinc-200 px-2 py-1 text-[11px] font-medium text-zinc-600 transition hover:bg-white disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                            >
                                                <x-phosphor-arrows-clockwise class="h-3 w-3" wire:loading.remove wire:target="recheckUrl('{{ $entry['finding']->uuid }}', {{ $entry['index'] }})" />
                                                <x-phosphor-spinner class="h-3 w-3 animate-spin" wire:loading wire:target="recheckUrl('{{ $entry['finding']->uuid }}', {{ $entry['index'] }})" />
                                                Verifikasi Ulang
                                            </button>
                                        @endcan
                                    </li>
                                @endforeach
                                {{-- The stored list is capped per finding; url_count carries the real total. --}}
                                @if ($group['url_count'] > count($storedUrls))
                                    <li class="pt-1 text-xs text-zinc-400">
                                        ...dan {{ $group['url_count'] - count($storedUrls) }} halaman lain.
                                    </li>
                                @endif
                            </ul>
                        @endif
                    @endif
                </section>
            @endforeach
        </div>
    </x-list-card>

    @can('deteksi-judol.update')
        {{-- Impor kata kunci massal --}}
        <div
            x-show="$wire.showBulkKeywordModal"
            x-cloak
            class="fixed inset-0 z-[100] flex items-center justify-center p-4"
            @keydown.escape.window="$wire.showBulkKeywordModal = false"
            role="dialog"
            aria-modal="true"
        >
            <div x-show="$wire.showBulkKeywordModal" x-transition.opacity wire:click="$set('showBulkKeywordModal', false)" class="absolute inset-0 bg-zinc-900/50"></div>

            <div
                x-show="$wire.showBulkKeywordModal"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                class="relative w-full max-w-lg rounded-2xl border border-zinc-200 bg-white p-5 shadow-xl shadow-zinc-900/10 dark:border-zinc-800 dark:bg-zinc-900"
            >
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Tambah Kata Kunci Massal</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Pisahkan dengan baris baru atau koma. Tanda kutip, tanda hubung, dan koma di ujung baris
                    diabaikan - jadi tempelan langsung dari laporan, kolom spreadsheet, atau dari
                    <code class="rounded bg-zinc-100 px-1 py-0.5 dark:bg-zinc-800">config/judol.php</code> bisa masuk apa adanya.
                    Semua kata kunci baru langsung aktif.
                </p>

                <form wire:submit="bulkAddKeywords" class="mt-4 flex flex-col gap-3">
                    <textarea
                        wire:model="bulkKeywords"
                        rows="8"
                        placeholder="slot gacor hari ini&#10;'link alternatif toto', 'rtp live'&#10;- bandar togel terpercaya"
                        class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 font-mono text-xs text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                    ></textarea>
                    @error('bulkKeywords') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

                    @if ($bulkKeywordResult)
                        <div class="flex flex-col gap-1.5 rounded-lg bg-zinc-50 p-3 text-xs dark:bg-zinc-800/60">
                            <p class="flex items-center gap-1.5 font-medium text-emerald-600 dark:text-emerald-400">
                                <x-phosphor-check-circle class="h-3.5 w-3.5 shrink-0" />
                                {{ $bulkKeywordResult['added'] }} kata kunci ditambahkan
                            </p>
                            @if (count($bulkKeywordResult['duplicate']))
                                <p class="text-zinc-500 dark:text-zinc-400">
                                    {{ count($bulkKeywordResult['duplicate']) }} dilewati (sudah ada): {{ implode(', ', $bulkKeywordResult['duplicate']) }}
                                </p>
                            @endif
                            @if (count($bulkKeywordResult['invalid']))
                                <p class="text-red-600 dark:text-red-400">
                                    {{ count($bulkKeywordResult['invalid']) }} tidak valid (lebih dari 100 karakter): {{ implode(' | ', $bulkKeywordResult['invalid']) }}
                                </p>
                            @endif
                        </div>
                    @endif

                    <div class="mt-1 flex justify-end gap-2">
                        <button type="button" wire:click="$set('showBulkKeywordModal', false)" class="rounded-lg px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 active:scale-[0.98] dark:text-zinc-300 dark:hover:bg-zinc-800">
                            Tutup
                        </button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="bulkAddKeywords"
                            class="inline-flex items-center gap-2 rounded-lg bg-accent-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-accent-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-70"
                        >
                            <x-loading-label target="bulkAddKeywords" loading="Memproses...">Tambahkan</x-loading-label>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
</div>
