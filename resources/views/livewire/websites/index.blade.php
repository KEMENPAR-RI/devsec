<div class="mx-auto flex max-w-7xl flex-col gap-6" wire:poll.10s.visible>
    @if (session('status'))
        <div class="enter flex items-center gap-2 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
            <x-phosphor-check-circle class="h-4 w-4 shrink-0" />
            {{ session('status') }}
        </div>
    @endif

    {{-- Header --}}
    <div class="enter flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Situs</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Kelola daftar website yang dipantau dan ambang batas deteksinya.</p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2 self-start sm:self-auto">
            @can('situs.delete')
                <x-confirm-dialog
                    title="Hapus Semua Data Situs?"
                    message="Ini juga menghapus seluruh riwayat cek, insiden, dan deteksi judol dari semua situs. Tindakan ini tidak bisa dibatalkan."
                    confirm-text="Ya, Hapus Semua"
                    variant="danger"
                    target="deleteAll"
                >
                    <x-slot:trigger>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3.5 py-2.5 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-red-500/30 dark:text-red-400 dark:hover:bg-red-500/10">
                            <x-phosphor-trash class="h-4 w-4" />
                            Hapus Data
                        </button>
                    </x-slot:trigger>
                    <form wire:submit="deleteAll" x-ref="form"></form>
                </x-confirm-dialog>
            @endcan
            <div class="mx-0.5 h-6 w-px bg-zinc-200 dark:bg-zinc-800"></div>
            @can('situs.create')
                <button
                    type="button"
                    wire:click="openBulk"
                    class="inline-flex items-center gap-2 rounded-lg border border-zinc-200 px-4 py-2.5 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50 active:scale-[0.98] dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                >
                    <x-phosphor-list-plus class="h-4 w-4" />
                    Impor Massal
                </button>
            @endcan
            @can('situs.create')
                <button
                    type="button"
                    wire:click="openCreate"
                    class="inline-flex items-center gap-2 rounded-lg bg-accent-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-accent-700 active:scale-[0.98]"
                >
                    <x-phosphor-plus class="h-4 w-4" />
                    Tambah Situs
                </button>
            @endcan
        </div>
    </div>

    @php
        $emptyTitle = $search ? 'Tidak ada situs yang cocok' : 'Belum ada situs';
        $emptyMessage = $search
            ? "Tidak ada situs yang cocok dengan \"{$search}\"."
            : 'Tambahkan situs pertama untuk mulai memantau down time, serangan, dan konten judol.';
    @endphp

    <x-list-card
        :is-empty="$websites->isEmpty()"
        empty-icon="phosphor-globe"
        :empty-title="$emptyTitle"
        :empty-message="$emptyMessage"
    >
        <x-slot:toolbar>
            <x-search-input model="search" placeholder="Cari nama atau URL situs..." />
        </x-slot:toolbar>

        <div class="divide-y divide-zinc-100 transition-opacity dark:divide-zinc-800" wire:loading.class="opacity-40" wire:target="previousPage,nextPage,gotoPage,search">
                @foreach ($websites as $website)
                    <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:gap-4">
                        <x-status-dot :status="$website->statusKey(isset($offlineIds[$website->id]))" />

                        <div class="min-w-0 flex-1">
                            <a href="{{ route('situs.show', $website) }}" wire:navigate class="truncate text-sm font-medium text-zinc-900 hover:text-accent-600 dark:text-white dark:hover:text-accent-400">
                                {{ $website->name }}
                            </a>
                            <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $website->url }}</p>
                        </div>

                        <div class="flex shrink-0 items-center gap-4 text-xs text-zinc-500 dark:text-zinc-400">
                            <span class="font-mono">{{ $website->uptimePercentage() }}% uptime</span>
                            @unless ($website->is_active)
                                <span class="rounded-full bg-zinc-100 px-2 py-1 font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">Nonaktif</span>
                            @endunless
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            @can('situs.update')
                                <button
                                    type="button"
                                    wire:click="checkNow('{{ $website->uuid }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="checkNow('{{ $website->uuid }}')"
                                    title="Cek sekarang"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 disabled:opacity-60 dark:text-zinc-400 dark:hover:bg-zinc-800"
                                >
                                    <x-phosphor-arrows-clockwise class="h-4 w-4" wire:loading.remove wire:target="checkNow('{{ $website->uuid }}')" />
                                    <x-phosphor-spinner class="h-4 w-4 animate-spin" wire:loading wire:target="checkNow('{{ $website->uuid }}')" />
                                </button>
                            @endcan
                            @can('situs.update')
                                <button
                                    type="button"
                                    wire:click="openEdit('{{ $website->uuid }}')"
                                    title="Ubah"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                                >
                                    <x-phosphor-pencil-simple class="h-4 w-4" />
                                </button>
                            @endcan
                            @can('situs.delete')
                                <x-confirm-dialog
                                    title="Hapus {{ $website->name }}?"
                                    message="Semua riwayat pengecekan & insiden situs ini ikut terhapus. Tindakan ini tidak bisa dibatalkan."
                                    confirm-text="Ya, Hapus"
                                    variant="danger"
                                    target="delete('{{ $website->uuid }}')"
                                >
                                    <x-slot:trigger>
                                        <button type="button" title="Hapus" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-red-50 hover:text-red-600 dark:text-zinc-400 dark:hover:bg-red-500/10 dark:hover:text-red-400">
                                            <x-phosphor-trash class="h-4 w-4" />
                                        </button>
                                    </x-slot:trigger>
                                    <form wire:submit="delete('{{ $website->uuid }}')" x-ref="form"></form>
                                </x-confirm-dialog>
                            @endcan
                        </div>
                    </div>
                @endforeach
        </div>

        <x-slot:footer>
            {{ $websites->links() }}
        </x-slot:footer>
    </x-list-card>

    @canany(['situs.create', 'situs.update'])
        {{-- Add / edit modal --}}
        <div
            x-show="$wire.showModal"
            x-cloak
            class="fixed inset-0 z-[100] flex items-center justify-center p-4"
            @keydown.escape.window="$wire.showModal = false"
            role="dialog"
            aria-modal="true"
        >
            <div x-show="$wire.showModal" x-transition.opacity wire:click="$set('showModal', false)" class="absolute inset-0 bg-zinc-900/50"></div>

            <div
                x-show="$wire.showModal"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                class="relative w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-5 shadow-xl shadow-zinc-900/10 dark:border-zinc-800 dark:bg-zinc-900"
            >
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $editing ? 'Ubah Situs' : 'Tambah Situs' }}</h2>

                <form wire:submit="save" class="mt-4 flex flex-col gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label for="name" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Nama Situs</label>
                        <input
                            id="name" type="text" wire:model="name" placeholder="Contoh: Website Utama"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        >
                        @error('name') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="url" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">URL</label>
                        <input
                            id="url" type="text" wire:model="url" placeholder="https://contoh.com"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        >
                        @error('url') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label for="checkIntervalMinutes" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Interval Cek Uptime (menit)</label>
                            <input
                                id="checkIntervalMinutes" type="number" min="1" wire:model="checkIntervalMinutes"
                                class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            >
                            @error('checkIntervalMinutes') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="judolScanIntervalMinutes" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Interval Scan Judol (menit)</label>
                            <input
                                id="judolScanIntervalMinutes" type="number" min="5" wire:model="judolScanIntervalMinutes"
                                class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            >
                            @error('judolScanIntervalMinutes') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="downThresholdFailures" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Ambang Batas Down</label>
                        <input
                            id="downThresholdFailures" type="number" min="1" wire:model="downThresholdFailures"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        >
                        @error('downThresholdFailures') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="responseTimeThresholdMs" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Ambang Batas Response Time (ms)</label>
                        <input
                            id="responseTimeThresholdMs" type="number" min="200" step="100" wire:model="responseTimeThresholdMs"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        >
                        @error('responseTimeThresholdMs') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <label class="flex items-center gap-2.5 text-sm text-zinc-700 dark:text-zinc-300">
                        <input type="checkbox" wire:model="isActive" class="h-4 w-4 rounded border-zinc-300 text-accent-600 focus:ring-accent-500/30 dark:border-zinc-700">
                        Pantau situs ini
                    </label>

                    <label class="flex items-start gap-2.5 text-sm text-zinc-700 dark:text-zinc-300">
                        <input type="checkbox" wire:model="isInternalOnly" class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-accent-600 focus:ring-accent-500/30 dark:border-zinc-700">
                        <span>
                            Hanya bisa diakses dari jaringan internal/instansi
                            <span class="block text-xs text-zinc-400 dark:text-zinc-500">Kalau gagal dicek dari sini, ditampilkan kuning ("Terbatas") - bukan merah ("Down") - karena kemungkinan memang tidak terjangkau dari luar jaringan itu, bukan benar-benar mati.</span>
                        </span>
                    </label>

                    <div class="mt-2 flex justify-end gap-2">
                        <button type="button" wire:click="$set('showModal', false)" class="rounded-lg px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 active:scale-[0.98] dark:text-zinc-300 dark:hover:bg-zinc-800">
                            Batal
                        </button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="save"
                            class="inline-flex items-center gap-2 rounded-lg bg-accent-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-accent-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-70"
                        >
                            <x-phosphor-spinner class="h-4 w-4 animate-spin" wire:loading wire:target="save" />
                            <span wire:loading.remove wire:target="save">Simpan</span>
                            <span wire:loading wire:target="save">Menyimpan...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endcanany

    @can('situs.create')
        {{-- Bulk import modal --}}
        <div
            x-show="$wire.showBulkModal"
            x-cloak
            class="fixed inset-0 z-[100] flex items-center justify-center p-4"
            @keydown.escape.window="$wire.showBulkModal = false"
            role="dialog"
            aria-modal="true"
        >
            <div x-show="$wire.showBulkModal" x-transition.opacity wire:click="$set('showBulkModal', false)" class="absolute inset-0 bg-zinc-900/50"></div>

            <div
                x-show="$wire.showBulkModal"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                class="relative w-full max-w-lg rounded-2xl border border-zinc-200 bg-white p-5 shadow-xl shadow-zinc-900/10 dark:border-zinc-800 dark:bg-zinc-900"
            >
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Impor Situs Massal</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Satu situs per baris: <code class="rounded bg-zinc-100 px-1 py-0.5 dark:bg-zinc-800">Nama, https://url.com</code> atau URL polos saja (nama otomatis diambil dari domainnya). Interval &amp; ambang batas pakai nilai bawaan - bisa diubah satu-satu lewat "Ubah" nanti.
                </p>

                <form wire:submit="bulkImport" class="mt-4 flex flex-col gap-3">
                    <textarea
                        wire:model="bulkInput"
                        rows="8"
                        placeholder="Website Desa Sukamaju, https://sukamaju.desa.id&#10;https://contoh-lain.go.id&#10;Portal Layanan, https://layanan.contoh.go.id"
                        class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 font-mono text-xs text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                    ></textarea>
                    @error('bulkInput') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

                    @if ($bulkResult)
                        <div class="flex flex-col gap-1.5 rounded-lg bg-zinc-50 p-3 text-xs dark:bg-zinc-800/60">
                            <p class="flex items-center gap-1.5 font-medium text-emerald-600 dark:text-emerald-400">
                                <x-phosphor-check-circle class="h-3.5 w-3.5 shrink-0" />
                                {{ $bulkResult['created'] }} situs ditambahkan
                            </p>
                            @if (count($bulkResult['skipped']))
                                <p class="text-zinc-500 dark:text-zinc-400">
                                    {{ count($bulkResult['skipped']) }} dilewati (sudah terdaftar): {{ implode(', ', $bulkResult['skipped']) }}
                                </p>
                            @endif
                            @if (count($bulkResult['invalid']))
                                <p class="text-red-600 dark:text-red-400">
                                    {{ count($bulkResult['invalid']) }} baris tidak valid: {{ implode(' | ', $bulkResult['invalid']) }}
                                </p>
                            @endif
                        </div>
                    @endif

                    <div class="mt-1 flex justify-end gap-2">
                        <button type="button" wire:click="$set('showBulkModal', false)" class="rounded-lg px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 active:scale-[0.98] dark:text-zinc-300 dark:hover:bg-zinc-800">
                            Tutup
                        </button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="bulkImport"
                            class="inline-flex items-center gap-2 rounded-lg bg-accent-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-accent-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-70"
                        >
                            <x-phosphor-spinner class="h-4 w-4 animate-spin" wire:loading wire:target="bulkImport" />
                            <span wire:loading.remove wire:target="bulkImport">Impor</span>
                            <span wire:loading wire:target="bulkImport">Memproses...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
</div>
