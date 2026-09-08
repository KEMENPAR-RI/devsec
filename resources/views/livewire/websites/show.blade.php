<div class="mx-auto flex max-w-7xl flex-col gap-6" wire:poll.5s.visible>
    @if (session('status'))
        <div class="enter flex items-center gap-2 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
            <x-phosphor-check-circle class="h-4 w-4 shrink-0" />
            {{ session('status') }}
        </div>
    @endif

    @php
        $isOffline = $website->isOffline();
        // Computed once and reused by the dot and the badge below, so they cannot
        // drift apart - each call re-queries incidents.
        $statusKey = $website->statusKey($isOffline, $website->hasTrafficSpike(), $website->wasAttacked());
    @endphp

    {{-- Automatic probing stops after a full day of failures (Website::isPausedForOffline()).
    Said out loud here, because a dashboard that has quietly stopped checking looks
    exactly like one where nothing is wrong. --}}
    @if ($website->isPausedForOffline())
        <div class="enter flex flex-col gap-2 rounded-lg bg-zinc-100 px-4 py-3 text-sm text-zinc-600 sm:flex-row sm:items-center sm:justify-between dark:bg-zinc-800/60 dark:text-zinc-300">
            <div class="flex items-start gap-2">
                <x-phosphor-pause-circle class="mt-0.5 h-4 w-4 shrink-0" />
                <span>Pengecekan otomatis dihentikan karena situs ini sudah down lebih dari 24 jam. Tekan <strong>Cek Sekarang</strong> untuk mencoba lagi - kalau berhasil, pemantauan otomatis berjalan kembali.</span>
            </div>
        </div>
    @endif

    {{-- Header --}}
    <div class="enter flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        {{-- min-w-0 all the way down: without it a flex item refuses to shrink below
        its content, so one long URL widens the page and the whole layout can be
        dragged sideways on a phone. --}}
        <div class="flex min-w-0 items-start gap-3">
            
            <x-status-dot :status="$statusKey" class="mt-1.5" />

            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <a href="{{ route('situs') }}" wire:navigate class="text-sm text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">Situs</a>
                    <x-phosphor-caret-right class="h-3.5 w-3.5 text-zinc-300 dark:text-zinc-600" />
                </div>
                <div class="flex min-w-0 items-center gap-2">
                    <h1 class="min-w-0 truncate text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $website->name }}</h1>
                    {{-- An up internal-only site would otherwise never say so anywhere:
                    the status badge below only reads 'internal' while it is down. --}}
                    @if ($website->is_internal_only && $statusKey !== 'internal')
                        <span title="Kemungkinan hanya bisa diakses dari jaringan internal/instansi - status down dari sini ditampilkan kuning, bukan merah." class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                            <x-phosphor-network class="h-3 w-3" />
                            Jaringan Internal
                        </span>
                    @endif
                </div>

                {{-- The status spelled out under the name. The dot beside the heading
                gives the colour at a glance; this says which status that colour is,
                and what it means - the two reds in particular are only told apart
                by their names. --}}
                <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                    <x-status-badge :status="$statusKey" />
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ \App\Models\Website::statusConfig($statusKey)['description'] }}</span>
                </div>
                {{-- Truncated, not wrapped: the full URL is one tap away on the link
                itself, and a wrapped three-line URL pushes the whole header around. --}}
                <a href="{{ $website->url }}" target="_blank" rel="noopener" title="{{ $website->url }}" class="block truncate text-sm text-zinc-500 hover:text-accent-600 dark:text-zinc-400 dark:hover:text-accent-400">
                    {{ $website->url }}
                </a>
            </div>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            @can('situs.update')
                <button type="button" wire:click="scanJudolNow" wire:loading.attr="disabled" wire:target="scanJudolNow" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-zinc-200 px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    <x-phosphor-shield-warning class="h-4 w-4" wire:loading.remove wire:target="scanJudolNow" />
                    <x-phosphor-spinner class="h-4 w-4 animate-spin" wire:loading wire:target="scanJudolNow" />
                    <span wire:loading.remove wire:target="scanJudolNow">Cek Judol</span>
                    <span wire:loading wire:target="scanJudolNow">Memindai...</span>
                </button>
                <button type="button" wire:click="checkNow" wire:loading.attr="disabled" wire:target="checkNow" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg bg-accent-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-accent-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-70">
                    <x-phosphor-arrows-clockwise class="h-4 w-4" wire:loading.remove wire:target="checkNow" />
                    <x-phosphor-spinner class="h-4 w-4 animate-spin" wire:loading wire:target="checkNow" />
                    <span wire:loading.remove wire:target="checkNow">Cek Sekarang</span>
                    <span wire:loading wire:target="checkNow">Mengecek...</span>
                </button>
            @endcan
        </div>
    </div>

    {{-- KPI row --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="enter rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Uptime 7 Hari</p>
            <p class="mt-2 font-mono text-2xl font-semibold text-zinc-900 dark:text-white">{{ $website->uptimePercentage() }}%</p>
        </div>
        <div class="enter rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Response Time Terakhir</p>
            {{-- Red when the last check breached response_time_threshold_ms (counts as "down",
            see WebsiteChecker::check()) - gray once that's been true for a full day (isOffline()). --}}
            <p @class([
                'mt-2 font-mono text-2xl font-semibold',
                'text-red-600 dark:text-red-400' => $checks->last() && ! $checks->last()->is_up && ! $isOffline,
                'text-zinc-400 dark:text-zinc-500' => $isOffline,
                'text-zinc-900 dark:text-white' => ! $isOffline && (! $checks->last() || $checks->last()->is_up),
            ])>{{ $checks->last()?->response_time_ms ?? '-' }}<span class="text-sm font-normal text-zinc-400">ms</span></p>
        </div>
        <div class="enter rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Pengecekan Terakhir</p>
            <p class="mt-2 text-sm font-medium text-zinc-900 dark:text-white">{{ $website->last_checked_at?->diffForHumans() ?? 'Belum pernah' }}</p>
        </div>
        <div class="enter rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Interval Cek</p>
            <p class="mt-2 text-sm font-medium text-zinc-900 dark:text-white">Setiap {{ $website->check_interval_minutes }} menit</p>
        </div>
    </div>

    {{-- Response time history --}}
    <div class="enter rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Riwayat Response Time</h2>
        @if ($checks->isEmpty())
            <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">Belum ada data pengecekan.</p>
        @else
            @php $max = max(50, $checks->max('response_time_ms')); @endphp
            <div class="mt-6 flex h-32 items-end gap-1">
                @foreach ($checks as $check)
                    <div
                        @class([
                            'flex-1 rounded-sm transition-all duration-300',
                            'bg-emerald-500/80 hover:bg-emerald-500' => $check->is_up,
                            'bg-red-500/80 hover:bg-red-500' => ! $check->is_up,
                        ])
                        style="height: {{ max(6, round($check->response_time_ms / $max * 100)) }}%"
                        title="{{ $check->checked_at->format('d M H:i') }} &middot; {{ $check->response_time_ms }}ms &middot; {{ $check->is_up ? 'Up' : 'Down' }}"
                    ></div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- min-w-0 on both cards: a grid item defaults to min-width:auto, so without it
    a card refuses to shrink below its widest line - one long incident URL or keyword
    list then widens the card past the screen instead of being clipped inside it. --}}
    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Incidents --}}
        <div class="enter min-w-0 rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="px-5 py-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Insiden</h2>
            </div>
            @if ($incidents->isEmpty())
                <p class="px-5 pb-5 text-sm text-zinc-500 dark:text-zinc-400">Belum ada insiden untuk situs ini.</p>
            @else
                <div class="space-y-1 px-3 pb-3 max-h-[300px] overflow-y-auto">
                    @foreach ($incidents as $incident)
                        <div class="rounded-xl px-2.5 py-2.5">
                            <div class="flex items-center gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $incident->title }}</p>
                                    <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $incident->detected_at->format('d M Y, H:i') }}</p>
                                </div>
                                <span @class([
                                    'shrink-0 rounded-full px-2.5 py-1 text-xs font-medium',
                                    'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' => $incident->status === 'open',
                                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $incident->status === 'resolved',
                                ])>
                                    {{ $incident->status === 'open' ? 'Terbuka' : 'Selesai' }}
                                </span>
                            </div>
                            {{-- break-words, not flex-wrap (a no-op outside a flex container):
                            these lines carry full page URLs and comma-joined keyword lists with
                            no spaces to wrap at, which is exactly what pushes the card wide. --}}
                            @if ($incident->description)
                                <p class="mt-1.5 break-words text-xs text-zinc-500 dark:text-zinc-400">{{ $incident->description }}</p>
                            @endif
                            @if ($lines = $incident->detailLines())
                                <p class="mt-1 break-words font-mono text-[11px] text-zinc-400">
                                    {!! collect($lines)->map(fn ($v, $k) => "{$k}: {$v}")->implode('<br>') !!}
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Judol detections --}}
        <div class="enter min-w-0 rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="px-5 py-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Deteksi Judol</h2>
            </div>

            @can('situs.update')
                {{-- Manual URL check: verify one specific page right now (e.g. a stray slot.html) --}}
                <form wire:submit="scanSpecificUrl" class="flex flex-col gap-2 px-5 pb-4 sm:flex-row sm:items-start">
                    <div class="flex-1">
                        <input
                            type="text" wire:model="manualScanUrl" placeholder="{{ parse_url($website->url, PHP_URL_HOST) }}/slot.html"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        >
                        @error('manualScanUrl') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="scanSpecificUrl"
                        class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border border-zinc-200 px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    >
                        <span wire:loading.remove wire:target="scanSpecificUrl">Cek URL Ini</span>
                        <span wire:loading wire:target="scanSpecificUrl">Memindai...</span>
                    </button>
                </form>
            @endcan

            @if ($manualScanResult)
                <div class="mx-5 mb-4 rounded-lg px-4 py-3 text-sm {{ $manualScanResult['matched'] ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' }}">
                    @if ($manualScanResult['matched'])
                        <p class="font-medium">Terdeteksi konten judi online di halaman ini.</p>
                        <p class="mt-1 break-words text-xs">Kata kunci: {{ implode(', ', $manualScanResult['matched']) }}</p>
                        @if ($ai = $manualScanResult['ai'] ?? null)
                            <div class="mt-2 flex items-start gap-2 rounded-lg bg-white/60 px-3 py-2 dark:bg-zinc-900/40">
                                <x-phosphor-sparkle class="mt-0.5 h-3.5 w-3.5 shrink-0 text-accent-500" />
                                <p class="min-w-0 flex-1 break-words text-xs text-zinc-700 dark:text-zinc-300">
                                    <span class="font-medium">{{ $ai['label'] }}</span>
                                    @if ($ai['confidence'] !== null)
                                        <span class="text-zinc-400">&middot; keyakinan {{ number_format((float) $ai['confidence'] * 100) }}%</span>
                                    @endif
                                    @if ($ai['reasoning'])
                                        {{-- The findings arrive as one bullet per line, so they have to
                                        keep their line breaks here the same way the detections list does. --}}
                                        <span class="mt-1 block whitespace-pre-line">{{ $ai['reasoning'] }}</span>
                                    @endif
                                </p>
                            </div>
                        @endif
                    @else
                        <p class="font-medium">Aman, tidak ada kata kunci judi online yang cocok di halaman ini.</p>
                    @endif
                </div>
            @endif

            @if ($judolDetections->isEmpty())
                <p class="px-5 pb-5 text-sm text-zinc-500 dark:text-zinc-400">Belum ada konten judi online terdeteksi.</p>
            @else
                <div class="space-y-1 px-3 pb-3 max-h-[300px] overflow-y-auto">
                    @foreach ($judolDetections as $detection)
                        <div class="min-w-0 rounded-xl px-2.5 py-2.5">
                            {{-- `block`, because truncate cannot clip an inline element. --}}
                            <a href="{{ $detection->url }}" target="_blank" rel="noopener" title="{{ $detection->url }}" class="block truncate text-sm font-medium text-zinc-900 hover:text-accent-600 dark:text-white dark:hover:text-accent-400">
                                {{ $detection->url }}
                            </a>
                            <p class="mt-1 break-words text-xs text-zinc-500 dark:text-zinc-400">{{ implode(', ', $detection->matched_keywords ?? []) }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
