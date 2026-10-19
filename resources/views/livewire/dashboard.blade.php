@php
    // Read once per render; computed props are cached for the request anyway.
    $summary = $this->summary;
    $metrics = $this->metrics;
    $series = $this->series;
    $liveSites = collect($this->liveSites);
    $loadCounts = $this->loadCounts;
    $breakdown = $summary['breakdown'] ?? [];

    $user = auth()->user();
    $canManage = $this->canManage;
    $canViewSites = $this->canViewSites;
    $canViewIncidents = $this->canViewIncidents;
    $canViewJudol = $this->canViewJudol;

    $ranges = ['1h' => 'Jam', '24h' => 'Hari', '7d' => 'Minggu', '30d' => 'Bulan'];
    $rangeSpan = ['1h' => '1 jam', '24h' => '24 jam', '7d' => '7 hari', '30d' => '30 hari'][$this->range] ?? '24 jam';
    $rangeBucket = ['1h' => 'per 5 menit', '24h' => 'per jam', '7d' => 'per 6 jam', '30d' => 'per hari'][$this->range] ?? 'per jam';

    $selectedSite = $this->selectedWebsite;

    // Already ordered by the backend: problems first, then heaviest load.
    $visibleSites = $liveSites->take(8);
    $siteTag = $canViewSites ? 'a' : 'div';

    $loadTotal = array_sum($loadCounts);
    $loadSegments = [
        ['key' => 'light', 'label' => 'Ringan', 'bar' => 'bg-emerald-500'],
        ['key' => 'moderate', 'label' => 'Sedang', 'bar' => 'bg-yellow-400'],
        ['key' => 'heavy', 'label' => 'Berat', 'bar' => 'bg-red-500'],
        ['key' => 'unknown', 'label' => 'Tanpa data', 'bar' => 'bg-zinc-300 dark:bg-zinc-600'],
    ];

    $total = (int) ($summary['total'] ?? 0);
    $onlinePct = $total > 0 ? (int) round(($summary['online'] ?? 0) / $total * 100) : 0;

    $chip = 'inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 dark:bg-zinc-800';
    $cardBase = 'enter min-w-0 rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900';
    $cardHead = 'flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800';
    $linkClass = 'text-xs font-medium text-accent-600 transition hover:text-accent-700 dark:text-accent-400 dark:hover:text-accent-300';
@endphp

<div class="mx-auto flex max-w-7xl flex-col gap-6" wire:poll.15s.visible>

    {{-- Header --}}
    <div class="enter flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                Selamat datang, {{ explode(' ', $user->name)[0] }}
            </h1>
            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">
                <span>{{ $this->updatedAt->translatedFormat('l, d F Y') }}</span>
                <span aria-hidden="true">&middot;</span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="relative flex h-2 w-2" aria-hidden="true">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60 motion-reduce:animate-none"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    </span>
                    <span class="font-medium text-emerald-600 dark:text-emerald-400">Live</span>
                    <span>&middot; diperbarui <span class="font-mono tabular-nums">{{ $this->updatedAt->format('H:i:s') }}</span></span>
                </span>
            </p>
        </div>
        @if ($canManage)
            <a
                href="{{ route('situs') }}"
                wire:navigate
                class="inline-flex items-center gap-2 self-start rounded-lg bg-accent-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-accent-700 active:scale-[0.98] sm:self-auto"
            >
                <x-phosphor-plus class="h-4 w-4" />
                Tambah Situs
            </a>
        @endif
    </div>

    {{-- Status KPI: a full partition, Online + Bermasalah + Offline = Total --}}
    <section aria-label="Ringkasan status situs" class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-kpi-card label="Total Situs" :value="$total" icon="globe" :href="$canViewSites ? route('situs') : null">
            @if ($total > 0)
                <span>{{ $onlinePct }}% online</span>
            @endif
        </x-kpi-card>

        <x-kpi-card label="Online" :value="$summary['online'] ?? 0" icon="check-circle" tone="up" style="animation-delay: 40ms">
            @if (($breakdown['maintenance'] ?? 0) > 0)
                <span class="{{ $chip }}"><span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>{{ $breakdown['maintenance'] }} Pemeliharaan</span>
            @endif
        </x-kpi-card>

        <x-kpi-card
            label="Bermasalah" :value="$summary['problem'] ?? 0" icon="warning-circle"
            :tone="($summary['problem'] ?? 0) > 0 ? 'down' : 'muted'"
            :alert="($summary['problem'] ?? 0) > 0"
            style="animation-delay: 80ms"
        >
            @if (($breakdown['down'] ?? 0) > 0)
                <span class="{{ $chip }}"><span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>{{ $breakdown['down'] }} Down</span>
            @endif
            @if (($breakdown['attack'] ?? 0) > 0)
                <span class="{{ $chip }}"><x-phosphor-lightning class="h-3 w-3 text-red-500" aria-hidden="true" />{{ $breakdown['attack'] }} DDoS</span>
            @endif
        </x-kpi-card>

        <x-kpi-card label="Offline" :value="$summary['offline'] ?? 0" icon="power" tone="muted" style="animation-delay: 120ms">
            @if (($breakdown['internal'] ?? 0) > 0)
                <span class="{{ $chip }}"><span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>{{ $breakdown['internal'] }} Internal</span>
            @endif
            @if (($breakdown['unknown'] ?? 0) > 0)
                <span class="{{ $chip }}"><span class="h-1.5 w-1.5 rounded-full bg-zinc-300 dark:bg-zinc-600"></span>{{ $breakdown['unknown'] }} Belum diketahui</span>
            @endif
        </x-kpi-card>
    </section>

    {{-- Range KPI: follow the selected range --}}
    <section aria-label="Ringkasan performa" class="-mt-2 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-kpi-card
            size="sm" label="Uptime {{ $rangeSpan }}" icon="pulse"
            :value="$metrics['uptime'] !== null ? number_format($metrics['uptime'], $metrics['uptime'] >= 99.95 ? 0 : 1).'%' : '-'"
            :tone="$metrics['uptime'] === null ? 'muted' : ($metrics['uptime'] >= 99 ? 'up' : ($metrics['uptime'] >= 95 ? 'warning' : 'down'))"
        />
        <x-kpi-card
            size="sm" label="Rata-rata respons" icon="timer" tone="accent"
            :value="$metrics['avg_ms'] !== null ? number_format($metrics['avg_ms']).'ms' : '-'"
        />
        @if ($canViewIncidents)
            <x-kpi-card
                size="sm" label="Insiden terbuka" icon="bell-ringing"
                :value="$metrics['open_incidents'] ?? 0"
                :tone="($metrics['open_incidents'] ?? 0) > 0 ? 'down' : 'muted'"
                :href="route('insiden', ['status' => 'open'])"
            />
        @endif
        {{-- Widens to fill the slot left by the hidden incident card, keeping the 4-col rhythm --}}
        <x-kpi-card
            size="sm" label="Judol baru" icon="shield-warning"
            :class="$canViewIncidents ? '' : 'col-span-2'"
            :value="$metrics['new_judol'] ?? 0"
            :tone="($metrics['new_judol'] ?? 0) > 0 ? 'down' : 'muted'"
            :href="$canViewJudol ? route('deteksi-judol') : null"
        />
    </section>

    {{-- Real-time comes first in the DOM so it sits above the chart on mobile --}}
    <div class="grid gap-6 lg:grid-cols-3">

        {{-- Real-time condition --}}
        <section class="{{ $cardBase }} flex flex-col lg:col-start-3 lg:row-start-1">
            <div class="{{ $cardHead }}">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Kondisi Real-time</h2>
                @if ($canViewSites && $liveSites->isNotEmpty())
                    <a href="{{ route('situs') }}" wire:navigate class="{{ $linkClass }}">Lihat semua situs</a>
                @endif
            </div>

            @if ($liveSites->isEmpty())
                <div class="flex flex-1 flex-col items-center justify-center gap-3 p-5 py-12 text-center">
                    <x-phosphor-globe-hemisphere-west class="h-8 w-8 text-zinc-300 dark:text-zinc-600" />
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Belum ada situs yang dipantau.</p>
                    @if ($canManage)
                        <a href="{{ route('situs') }}" wire:navigate class="{{ $linkClass }}">Tambah situs pertama</a>
                    @endif
                </div>
            @else
                {{-- Load distribution --}}
                <div class="border-b border-zinc-100 p-5 dark:border-zinc-800">
                    <div class="flex items-baseline justify-between gap-2">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Distribusi beban</p>
                        <p class="text-[11px] text-zinc-400 dark:text-zinc-500">{{ $loadTotal }} situs</p>
                    </div>
                    <div class="mt-2 flex h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" aria-hidden="true">
                        @foreach ($loadSegments as $seg)
                            @if (($loadCounts[$seg['key']] ?? 0) > 0 && $loadTotal > 0)
                                <span class="h-full {{ $seg['bar'] }}" style="width: {{ round($loadCounts[$seg['key']] / $loadTotal * 100, 2) }}%"></span>
                            @endif
                        @endforeach
                    </div>
                    <ul class="mt-2.5 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-zinc-500 dark:text-zinc-400">
                        @foreach ($loadSegments as $seg)
                            <li class="inline-flex items-center gap-1.5">
                                <span class="h-1.5 w-1.5 rounded-full {{ $seg['bar'] }}" aria-hidden="true"></span>
                                {{ $seg['label'] }} <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ $loadCounts[$seg['key']] ?? 0 }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- Problem-first site list --}}
                <ul class="flex-1 p-2">
                    @foreach ($visibleSites as $site)
                        @php
                            $problem = in_array($site['status_key'], ['down', 'attack'], true);
                            $history = array_slice(array_values($site['history'] ?? []), -24);
                            $pad = 24 - count($history);
                            $upCount = count(array_filter($history, fn ($h) => $h === true));
                        @endphp
                        <li wire:key="live-{{ $site['uuid'] }}">
                            <{{ $siteTag }}
                                @if ($canViewSites) href="{{ route('situs.show', $site['uuid']) }}" wire:navigate @endif
                                @class([
                                    'block rounded-xl px-3 py-2.5',
                                    'transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60' => $canViewSites,
                                ])
                            >
                                <div class="flex items-center gap-2.5">
                                    <x-status-dot :status="$site['status_key']" />
                                    <p class="min-w-0 flex-1 truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $site['name'] }}</p>
                                    <span class="shrink-0 font-mono text-[11px] text-zinc-400 dark:text-zinc-500">
                                        {{ $site['last_checked_at']?->diffForHumans(null, true) ?? 'Belum dicek' }}
                                    </span>
                                </div>
                                <div class="mt-1.5 flex items-center gap-2 pl-5">
                                    <div class="flex h-1.5 min-w-0 flex-1 gap-px" role="img" aria-label="Riwayat 24 pengecekan terakhir: {{ $upCount }} up, {{ count($history) - $upCount }} down">
                                        @for ($i = 0; $i < 24; $i++)
                                            @php $h = $i < $pad ? null : $history[$i - $pad]; @endphp
                                            <span @class([
                                                'flex-1 rounded-sm',
                                                'bg-zinc-200 dark:bg-zinc-700' => $h === null,
                                                'bg-emerald-500' => $h === true,
                                                'bg-red-500' => $h === false,
                                            ])></span>
                                        @endfor
                                    </div>
                                    @if ($site['status_key'] !== 'up')
                                        <span @class([
                                            'shrink-0 text-[11px] font-medium',
                                            'text-red-600 dark:text-red-400' => $problem,
                                            'text-zinc-500 dark:text-zinc-400' => ! $problem,
                                        ])>{{ $site['status_label'] }}</span>
                                    @endif
                                </div>
                            </{{ $siteTag }}>
                        </li>
                    @endforeach
                </ul>

                @if ($liveSites->count() > $visibleSites->count())
                    <div class="border-t border-zinc-100 px-5 py-3 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                        +{{ $liveSites->count() - $visibleSites->count() }} situs lainnya
                        @if ($canViewSites)
                            &middot; <a href="{{ route('situs') }}" wire:navigate class="{{ $linkClass }}">Lihat semua situs</a>
                        @endif
                    </div>
                @endif
            @endif
        </section>

        {{-- Performance chart --}}
        <section class="{{ $cardBase }} flex flex-col lg:col-span-2 lg:col-start-1 lg:row-start-1">
            <div class="{{ $cardHead }}">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Performa</h2>
                    <p class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $selectedSite?->name ?? 'Semua situs' }} &middot; rata-rata {{ $rangeBucket }}, {{ $rangeSpan }} terakhir
                    </p>
                </div>

                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                    {{-- Range segmented control --}}
                    <div role="radiogroup" aria-label="Rentang waktu" class="inline-flex self-start rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800">
                        @foreach ($ranges as $key => $label)
                            <label class="cursor-pointer">
                                <input type="radio" name="dashboard-range" value="{{ $key }}" wire:model.live="range" class="peer sr-only">
                                <span class="block rounded-md px-3 py-1.5 text-xs font-medium text-zinc-500 transition peer-checked:bg-white peer-checked:text-zinc-900 peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-accent-500/40 hover:text-zinc-700 dark:text-zinc-400 dark:peer-checked:bg-zinc-700 dark:peer-checked:text-white dark:hover:text-zinc-200">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>

                    {{-- Site picker: filters client-side, list is already loaded --}}
                    <div
                        class="relative w-full sm:w-52"
                        wire:key="response-picker-{{ md5($liveSites->pluck('uuid')->implode('-')) }}"
                        x-data="{
                            open: false,
                            query: @js($selectedSite?->name ?? ''),
                            sites: @js($liveSites->map(fn ($s) => ['uuid' => $s['uuid'], 'name' => $s['name']])->values()),
                            get filtered() {
                                const q = this.query.toLowerCase().trim();
                                return q ? this.sites.filter(s => s.name.toLowerCase().includes(q)) : this.sites;
                            },
                            pick(site) {
                                this.query = site.name;
                                this.open = false;
                                $wire.selectWebsite(site.uuid);
                            },
                            clear() {
                                this.query = '';
                                this.open = false;
                                $wire.selectWebsite('');
                            },
                        }"
                        @click.outside="open = false"
                        @keydown.escape="open = false"
                    >
                        <div class="relative">
                            <x-phosphor-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                            <input
                                type="text"
                                x-model="query"
                                @focus="open = true"
                                @input="open = true"
                                @disabled($liveSites->isEmpty())
                                autocomplete="off"
                                aria-label="Cari situs"
                                placeholder="{{ $liveSites->isEmpty() ? 'Belum ada situs' : 'Cari situs...' }}"
                                class="w-full rounded-lg border border-zinc-200 bg-white py-1.5 pl-9 pr-8 text-sm text-zinc-700 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            >
                            <button type="button" x-show="query" x-cloak @click="clear()" aria-label="Hapus pilihan situs" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 transition hover:text-zinc-600 dark:hover:text-zinc-300">
                                <x-phosphor-x class="h-3.5 w-3.5" />
                            </button>
                        </div>

                        <div
                            x-show="open && filtered.length > 0"
                            x-cloak
                            class="absolute right-0 z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-zinc-200 bg-white py-1 shadow-lg sm:w-64 dark:border-zinc-700 dark:bg-zinc-800"
                        >
                            <template x-for="site in filtered" :key="site.uuid">
                                <button
                                    type="button"
                                    @click="pick(site)"
                                    class="block w-full truncate px-3 py-2 text-left text-sm text-zinc-700 transition hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-700"
                                    x-text="site.name"
                                ></button>
                            </template>
                        </div>
                        <div
                            x-show="open && query && filtered.length === 0"
                            x-cloak
                            class="absolute right-0 z-20 mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-500 shadow-lg sm:w-64 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400"
                        >
                            Situs tidak ditemukan.
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-1 flex-col p-5 transition-opacity" wire:loading.class="opacity-50" wire:target="range,selectWebsite">
                <x-line-chart
                    class="flex-1"
                    :series="$series"
                    :height="220"
                    :fill="true"
                    :threshold="$selectedSite?->response_time_threshold_ms"
                    :empty="$liveSites->isEmpty()
                        ? 'Belum ada situs yang dipantau.'
                        : ($selectedSite ? 'Belum ada data pengecekan untuk '.$selectedSite->name.' dalam '.$rangeSpan.' terakhir.' : 'Belum ada data pengecekan dalam '.$rangeSpan.' terakhir.')"
                />
            </div>
        </section>
    </div>

    {{-- Incidents --}}
    @if ($canViewIncidents)
    @php
        $incidentPanels = [
            [
                'title' => 'Insiden Belum Selesai',
                'items' => $this->openIncidents,
                'count' => (int) $this->openIncidentsCount,
                'href' => $canViewIncidents ? route('insiden', ['status' => 'open']) : null,
                'empty' => 'Tidak ada insiden terbuka. Semua situs aman terkendali.',
                'showStatus' => false,
            ],
            [
                'title' => 'Insiden Terbaru',
                'items' => $this->recentIncidents,
                'count' => null,
                'href' => $canViewIncidents ? route('insiden') : null,
                'empty' => 'Belum ada insiden.',
                'showStatus' => true,
            ],
        ];
    @endphp
    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ($incidentPanels as $panel)
            <section class="{{ $cardBase }}">
                <div class="{{ $cardHead }}">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                        {{ $panel['title'] }}
                        @if ($panel['count'])
                            <span class="rounded-full bg-red-50 px-2 py-0.5 font-mono text-[11px] font-medium text-red-700 dark:bg-red-500/10 dark:text-red-400">{{ $panel['count'] }}</span>
                        @endif
                    </h2>
                    @if ($panel['href'])
                        <a href="{{ $panel['href'] }}" wire:navigate class="{{ $linkClass }}">
                            Lihat semua{{ $panel['count'] ? ' ('.$panel['count'].')' : '' }}
                        </a>
                    @endif
                </div>

                @if ($panel['items']->isEmpty())
                    <div class="flex flex-col items-center gap-2 p-5 py-10 text-center">
                        <x-phosphor-shield-check class="h-8 w-8 text-emerald-500" />
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $panel['empty'] }}</p>
                    </div>
                @else
                    <ul class="p-2">
                        @foreach ($panel['items'] as $incident)
                            @php
                                // Judol detail lives on Deteksi Judol, not the incidents page.
                                $incidentHref = $incident->type === 'judol'
                                    ? ($canViewJudol ? route('deteksi-judol') : null)
                                    : ($canViewIncidents ? route('insiden') : null);
                            @endphp
                            <li wire:key="incident-{{ $loop->parent->index }}-{{ $incident->id }}">
                                <a
                                    @if ($incidentHref) href="{{ $incidentHref }}" wire:navigate @endif
                                    class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60"
                                >
                                    <span @class([
                                        'flex h-9 w-9 shrink-0 items-center justify-center rounded-full',
                                        'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400' => $incident->severity === 'critical',
                                        'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' => $incident->severity === 'warning',
                                        'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400' => ! in_array($incident->severity, ['critical', 'warning'], true),
                                    ])>
                                        @if ($incident->type === 'downtime')
                                            <x-phosphor-wifi-x class="h-4 w-4" />
                                        @elseif ($incident->type === 'judol')
                                            <x-phosphor-shield-warning class="h-4 w-4" />
                                        @elseif ($incident->type === 'ssl')
                                            <x-phosphor-lock-key class="h-4 w-4" />
                                        @else
                                            <x-phosphor-warning class="h-4 w-4" />
                                        @endif
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $incident->title }}</p>
                                        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $incident->website?->name ?? '-' }}
                                            <span class="sm:hidden">&middot; {{ $incident->detected_at?->diffForHumans(null, true) }}</span>
                                        </p>
                                    </div>
                                    @if ($panel['showStatus'])
                                        <span @class([
                                            'shrink-0 rounded-full px-2.5 py-1 text-xs font-medium',
                                            'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' => $incident->status === 'open',
                                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $incident->status !== 'open',
                                        ])>
                                            {{ $incident->status === 'open' ? 'Terbuka' : 'Selesai' }}
                                        </span>
                                    @endif
                                    <span class="hidden w-16 shrink-0 text-right font-mono text-xs text-zinc-400 sm:block">{{ $incident->detected_at?->diffForHumans(null, true) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>
    @endif
</div>
