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
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Halaman yang terindikasi disusupi konten judi online, hasil crawl homepage & sitemap tiap situs.</p>
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
                        <x-phosphor-spinner class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="saveThreshold" />
                        Simpan Ambang Batas
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
                        <x-phosphor-plus class="h-3.5 w-3.5" wire:loading.remove wire:target="addKeyword" />
                        <x-phosphor-spinner class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="addKeyword" />
                        <span wire:loading.remove wire:target="addKeyword">Tambah</span>
                        <span wire:loading wire:target="addKeyword">Menambahkan...</span>
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
            ? "Tidak ada deteksi yang cocok dengan \"{$search}\"."
            : 'Belum ada konten judi online yang terdeteksi.';
    @endphp

    <x-list-card
        :is-empty="$groups->isEmpty()"
        empty-icon="phosphor-shield-check"
        :empty-message="$emptyMessage"
    >
        <x-slot:toolbar>
            <x-search-input model="search" placeholder="Cari URL atau nama situs..." class="sm:max-w-xs" />
            <select wire:model.live="verification" class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                <option value="">Semua Verifikasi</option>
                <option value="confirmed">Terverifikasi AI</option>
                <option value="keyword">Belum diverifikasi AI</option>
            </select>
        </x-slot:toolbar>

        <div class="divide-y divide-zinc-100 transition-opacity dark:divide-zinc-800" wire:loading.class="opacity-40" wire:target="previousPage,nextPage,gotoPage,search">
            @foreach ($groups as $group)
                @php
                    $site = $websites[$group->site_key] ?? null;
                    $items = $detectionsByGroup[\App\Support\DailyGroups::key($group->site_key, $group->day)] ?? collect();
                    $newCount = $items->where('status', 'new')->count();
                @endphp

                <x-list-group
                    :title="$site?->name ?? 'Situs sudah dihapus'"
                    :href="$site ? route('situs.show', $site) : null"
                    :day="\Illuminate\Support\Carbon::parse($group->day)"
                    :count="$group->total"
                    unit="halaman"
                    :open="$loop->first"
                    :tone="$newCount ? 'red' : 'zinc'"
                    icon="phosphor-shield-warning"
                >
                    <x-slot:summary>
                        @if ($newCount)
                            <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-500/10 dark:text-red-400">{{ $newCount }} baru</span>
                        @else
                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">Sudah ditangani</span>
                        @endif
                    </x-slot:summary>

                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($items as $detection)
                            <div class="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-start sm:gap-4 sm:pl-[4.25rem]">
                                <div class="min-w-0 flex-1">
                                    <a href="{{ $detection->url }}" target="_blank" rel="noopener" class="block truncate text-sm font-medium text-zinc-900 hover:text-accent-600 dark:text-white dark:hover:text-accent-400">
                                        {{ $detection->url }}
                                    </a>
                                    <p class="font-mono text-xs text-zinc-400">
                                        {{ app_datetime($detection->detected_at) }}
                                        @if ($detection->page_last_modified_at)
                                            &middot;
                                            <span class="text-amber-600 dark:text-amber-400" title="Header Last-Modified dari server: kapan file halaman ini terakhir ditulis. Ini batas atas - penyusupan bisa terjadi lebih awal, dan penulisan ulang apa pun (restore backup, re-upload) akan menggeser tanggal ini.">
                                                halaman diubah {{ app_datetime($detection->page_last_modified_at) }}
                                            </span>
                                        @endif
                                    </p>
                                    @php
                                        $excerpts = $detection->matchedExcerpts();
                                        $changes = $detection->content_changes;
                                        $badge = $detection->aiBadge();
                                        $hasAiFinding = filled($detection->ai_reasoning);
                                        $linkedDomains = $detection->linked_domains ?? [];
                                    @endphp

                                    {{-- What the gambling content on this page actually says --}}
                                    @if ($excerpts || $changes || $hasAiFinding || $linkedDomains)
                                        <div x-data="{ open: false }" class="mt-2">
                                            <button type="button" @click="open = !open" class="inline-flex items-center gap-1.5 text-xs font-medium text-zinc-500 transition hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                                                <x-phosphor-file-text class="h-3.5 w-3.5" />
                                                <span x-show="!open">{{ $hasAiFinding ? 'Lihat temuan AI & isi konten' : 'Lihat isi konten' }}</span>
                                                <span x-show="open" x-cloak>Sembunyikan detail</span>
                                                @if ($changes)
                                                    <span class="rounded-full bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">berubah</span>
                                                @endif
                                            </button>

                                            <div x-show="open" x-cloak x-transition class="mt-2 space-y-3">
                                                @if ($hasAiFinding)
                                                    <div @class([
                                                        'rounded-lg px-3.5 py-3',
                                                        'bg-accent-50/60 dark:bg-accent-500/5' => $badge['tone'] === 'accent',
                                                        'bg-amber-50/60 dark:bg-amber-500/5' => $badge['tone'] === 'amber',
                                                        'bg-zinc-100/70 dark:bg-zinc-800/40' => $badge['tone'] === 'zinc',
                                                    ])>
                                                        <div class="flex items-start gap-2.5">
                                                            <x-phosphor-sparkle @class([
                                                                'mt-0.5 h-4 w-4 shrink-0',
                                                                'text-accent-500' => $badge['tone'] === 'accent',
                                                                'text-amber-500' => $badge['tone'] === 'amber',
                                                                'text-zinc-400' => $badge['tone'] === 'zinc',
                                                            ]) />
                                                            <div class="min-w-0 flex-1">
                                                                <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">
                                                                    Temuan AI &middot; {{ $badge['label'] }}
                                                                    @if ($detection->ai_confidence !== null)
                                                                        &middot; keyakinan {{ number_format((float) $detection->ai_confidence * 100) }}%
                                                                    @endif
                                                                    @if ($detection->ai_checked_at)
                                                                        &middot; {{ app_datetime($detection->ai_checked_at) }}
                                                                    @endif
                                                                </p>
                                                                <p class="mt-1 whitespace-pre-line text-xs leading-relaxed text-zinc-700 dark:text-zinc-300">{{ $detection->ai_reasoning }}</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif

                                                @if ($linkedDomains)
                                                    <div>
                                                        <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">
                                                            Tautan keluar ke situs judi
                                                        </p>
                                                        {{-- The domains, not the full URLs: the same site is usually
                                                        linked from several paths, and the domain is what has to be
                                                        searched for in the source and stripped out. --}}
                                                        <div class="mt-1 flex flex-wrap gap-1.5">
                                                            @foreach ($linkedDomains as $domain)
                                                                <span class="rounded-md bg-red-50 px-2 py-1 font-mono text-xs text-red-700 dark:bg-red-500/10 dark:text-red-400">{{ $domain }}</span>
                                                            @endforeach
                                                        </div>
                                                        <p class="mt-1.5 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
                                                            Cari domain di atas pada sumber halaman ini - biasanya berupa
                                                            tag &lt;a&gt; tersembunyi yang disisipkan ke template atau isi artikel.
                                                        </p>
                                                    </div>
                                                @endif

                                                @if ($excerpts)
                                                    <div>
                                                        <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">Kutipan konten judi di halaman ini</p>
                                                        <div class="mt-1 space-y-1.5">
                                                            @foreach ($excerpts as $excerpt)
                                                                <p class="rounded-lg bg-white px-3 py-2 text-xs leading-relaxed text-zinc-600 dark:bg-zinc-900/60 dark:text-zinc-300">{{ $excerpt }}</p>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif

                                                {{-- Only present once a re-check found the page different --}}
                                                @if ($changes)
                                                    <div>
                                                        <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">
                                                            Perubahan terdeteksi {{ app_datetime($changes['at']) }}
                                                        </p>
                                                        <div class="mt-1 space-y-1">
                                                            @foreach ($changes['added'] ?? [] as $line)
                                                                <p class="rounded-lg bg-emerald-50/70 px-3 py-1.5 text-xs text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">
                                                                    <span class="font-mono text-emerald-600 dark:text-emerald-400">+</span> {{ $line }}
                                                                </p>
                                                            @endforeach
                                                            @foreach ($changes['removed'] ?? [] as $line)
                                                                <p class="rounded-lg bg-red-50/70 px-3 py-1.5 text-xs text-red-800 line-through dark:bg-red-500/10 dark:text-red-300">
                                                                    <span class="font-mono text-red-600 no-underline dark:text-red-400">-</span> {{ $line }}
                                                                </p>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    @endif

                                    @php $badge = $detection->aiBadge(); @endphp
                                    <p class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                        <span
                                            title="{{ $badge['detail'] }}"
                                            @class([
                                                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                                                'bg-accent-50 text-accent-700 dark:bg-accent-500/10 dark:text-accent-400' => $badge['tone'] === 'accent',
                                                'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' => $badge['tone'] === 'amber',
                                                'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => $badge['tone'] === 'zinc',
                                            ])
                                        >
                                            @if ($badge['tone'] === 'accent')
                                                <x-phosphor-sparkle class="h-3 w-3" />
                                            @endif
                                            {{ $badge['label'] }}
                                        </span>
                                        @foreach ($detection->matched_keywords ?? [] as $keyword)
                                            <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $keyword }}</span>
                                        @endforeach

                                        {{-- A backlink injection matches no keyword at all, so without this the
                                        row would show a bare AI badge and nothing saying what was found. --}}
                                        @if ($count = count($detection->linked_domains ?? []))
                                            <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-500/10 dark:text-red-400">
                                                {{ $count }} tautan ke situs judi
                                            </span>
                                        @endif
                                    </p>
                                    @if ($error = ($recheckErrors[$detection->id] ?? null))
                                        <p class="mt-1.5 flex items-center gap-1.5 text-xs text-red-600 dark:text-red-400">
                                            <x-phosphor-warning-circle class="h-3.5 w-3.5 shrink-0" />
                                            {{ $error }}
                                        </p>
                                    @endif
                                </div>

                                <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                                    <span @class([
                                        'rounded-full px-2.5 py-1 text-xs font-medium',
                                        'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' => $detection->status === 'new',
                                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $detection->status === 'reviewed',
                                        'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => $detection->status === 'ignored',
                                    ])>
                                        {{ match ($detection->status) { 'new' => 'Baru', 'reviewed' => 'Sudah Ditangani', default => 'Diabaikan' } }}
                                    </span>

                                    @can('deteksi-judol.update')
                                        <button
                                            type="button"
                                            wire:click="recheck('{{ $detection->uuid }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="recheck('{{ $detection->uuid }}')"
                                            title="Cek ulang URL ini dengan daftar kata kunci saat ini - kalau sudah bersih, deteksi ini otomatis dihapus"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                        >
                                            <x-phosphor-arrows-clockwise class="h-3.5 w-3.5" wire:loading.remove wire:target="recheck('{{ $detection->uuid }}')" />
                                            <x-phosphor-spinner class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="recheck('{{ $detection->uuid }}')" />
                                            Verifikasi Ulang
                                        </button>

                                        @if ($detection->status === 'new')
                                            <button
                                                type="button"
                                                wire:click="markReviewed('{{ $detection->uuid }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="markReviewed('{{ $detection->uuid }}')"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                            >
                                                <x-phosphor-spinner class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="markReviewed('{{ $detection->uuid }}')" />
                                                Sudah Ditangani
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="markIgnored('{{ $detection->uuid }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="markIgnored('{{ $detection->uuid }}')"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                            >
                                                <x-phosphor-spinner class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="markIgnored('{{ $detection->uuid }}')" />
                                                Abaikan
                                            </button>
                                        @endif
                                    @endcan
                                    @can('deteksi-judol.delete')
                                        <x-confirm-dialog
                                            title="Hapus deteksi ini?"
                                            message="Temuan untuk {{ $detection->url }} akan dihapus permanen dan insidennya ditandai selesai."
                                            confirm-text="Ya, Hapus"
                                            variant="danger"
                                            target="delete('{{ $detection->uuid }}')"
                                        >
                                            <x-slot:trigger>
                                                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-red-50 hover:text-red-600 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-red-500/10 dark:hover:text-red-400">
                                                    <x-phosphor-trash class="h-3.5 w-3.5" />
                                                    Hapus
                                                </button>
                                            </x-slot:trigger>
                                            <form wire:submit="delete('{{ $detection->uuid }}')" x-ref="form"></form>
                                        </x-confirm-dialog>
                                    @endcan
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-list-group>
            @endforeach
        </div>

        <x-slot:footer>
            {{ $groups->links() }}
        </x-slot:footer>
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
                            <x-phosphor-spinner class="h-4 w-4 animate-spin" wire:loading wire:target="bulkAddKeywords" />
                            <span wire:loading.remove wire:target="bulkAddKeywords">Tambahkan</span>
                            <span wire:loading wire:target="bulkAddKeywords">Memproses...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
</div>
