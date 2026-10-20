@php
    $statusLabels = [
        'open' => ['label' => 'Terbuka', 'class' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/30'],
        'recovered' => ['label' => 'Kembali normal', 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/30'],
        'legitimate' => ['label' => 'Ditandai sah', 'class' => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-400 dark:ring-sky-500/30'],
        'resolved' => ['label' => 'Selesai', 'class' => 'bg-zinc-100 text-zinc-600 ring-zinc-500/20 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-600/40'],
    ];
    $reasonLabels = [
        'signature' => 'Jejak peretas',
        'replaced' => 'Konten terganti',
        'shrink' => 'Konten menyusut drastis',
        'foreign_script' => 'Skrip asing',
        'hidden_frame' => 'Frame tersembunyi',
        'obfuscated' => 'Kode tersamar',
    ];
    $kindLabels = ['content' => 'Konten', 'injection' => 'Injeksi kode'];
    $inputClass = 'w-28 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white';
    $selectClass = 'rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200';
@endphp

<div class="mx-auto flex max-w-7xl flex-col gap-6" wire:poll.30s.visible>
    {{-- Header --}}
    <div class="enter">
        <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Defacement</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Halaman yang isinya diganti, menyusut drastis, memuat jejak peretas, atau disisipi kode asing - dibandingkan dengan kondisi normal terakhir (baseline) halaman itu sendiri.</p>
    </div>

    {{-- Ambang deteksi --}}
    <div class="enter rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900" x-data="{ open: false }">
        <button type="button" @click="open = !open" :aria-expanded="open" class="flex w-full items-center justify-between gap-2 px-5 py-4 text-left">
            <span class="flex items-center gap-2 text-sm font-medium text-zinc-700 dark:text-zinc-200">
                <x-phosphor-sliders-horizontal class="h-4 w-4 text-zinc-400" />
                Ambang Deteksi
                <span class="hidden rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-500 sm:inline dark:bg-zinc-800 dark:text-zinc-400">terganti {{ $replacePercent }}% &middot; menyusut {{ $shrinkPercent }}%</span>
            </span>
            <x-phosphor-caret-down class="h-4 w-4 text-zinc-400 transition" x-bind:class="open ? 'rotate-180' : ''" />
        </button>

        <div x-show="open" x-cloak class="border-t border-zinc-100 px-5 py-4 dark:border-zinc-800">
            <form wire:submit="saveThresholds" class="flex flex-col gap-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="flex flex-col gap-1.5">
                        <label for="replacePercent" class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Konten terganti (%)</label>
                        <input wire:model="replacePercent" id="replacePercent" type="number" min="30" max="95" class="{{ $inputClass }} @error('replacePercent') border-red-400 @enderror">
                        <p class="text-xs text-zinc-400">Porsi teks baseline yang hilang, disertai judul halaman berubah. 30-95.</p>
                        @error('replacePercent') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="shrinkPercent" class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Konten menyusut (%)</label>
                        <input wire:model="shrinkPercent" id="shrinkPercent" type="number" min="50" max="99" class="{{ $inputClass }} @error('shrinkPercent') border-red-400 @enderror">
                        <p class="text-xs text-zinc-400">Halaman tiba-tiba jauh lebih pendek dari biasanya. 50-99.</p>
                        @error('shrinkPercent') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="minBaselineChars" class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Panjang baseline minimal (karakter)</label>
                        <input wire:model="minBaselineChars" id="minBaselineChars" type="number" min="100" max="5000" class="{{ $inputClass }} @error('minBaselineChars') border-red-400 @enderror">
                        <p class="text-xs text-zinc-400">Halaman yang lebih pendek hanya dinilai dari jejak peretas. 100-5000.</p>
                        @error('minBaselineChars') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>
                @can('defacement.update')
                    <div>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="saveThresholds"
                            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-zinc-200 px-3 py-2 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        >
                            <x-loading-label target="saveThresholds" spinner="h-3.5 w-3.5" gap="gap-1.5">
                                Simpan Ambang
                            </x-loading-label>
                        </button>
                    </div>
                @endcan
            </form>
        </div>
    </div>

    <x-list-card
        :is-empty="$findings->isEmpty()"
        :empty-icon="$status === 'open' ? 'phosphor-shield-check' : 'phosphor-tray'"
        :empty-title="$status === 'open' ? 'Tidak ada defacement terbuka' : 'Tidak ada temuan'"
        :empty-message="$status === 'open' ? 'Semua halaman yang dipantau sesuai dengan baseline-nya.' : 'Tidak ada temuan yang cocok dengan filter ini.'"
    >
        <x-slot:toolbar>
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                <label class="sr-only" for="filter-site">Situs</label>
                <select id="filter-site" wire:model.live="site" class="{{ $selectClass }}">
                    <option value="">Semua Situs</option>
                    @foreach ($websites as $website)
                        <option value="{{ $website->uuid }}">{{ $website->name }}</option>
                    @endforeach
                </select>
                <label class="sr-only" for="filter-status">Status</label>
                <select id="filter-status" wire:model.live="status" class="{{ $selectClass }}">
                    <option value="">Semua Status</option>
                    @foreach ($statusLabels as $value => $config)
                        <option value="{{ $value }}">{{ $config['label'] }}</option>
                    @endforeach
                </select>
                <label class="sr-only" for="filter-kind">Jenis</label>
                <select id="filter-kind" wire:model.live="kind" class="{{ $selectClass }}">
                    <option value="">Semua Jenis</option>
                    @foreach ($kindLabels as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <p class="hidden text-xs text-zinc-400 lg:block">Satu entri = satu halaman, diperbarui tiap crawl.</p>
        </x-slot:toolbar>

        <div class="divide-y divide-zinc-100 transition-opacity dark:divide-zinc-800" wire:loading.class="opacity-40" wire:target="site, status, kind, gotoPage, nextPage, previousPage">
            @foreach ($findings as $finding)
                @php
                    $statusConfig = $statusLabels[$finding->status] ?? $statusLabels['resolved'];
                    $isOpen = $finding->status === 'open';
                    $pageUrl = safe_image_urls($finding->url);
                    $hasCompare = filled($finding->before_title) || filled($finding->before_excerpt) || filled($finding->after_title) || filled($finding->after_excerpt);
                @endphp

                <section @class(['px-5 py-4', 'border-l-2 border-red-500' => $isOpen, 'border-l-2 border-transparent' => ! $isOpen]) wire:key="defacement-{{ $finding->uuid }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $statusConfig['class'] }}">
                                    {{ $statusConfig['label'] }}
                                </span>
                                @if ($finding->website)
                                    <a href="{{ route('situs.show', $finding->website) }}" wire:navigate class="text-sm font-semibold text-zinc-900 hover:text-accent-600 dark:text-white dark:hover:text-accent-400">
                                        {{ $finding->website->name }}
                                    </a>
                                @endif
                                <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                    {{ $kindLabels[$finding->kind] ?? $finding->kind }}
                                </span>
                                @if ($finding->change_ratio !== null)
                                    <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400" title="Porsi teks baseline yang tidak lagi ada di halaman">
                                        {{ round((float) $finding->change_ratio * 100) }}% terganti
                                    </span>
                                @endif
                                @if ($finding->incident)
                                    <a href="{{ route('insiden', ['type' => 'attack']) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-accent-600 hover:underline dark:text-accent-400">
                                        <x-phosphor-bell-ringing class="h-3.5 w-3.5" />
                                        Insiden {{ $finding->incident->status === 'open' ? 'terbuka' : 'selesai' }}
                                    </a>
                                @endif
                            </div>

                            @if ($pageUrl)
                                <a href="{{ $pageUrl }}" target="_blank" rel="noopener noreferrer" class="mt-1.5 flex min-w-0 items-center gap-1 font-mono text-xs text-zinc-500 hover:text-accent-600 dark:text-zinc-400 dark:hover:text-accent-400" title="{{ $finding->url }}">
                                    <span class="truncate">{{ $finding->url }}</span>
                                    <x-phosphor-arrow-square-out class="h-3 w-3 shrink-0" />
                                </a>
                            @else
                                <p class="mt-1.5 truncate font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $finding->url }}</p>
                            @endif

                            @if ($finding->reasons)
                                <ul class="mt-2.5 flex flex-wrap gap-1.5">
                                    @foreach ($finding->reasons as $reason)
                                        <li class="inline-flex max-w-full items-center gap-1 rounded-md bg-amber-50 px-2 py-1 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                                            <x-phosphor-warning class="h-3.5 w-3.5 shrink-0" />
                                            <span class="font-medium">{{ $reasonLabels[$reason['code'] ?? ''] ?? ($reason['code'] ?? 'Lainnya') }}</span>
                                            @if (filled($reason['detail'] ?? null))
                                                <span class="truncate text-amber-700/80 dark:text-amber-300/80">- {{ $reason['detail'] }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                                Pertama terlihat {{ app_datetime($finding->first_seen_at) }} &middot; terakhir {{ app_datetime($finding->last_seen_at) }}
                                @if ($finding->resolved_at)
                                    &middot; ditutup {{ app_datetime($finding->resolved_at) }}
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                            @can('defacement.update')
                                @if ($isOpen)
                                    <x-confirm-dialog
                                        title="Tandai perubahan ini sah?"
                                        message="Kondisi halaman saat ini dijadikan baseline baru, jadi crawl berikutnya membandingkan dengan isi ini. Gunakan hanya bila perubahan memang dilakukan pengelola situs."
                                        confirm-text="Ya, Tandai Sah"
                                        variant="success"
                                        target="markLegitimate('{{ $finding->uuid }}', '{{ $finding->snapshot?->content_hash }}')"
                                    >
                                        <x-slot:trigger>
                                            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg bg-accent-600 px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-accent-700">
                                                <x-phosphor-seal-check class="h-3.5 w-3.5" />
                                                Tandai Sah
                                            </button>
                                        </x-slot:trigger>
                                        <form wire:submit="markLegitimate('{{ $finding->uuid }}', '{{ $finding->snapshot?->content_hash }}')" x-ref="form"></form>
                                    </x-confirm-dialog>
                                    <x-confirm-dialog
                                        title="Tandai sudah ditangani?"
                                        message="Temuan ditutup tanpa mengubah baseline - bila halaman masih belum pulih, crawl berikutnya akan membukanya lagi."
                                        confirm-text="Ya, Selesai"
                                        target="markResolved('{{ $finding->uuid }}')"
                                    >
                                        <x-slot:trigger>
                                            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-2.5 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                                                <x-phosphor-check-circle class="h-3.5 w-3.5" />
                                                Selesai
                                            </button>
                                        </x-slot:trigger>
                                        <form wire:submit="markResolved('{{ $finding->uuid }}')" x-ref="form"></form>
                                    </x-confirm-dialog>
                                @endif
                            @endcan
                            @can('defacement.delete')
                                <x-confirm-dialog
                                    title="Hapus temuan ini?"
                                    message="Temuan defacement untuk halaman ini akan dihapus permanen. Baseline halaman tidak ikut berubah."
                                    confirm-text="Ya, Hapus"
                                    variant="danger"
                                    target="delete('{{ $finding->uuid }}')"
                                >
                                    <x-slot:trigger>
                                        <button type="button" title="Hapus temuan" aria-label="Hapus temuan" class="inline-flex items-center rounded-lg border border-zinc-200 px-2.5 py-1.5 text-xs text-zinc-600 transition hover:bg-red-50 hover:text-red-600 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-red-500/10 dark:hover:text-red-400">
                                            <x-phosphor-trash class="h-3.5 w-3.5" />
                                        </button>
                                    </x-slot:trigger>
                                    <form wire:submit="delete('{{ $finding->uuid }}')" x-ref="form"></form>
                                </x-confirm-dialog>
                            @endcan
                        </div>
                    </div>

                    @if ($hasCompare)
                        {{-- Before vs after: crawled text, always escaped. --}}
                        <div class="mt-3 grid gap-2 sm:grid-cols-2" x-data="{ full: false }">
                            <div class="min-w-0 rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-800/40">
                                <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">Sebelum (baseline)</p>
                                @if (filled($finding->before_title))
                                    <p class="mt-1.5 break-words text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $finding->before_title }}</p>
                                @endif
                                @if (filled($finding->before_excerpt))
                                    <p class="mt-1 whitespace-pre-wrap break-words text-xs leading-relaxed text-zinc-600 dark:text-zinc-400" :class="full ? '' : 'line-clamp-4'">{{ $finding->before_excerpt }}</p>
                                @elseif (blank($finding->before_title))
                                    <p class="mt-1.5 text-xs italic text-zinc-400">Belum ada baseline.</p>
                                @endif
                            </div>
                            <div @class([
                                'min-w-0 rounded-xl px-4 py-3',
                                'bg-red-50 dark:bg-red-500/10' => $isOpen,
                                'bg-zinc-50 dark:bg-zinc-800/40' => ! $isOpen,
                            ])>
                                <p @class(['text-[11px] font-medium uppercase tracking-wide', 'text-red-500 dark:text-red-400' => $isOpen, 'text-zinc-400' => ! $isOpen])>Sesudah (terdeteksi)</p>
                                @if (filled($finding->after_title))
                                    <p class="mt-1.5 break-words text-sm font-medium text-zinc-900 dark:text-white">{{ $finding->after_title }}</p>
                                @endif
                                @if (filled($finding->after_excerpt))
                                    <p class="mt-1 whitespace-pre-wrap break-words text-xs leading-relaxed text-zinc-700 dark:text-zinc-300" :class="full ? '' : 'line-clamp-4'">{{ $finding->after_excerpt }}</p>
                                @endif
                            </div>
                            @if (mb_strlen((string) $finding->before_excerpt) > 280 || mb_strlen((string) $finding->after_excerpt) > 280)
                                <button type="button" @click="full = !full" class="justify-self-start text-xs font-medium text-accent-600 hover:underline sm:col-span-2 dark:text-accent-400">
                                    <span x-text="full ? 'Ringkas cuplikan' : 'Tampilkan cuplikan penuh'">Tampilkan cuplikan penuh</span>
                                </button>
                            @endif
                        </div>
                    @endif
                </section>
            @endforeach
        </div>

        <x-slot:footer>
            {{ $findings->links() }}
        </x-slot:footer>
    </x-list-card>
</div>
