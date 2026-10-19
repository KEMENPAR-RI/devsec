<div class="mx-auto flex max-w-7xl flex-col gap-6" wire:poll.15s.visible>
    @php
        $isOffline = $website->isOffline();
        // Computed once and reused by the dot and the badge below, so they cannot
        // drift apart - each call re-queries incidents.
        $statusKey = $website->statusKey($isOffline, $website->wasAttacked(), $underMaintenance);
        $loadLevel = $website->loadLevel();

        // Uptime history: view data or computed props, whichever the component exposes.
        $series ??= $this->series;
        $rangeMetrics ??= $this->rangeMetrics;
        $uptimeSummary ??= $this->uptimeSummary;
        $downtimes = collect($downtimes ?? $this->downtimes);
        $downtimeTotal30d ??= $this->downtimeTotal30d;
        $verification ??= $this->verification;

        $ranges = ['1h' => 'Jam', '24h' => 'Hari', '7d' => 'Minggu', '30d' => 'Bulan'];
        $rangeSpan = ['1h' => '1 jam', '24h' => '24 jam', '7d' => '7 hari', '30d' => '30 hari'][$this->range] ?? '24 jam';
        $rangeBucket = ['1h' => 'per 5 menit', '24h' => 'per jam', '7d' => 'per 6 jam', '30d' => 'per hari'][$this->range] ?? 'per jam';

        $errorLabels = [
            'timeout' => 'Timeout',
            'dns' => 'DNS gagal',
            'connect' => 'Koneksi gagal',
            'ssl' => 'SSL/TLS bermasalah',
            'http' => 'Error HTTP',
            'slow' => 'Respons lambat',
            'blocked' => 'Alamat diblokir',
        ];

        // 100 / 99.9 / 99.95 - null stays "-", never a fake 100%.
        $fmtUptime = fn (?float $u) => $u === null ? '-' : rtrim(rtrim(number_format($u, 2), '0'), '.').'%';
        $uptimeTone = fn (?float $u) => match (true) {
            $u === null => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
            $u >= 99 => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
            $u >= 95 => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
            default => 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400',
        };
        // 45 dtk / 12m / 1j 12m / 2 hari 3j
        $fmtDuration = function (int $s): string {
            if ($s < 60) {
                return max(0, $s).' dtk';
            }
            $d = intdiv($s, 86400);
            $h = intdiv($s % 86400, 3600);
            $m = intdiv($s % 3600, 60);

            return collect([$d ? $d.' hari' : null, $h ? $h.'j' : null, ! $d && $m ? $m.'m' : null])->filter()->implode(' ');
        };

        $cardHead = 'flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800';
    @endphp

    {{-- Scheduled maintenance: the one banner that explains why a red site is not
    generating incidents or email right now. --}}
    @if ($underMaintenance)
        <div class="enter flex items-start gap-2 rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-700 dark:bg-sky-500/10 dark:text-sky-400">
            <x-phosphor-wrench class="mt-0.5 h-4 w-4 shrink-0" />
            <span>Situs ini sedang dalam <strong>jendela pemeliharaan terjadwal</strong>. Pengecekan tetap berjalan dan riwayatnya tetap dicatat, tetapi insiden baru dan notifikasi ditahan sampai jendela berakhir.</span>
        </div>
    @endif

    {{-- After a full day of failures (Website::isPausedForOffline()) probing continues at effectiveCheckIntervalMinutes().
    Said out loud here, because a dashboard that has quietly stopped checking looks
    exactly like one where nothing is wrong. --}}
    @if ($website->isPausedForOffline())
        <div class="enter flex flex-col gap-2 rounded-lg bg-zinc-100 px-4 py-3 text-sm text-zinc-600 sm:flex-row sm:items-center sm:justify-between dark:bg-zinc-800/60 dark:text-zinc-300">
            <div class="flex items-start gap-2">
                <x-phosphor-pause-circle class="mt-0.5 h-4 w-4 shrink-0" />
                <span>Situs sudah down lebih dari 24 jam. Tetap dicek otomatis tiap {{ $website->effectiveCheckIntervalMinutes() }} menit; notifikasi tidak diulang sampai situs pulih.</span>
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
                    <x-priority-badge :priority="$website->is_priority" secondary />
                </div>

                {{-- The status spelled out under the name. The dot beside the heading
                gives the colour at a glance; this says which status that colour is,
                and what it means - the two reds in particular are only told apart
                by their names. --}}
                <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                    <x-status-badge :status="$statusKey" />
                    @if ($verification)
                        <span title="Pengecekan gagal, sedang diulang tiap menit sebelum insiden dibuka." class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium whitespace-nowrap text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-400/30">
                            <x-phosphor-circle-notch class="h-3 w-3 shrink-0 motion-safe:animate-spin" aria-hidden="true" />
                            Memverifikasi {{ $verification['failures'] }}/{{ $verification['threshold'] }}
                        </span>
                    @endif
                    {{-- No latency in the tooltip when the level is unknown (last check failed / none yet). --}}
                    <x-load-badge :level="$loadLevel" :response-time="$loadLevel === 'unknown' ? null : $checks->last()?->response_time_ms" />
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ \App\Models\Website::statusConfig($statusKey)['description'] }}</span>
                </div>
                {{-- Truncated, not wrapped: the full URL is one tap away on the link
                itself, and a wrapped three-line URL pushes the whole header around. --}}
                <a href="{{ $website->url }}" target="_blank" rel="noopener" title="{{ $website->url }}" class="block truncate text-sm text-zinc-500 hover:text-accent-600 dark:text-zinc-400 dark:hover:text-accent-400">
                    {{ $website->url }}
                </a>

                {{-- Multi-period uptime + 30-day downtime total --}}
                <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
                    @foreach (['24h' => '24 jam', '7d' => '7 hari', '30d' => '30 hari'] as $key => $label)
                        @php $uptime = isset($uptimeSummary[$key]) ? (float) $uptimeSummary[$key] : null; @endphp
                        <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-medium {{ $uptimeTone($uptime) }}">
                            <span class="font-normal opacity-80">Uptime {{ $label }}</span>
                            <span class="font-mono tabular-nums">{{ $fmtUptime($uptime) }}</span>
                        </span>
                    @endforeach
                    <span class="px-1 text-zinc-500 dark:text-zinc-400">
                        Total downtime 30 hari:
                        <span class="font-mono font-medium tabular-nums text-zinc-700 dark:text-zinc-200">{{ $downtimeTotal30d > 0 ? $fmtDuration((int) $downtimeTotal30d) : '0m' }}</span>
                    </span>
                </div>
            </div>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            @can('situs.update')
                <button type="button" wire:click="scanJudolNow" wire:loading.attr="disabled" wire:target="scanJudolNow" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-zinc-200 px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    <x-loading-label target="scanJudolNow" loading="Memindai...">
                        <x-phosphor-shield-warning class="h-4 w-4 shrink-0" />
                        <span>Cek Judol</span>
                    </x-loading-label>
                </button>
                <button type="button" wire:click="checkNow" wire:loading.attr="disabled" wire:target="checkNow" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg bg-accent-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-accent-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-70">
                    <x-loading-label target="checkNow" loading="Mengecek...">
                        <x-phosphor-arrows-clockwise class="h-4 w-4 shrink-0" />
                        <span>Cek Sekarang</span>
                    </x-loading-label>
                </button>
            @endcan
        </div>
    </div>

    {{-- KPI row --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="enter rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            {{-- Follows the range selected on the chart below --}}
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Uptime {{ $rangeSpan }}</p>
            <p class="mt-2 font-mono text-2xl font-semibold text-zinc-900 dark:text-white">{{ $fmtUptime(isset($rangeMetrics['uptime']) ? (float) $rangeMetrics['uptime'] : null) }}</p>
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
    <section class="enter min-w-0 rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="{{ $cardHead }}">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Riwayat Response Time</h2>
                <p class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400">
                    Rata-rata {{ $rangeBucket }}, {{ $rangeSpan }} terakhir
                    @if (isset($rangeMetrics['avg_ms']))
                        &middot; rata-rata <span class="font-mono tabular-nums">{{ number_format($rangeMetrics['avg_ms']) }}ms</span>
                    @endif
                </p>
            </div>

            {{-- Range segmented control, same as the dashboard --}}
            <div role="radiogroup" aria-label="Rentang waktu" class="inline-flex self-start rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800">
                @foreach ($ranges as $key => $label)
                    <label class="cursor-pointer">
                        <input type="radio" name="site-range" value="{{ $key }}" wire:model.live="range" class="peer sr-only">
                        <span class="block rounded-md px-3 py-1.5 text-xs font-medium text-zinc-500 transition peer-checked:bg-white peer-checked:text-zinc-900 peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-accent-500/40 hover:text-zinc-700 dark:text-zinc-400 dark:peer-checked:bg-zinc-700 dark:peer-checked:text-white dark:hover:text-zinc-200">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="p-5 transition-opacity" wire:loading.class="opacity-50" wire:target="range">
            <x-line-chart
                :series="$series"
                :height="220"
                :threshold="$website->response_time_threshold_ms"
                :empty="'Belum ada data pengecekan untuk situs ini dalam '.$rangeSpan.' terakhir.'"
            />
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Downtime history --}}
        <section class="enter min-w-0 rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="{{ $cardHead }}">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Riwayat Downtime</h2>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">90 hari terakhir</p>
                </div>
                @can('insiden.view')
                    @if ($downtimes->isNotEmpty())
                        <a href="{{ route('insiden', ['type' => 'downtime', 'search' => $website->name]) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-accent-600 transition hover:text-accent-700 dark:text-accent-400 dark:hover:text-accent-300">
                            Lihat di Insiden
                            <x-phosphor-arrow-square-out class="h-3.5 w-3.5" aria-hidden="true" />
                        </a>
                    @endif
                @endcan
            </div>

            @if ($downtimes->isEmpty())
                <div class="flex flex-col items-center gap-2 px-5 py-8 text-center">
                    <x-phosphor-check-circle class="h-7 w-7 text-emerald-500/70" aria-hidden="true" />
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Tidak ada downtime dalam 90 hari terakhir.</p>
                </div>
            @else
                <ul class="max-h-[340px] divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800">
                    @foreach ($downtimes as $downtime)
                        @php
                            $cause = $downtime['cause'] ?? null;
                            $causeLabel = $cause ? ($errorLabels[$cause] ?? $cause) : null;
                            $code = $downtime['status_code'] ?? null;
                            $causeDetail = $downtime['cause_detail'] ?? null;
                        @endphp
                        <li wire:key="downtime-{{ $downtime['uuid'] }}" @class(['px-5 py-3', 'bg-red-50/50 dark:bg-red-500/5' => $downtime['ongoing']])>
                            <div class="flex items-start justify-between gap-3">
                                <p class="min-w-0 text-sm text-zinc-900 dark:text-white">
                                    <span class="font-mono tabular-nums">{{ \App\Support\Dates::plain($downtime['started_at']) }}</span>
                                    <span class="text-zinc-400">&rarr;</span>
                                    @if ($downtime['ongoing'] || ! $downtime['ended_at'])
                                        <span class="font-medium text-red-600 dark:text-red-400">Masih down</span>
                                    @else
                                        <span class="font-mono tabular-nums">{{ \App\Support\Dates::plain($downtime['ended_at']) }}</span>
                                    @endif
                                </p>
                                <span @class([
                                    'shrink-0 rounded-full px-2.5 py-0.5 font-mono text-xs font-semibold tabular-nums',
                                    'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' => $downtime['ongoing'],
                                    'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' => ! $downtime['ongoing'],
                                ])>{{ $fmtDuration((int) $downtime['duration_seconds']) }}</span>
                            </div>
                            @if ($causeLabel || ! $causeDetail)
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    Penyebab: {{ $causeLabel ?? 'Tidak diketahui' }}
                                    @if ($code) &middot; <span class="font-mono">HTTP {{ $code }}</span>@endif
                                </p>
                            @endif
                            @if ($causeDetail)
                                {{-- Raw cURL/HTTP text: clipped to one line, full text on hover. --}}
                                <p class="mt-0.5 truncate font-mono text-[11px] text-zinc-400 dark:text-zinc-500" title="{{ $causeDetail }}">{{ \Illuminate\Support\Str::limit($causeDetail, 80) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Recent checks, newest first --}}
        <section class="enter min-w-0 rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="{{ $cardHead }}">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Pengecekan Terbaru</h2>
                @if ($checks->isNotEmpty())
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $checks->count() }} terakhir</span>
                @endif
            </div>

            @if ($checks->isEmpty())
                <div class="flex flex-col items-center gap-2 px-5 py-8 text-center">
                    <x-phosphor-list-checks class="h-7 w-7 text-zinc-300 dark:text-zinc-600" aria-hidden="true" />
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Belum ada data pengecekan.</p>
                </div>
            @else
                {{-- relative: makes the list the containing block of the sr-only labels, so it
                clips them - otherwise they escape the scroll box and add blank space under the footer. --}}
                <ul class="relative max-h-[340px] divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800">
                    @foreach ($checks->reverse() as $check)
                        @php $errorType = $check->error_type; @endphp
                        <li wire:key="check-{{ $check->id }}" class="flex items-center gap-3 px-5 py-2.5">
                            <span @class(['h-2 w-2 shrink-0 rounded-full', 'bg-emerald-500' => $check->is_up, 'bg-red-500' => ! $check->is_up])>
                                <span class="sr-only">{{ $check->is_up ? 'Up' : 'Down' }}</span>
                            </span>
                            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="font-mono text-xs tabular-nums text-zinc-600 dark:text-zinc-300">{{ \App\Support\Dates::plain($check->checked_at) }}</span>
                                @if ($errorType)
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-[11px] font-medium',
                                        'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' => $errorType === 'slow',
                                        'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' => $errorType !== 'slow',
                                    ])>{{ $errorLabels[$errorType] ?? $errorType }}</span>
                                @elseif (! $check->is_up)
                                    <span class="rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-700 dark:bg-red-500/10 dark:text-red-400">Gagal</span>
                                @endif
                            </div>
                            <span @class([
                                'shrink-0 rounded px-1.5 py-0.5 font-mono text-[11px] tabular-nums',
                                'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => $check->status_code && $check->status_code < 400,
                                'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' => $check->status_code >= 400,
                                'text-zinc-400 dark:text-zinc-500' => ! $check->status_code,
                            ])>{{ $check->status_code ?: '-' }}</span>
                            <span class="w-16 shrink-0 text-right font-mono text-xs tabular-nums text-zinc-900 dark:text-white">{{ $check->response_time_ms !== null ? number_format($check->response_time_ms).'ms' : '-' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
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
                                <p class="mt-1.5 break-words text-xs text-zinc-500 dark:text-zinc-400">{{ $incident->displayDescription() }}</p>
                            @endif
                            {{-- e() on both halves, not a raw echo of the joined string: a judol
                            incident's "URL Halaman" is a URL the crawler read off the monitored
                            site itself (JudolScanner::resolveUrl returns an absolute href
                            verbatim), so a compromised site - the very thing being watched for
                            here - can put markup in it. Only the <br> separators are HTML. --}}
                            @if ($lines = $incident->detailLines())
                                <p class="mt-1 break-words font-mono text-[11px] text-zinc-400">
                                    {!! collect($lines)->map(fn ($v, $k) => e($k).': '.e($v))->implode('<br>') !!}
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

    {{-- Safe Browsing. Only shown once a verdict exists - "belum pernah dicek"
    and "aman" are different claims, and the second must never stand in for the
    first. --}}
    @if ($website->reputation_status === 'flagged')
        <div class="enter flex items-start gap-2 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-400">
            <x-phosphor-shield-warning class="mt-0.5 h-4 w-4 shrink-0" />
            <span>
                Domain ini <strong>masuk daftar blokir Google Safe Browsing</strong>
                ({{ collect($website->reputation_threats ?? [])->map(fn ($threat) => \App\Services\Monitoring\ReputationChecker::THREAT_LABELS[$threat] ?? $threat)->implode(', ') }}).
                Pengunjung Chrome melihat halaman peringatan merah sebelum masuk, meski server situsnya menjawab normal.
                Bersihkan penyebabnya, lalu ajukan peninjauan ulang lewat Google Search Console.
            </span>
        </div>
    @elseif ($website->reputation_status === 'clean')
        <p class="enter flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
            <x-phosphor-shield-check class="h-3.5 w-3.5 shrink-0 text-emerald-500" />
            Bersih menurut Google Safe Browsing, dicek {{ \App\Support\Dates::display($website->reputation_checked_at) }}.
        </p>
    @endif

    {{-- Certificate, DNS & domain: everything here is read from $website, no extra queries. --}}
    @php
        // Calendar days in the app timezone - the same count the incident severity uses.
        $daysLeft = fn ($date) => \App\Support\Dates::daysUntil($date);
        // Red when expired or $red days out, amber within $amber days.
        $expiryTone = fn (?int $d, int $red, int $amber) => match (true) {
            $d === null => 'text-zinc-400 dark:text-zinc-500',
            $d <= $red => 'text-red-600 dark:text-red-400',
            $d <= $amber => 'text-amber-600 dark:text-amber-400',
            default => 'text-emerald-600 dark:text-emerald-400',
        };
        // [big, small] for the focal line.
        $daysText = fn (int $d) => match (true) {
            $d < 0 => ['Kedaluwarsa', abs($d).' hari lalu'],
            $d === 0 => ['Hari ini', 'berakhir'],
            default => [$d, 'hari lagi'],
        };
        $flag = fn (?bool $v, string $yes, string $no, string $unknown) => match ($v) {
            true => [$yes, 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'],
            false => [$no, 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400'],
            default => [$unknown, 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400'],
        };

        $sslDays = $daysLeft($website->ssl_expires_at);
        $domainDays = $daysLeft($website->domain_expires_at);
        [$chainLabel, $chainTone] = $flag($website->ssl_chain_valid, 'Valid', 'Tidak valid', 'Tidak diketahui');
        [$hostLabel, $hostTone] = $flag($website->ssl_hostname_match, 'Cocok', 'Tidak cocok', '-');
        $dnsRecords = collect(['A', 'AAAA', 'CNAME', 'NS', 'MX'])
            ->mapWithKeys(fn ($type) => [$type => (array) (($website->dns_records ?? [])[$type] ?? [])])
            ->filter();
        $sourceLabels = ['rdap' => 'RDAP', 'manual' => 'Manual'];
    @endphp
    <div class="enter rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="px-5 py-4">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Sertifikat, DNS &amp; Domain</h2>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Kapan sertifikat dan domain habis, dan ke mana nama domain ini mengarah saat terakhir dicek.</p>
        </div>

        <div class="grid divide-y divide-zinc-100 border-t border-zinc-100 lg:grid-cols-3 lg:divide-x lg:divide-y-0 dark:divide-zinc-800 dark:border-zinc-800">
            {{-- Certificate --}}
            <div class="min-w-0 px-5 py-4">
                <p class="flex items-center gap-1.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">
                    <x-phosphor-certificate class="h-4 w-4 shrink-0" />
                    Sertifikat SSL
                </p>

                @if (! $website->ssl_checked_at && ! $website->ssl_expires_at && ! $website->ssl_error)
                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">Belum dicek.</p>
                @else
                    @if ($sslDays !== null)
                        @php [$sslBig, $sslSmall] = $daysText($sslDays); @endphp
                        <p class="mt-2 font-mono text-2xl font-semibold tabular-nums {{ $expiryTone($sslDays, (int) config('monitoring.ssl.critical_days'), (int) config('monitoring.ssl.warning_days')) }}">
                            {{ $sslBig }}<span class="ml-1.5 font-sans text-sm font-normal">{{ $sslSmall }}</span>
                        </p>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Berlaku sampai {{ \App\Support\Dates::display($website->ssl_expires_at) }}</p>
                    @endif

                    @if ($website->ssl_error)
                        <p class="mt-3 flex items-start gap-1.5 break-words rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-400">
                            <x-phosphor-warning-circle class="mt-px h-3.5 w-3.5 shrink-0" />
                            <span class="min-w-0">{{ $website->ssl_error }}</span>
                        </p>
                    @endif

                    <dl class="mt-3 space-y-2 text-xs">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-zinc-500 dark:text-zinc-400">Penerbit</dt>
                            <dd class="min-w-0 break-words text-right font-medium text-zinc-900 dark:text-white">{{ $website->ssl_issuer ?: '-' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-zinc-500 dark:text-zinc-400">Rantai sertifikat</dt>
                            <dd><span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $chainTone }}">{{ $chainLabel }}</span></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-zinc-500 dark:text-zinc-400">Nama host</dt>
                            <dd><span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $hostTone }}">{{ $hostLabel }}</span></dd>
                        </div>
                        @if ($website->ssl_hostname_match === false && $website->ssl_names)
                            @php $sanNames = collect($website->ssl_names); @endphp
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Sertifikat berlaku untuk</dt>
                                <dd class="mt-1 flex flex-wrap gap-1">
                                    @foreach ($sanNames->take(5) as $san)
                                        <span class="max-w-full break-all rounded-md bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ $san }}</span>
                                    @endforeach
                                    @if ($sanNames->count() > 5)
                                        <span class="px-1 py-0.5 text-[11px] text-zinc-500 dark:text-zinc-400">+{{ $sanNames->count() - 5 }} lainnya</span>
                                    @endif
                                </dd>
                            </div>
                        @endif
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-zinc-500 dark:text-zinc-400">Terakhir dicek</dt>
                            <dd class="text-right text-zinc-700 dark:text-zinc-300">{{ $website->ssl_checked_at ? \App\Support\Dates::display($website->ssl_checked_at) : '-' }}</dd>
                        </div>
                    </dl>
                @endif
            </div>

            {{-- DNS --}}
            <div class="min-w-0 px-5 py-4">
                <p class="flex items-center gap-1.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">
                    <x-phosphor-tree-structure class="h-4 w-4 shrink-0" />
                    Rekaman DNS
                    @if ($website->dns_ip_rotates)
                        <span title="Perubahan IP A/AAAA jadi peringatan, bukan kritis." class="rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">IP berganti-ganti</span>
                    @endif
                </p>

                @if ($dnsRecords->isEmpty())
                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">Belum dicek.</p>
                @else
                    <dl class="mt-3 space-y-2.5">
                        @foreach ($dnsRecords as $type => $values)
                            <div class="flex items-start gap-3">
                                <dt class="w-12 shrink-0 pt-0.5 font-mono text-[11px] font-semibold text-zinc-500 dark:text-zinc-400">{{ $type }}</dt>
                                <dd class="flex min-w-0 flex-1 flex-wrap gap-1">
                                    @foreach ($values as $value)
                                        <span class="max-w-full break-all rounded-md bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ $value }}</span>
                                    @endforeach
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endif

                @if ($website->dns_checked_at)
                    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">Dicek {{ \App\Support\Dates::display($website->dns_checked_at) }}</p>
                @endif
            </div>

            {{-- Domain --}}
            <div class="min-w-0 px-5 py-4">
                <p class="flex items-center gap-1.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">
                    <x-phosphor-globe-simple class="h-4 w-4 shrink-0" />
                    Masa Berlaku Domain
                    @if ($sourceLabel = $sourceLabels[$website->domain_expiry_source] ?? null)
                        <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $sourceLabel }}</span>
                    @endif
                </p>

                @if ($domainDays === null)
                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">Belum dicek.</p>
                    <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Registri tanpa RDAP (mis. .id) perlu diisi manual lewat Edit situs.</p>
                @else
                    @php [$domainBig, $domainSmall] = $daysText($domainDays); @endphp
                    <p class="mt-2 font-mono text-2xl font-semibold tabular-nums {{ $expiryTone($domainDays, (int) config('monitoring.domain.critical_days', 3), (int) config('monitoring.domain.warning_days')) }}">
                        {{ $domainBig }}<span class="ml-1.5 font-sans text-sm font-normal">{{ $domainSmall }}</span>
                    </p>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Berlaku sampai {{ $website->domain_expires_at->translatedFormat('d M Y') }}</p>
                @endif

                @if ($website->domain_checked_at)
                    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">Dicek {{ \App\Support\Dates::display($website->domain_checked_at) }}</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Security posture --}}
    <div class="enter rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Keamanan Situs (Header &amp; TLS)</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Seberapa baik situs ini melindungi pengunjungnya sendiri. Bersifat informatif - tidak membuat insiden, karena header berubah setahun sekali dan alarm harian hanya bikin orang mengabaikannya.
                    @if ($website->security_checked_at)
                        <span class="block">Dinilai {{ \App\Support\Dates::display($website->security_checked_at) }}.</span>
                    @endif
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-3">
                @if ($website->security_grade)
                    @php
                        $gradeTone = match ($website->security_grade) {
                            'A' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
                            'B' => 'bg-lime-50 text-lime-700 dark:bg-lime-500/10 dark:text-lime-400',
                            'C' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
                            'D' => 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-400',
                            default => 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400',
                        };
                    @endphp
                    <div class="flex items-center gap-2">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl text-lg font-bold {{ $gradeTone }}">{{ $website->security_grade }}</span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $website->security_score }}/100</span>
                    </div>
                @endif

                @can('situs.update')
                    <button type="button" wire:click="gradeSecurityNow" class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                        <x-loading-label target="gradeSecurityNow" loading="Menilai...">
                            <x-phosphor-shield-check class="h-4 w-4" />
                            <span>Nilai Sekarang</span>
                        </x-loading-label>
                    </button>
                @endcan
            </div>
        </div>

        @if ($website->security_findings)
            <div class="divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-zinc-800 dark:border-zinc-800">
                @foreach ($website->security_findings as $finding)
                    <div class="flex items-start gap-3 px-5 py-3">
                        <x-dynamic-component
                            :component="$finding['ok'] ? 'phosphor-check-circle' : 'phosphor-x-circle'"
                            @class([
                                'mt-0.5 h-4 w-4 shrink-0',
                                'text-emerald-500' => $finding['ok'],
                                'text-red-500' => ! $finding['ok'],
                            ])
                        />
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $finding['label'] }}</p>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $finding['note'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="border-t border-zinc-100 px-5 py-4 text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">Situs ini belum pernah dinilai. Penilaian otomatis berjalan setiap malam, atau tekan "Nilai Sekarang".</p>
        @endif
    </div>

    {{-- Scheduled maintenance --}}
    <div class="enter rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Jendela Pemeliharaan</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Periode saat situs ini memang sedang dikerjakan. Pengecekan tetap jalan dan riwayat uptime tetap jujur - yang ditahan hanya insiden baru dan notifikasinya.</p>
            </div>

            @can('situs.update')
                @unless ($showMaintenanceForm)
                    <button type="button" wire:click="openMaintenanceForm" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-zinc-200 px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                        <x-phosphor-plus class="h-4 w-4" />
                        Jadwalkan
                    </button>
                @endunless
            @endcan
        </div>

        @can('situs.update')
            @if ($showMaintenanceForm)
                <form wire:submit="saveMaintenance" class="flex flex-col gap-3 border-t border-zinc-100 px-5 py-4 dark:border-zinc-800" novalidate>
                    <div class="flex flex-col gap-1.5">
                        <label for="maintenanceReason" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Keterangan</label>
                        <input
                            id="maintenanceReason" type="text" wire:model="maintenanceReason" placeholder="Migrasi server, update CMS, ..."
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        >
                        @error('maintenanceReason') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <label for="maintenanceStartsAt" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Mulai ({{ \App\Support\Dates::abbreviation() }})</label>
                            <input
                                id="maintenanceStartsAt" type="datetime-local" wire:model="maintenanceStartsAt"
                                class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            >
                            @error('maintenanceStartsAt') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="maintenanceEndsAt" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Selesai ({{ \App\Support\Dates::abbreviation() }})</label>
                            <input
                                id="maintenanceEndsAt" type="datetime-local" wire:model="maintenanceEndsAt"
                                class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            >
                            @error('maintenanceEndsAt') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-accent-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-accent-700">
                            <x-loading-label target="saveMaintenance" loading="Menyimpan...">
                                <x-phosphor-check class="h-4 w-4" />
                                <span>Simpan</span>
                            </x-loading-label>
                        </button>
                        <button type="button" wire:click="$set('showMaintenanceForm', false)" class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                            Batal
                        </button>
                    </div>
                </form>
            @endif
        @endcan

        @if ($maintenanceWindows->isEmpty())
            <p class="border-t border-zinc-100 px-5 py-4 text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">Belum ada jendela pemeliharaan yang dijadwalkan.</p>
        @else
            <div class="divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-zinc-800 dark:border-zinc-800">
                @foreach ($maintenanceWindows as $window)
                    @php
                        $state = $window->state();
                        $tone = $state === 'berlangsung'
                            ? 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-400'
                            : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300';
                    @endphp

                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-zinc-900 dark:text-white">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $tone }}">{{ ucfirst($state) }}</span>
                                <span class="truncate">{{ $window->reason }}</span>
                                @unless ($window->website_id)
                                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">Semua situs</span>
                                @endunless
                            </p>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ \App\Support\Dates::plain($window->starts_at) }} &rarr; {{ \App\Support\Dates::display($window->ends_at) }}
                                @if ($window->author)
                                    &middot; dijadwalkan oleh {{ $window->author->name }}
                                @endif
                            </p>
                        </div>

                        @can('situs.update')
                            {{-- An all-sites window is not this page's to remove - deleting it here
                            would silently change every other site's behaviour too. --}}
                            @if ($window->website_id)
                                <button type="button" wire:click="deleteMaintenance('{{ $window->uuid }}')" title="Hapus" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-red-50 hover:text-red-600 dark:text-zinc-400 dark:hover:bg-red-500/10 dark:hover:text-red-400">
                                    <x-phosphor-trash class="h-4 w-4" />
                                </button>
                            @endif
                        @endcan
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
