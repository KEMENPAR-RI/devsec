<div class="mx-auto flex max-w-7xl flex-col gap-6" wire:poll.10s.visible>

    {{-- Header --}}
    <div class="enter flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                Selamat datang, {{ explode(' ', auth()->user()->name)[0] }}
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ now()->translatedFormat('l, d F Y') }} &middot; ringkasan monitoring situs
            </p>
        </div>
        @can('situs.create')
            <a
                href="{{ route('situs') }}"
                wire:navigate
                class="inline-flex items-center gap-2 self-start rounded-lg bg-accent-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-accent-700 active:scale-[0.98] sm:self-auto"
            >
                <x-phosphor-plus class="h-4 w-4" />
                Tambah Situs
            </a>
        @endcan
    </div>

    {{-- KPI stats --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ($stats as $i => $stat)
            <div
                class="enter rounded-2xl border border-zinc-200 bg-white p-4 transition hover:-translate-y-0.5 hover:shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
                style="animation-delay: {{ $i * 40 }}ms"
            >
                {{-- The colour arrives as a whole class string from Dashboard::TONES,
                so adding a card colour is one entry there rather than another branch
                here - and Tailwind still sees every class spelled out. --}}
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $stat['accent'] }}">
                        <x-dynamic-component :component="'phosphor-'.$stat['icon']" class="h-4 w-4" />
                    </span>
                    {{-- Six columns leave no room for "Down - Dugaan DDoS" to wrap onto
                    three lines, so it truncates and carries the full text on hover. --}}
                    <span class="min-w-0 truncate text-xs text-zinc-500 dark:text-zinc-400" title="{{ $stat['label'] }}">{{ $stat['label'] }}</span>
                </div>
                <div class="mt-3">
                    <span class="font-mono text-2xl font-semibold text-zinc-900 dark:text-white">{{ $stat['value'] }}</span>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Response time chart, filterable per site --}}
    <div class="enter rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Response Time</h2>
            {{-- A searchable combobox scales to any number of sites without wrapping
            into a messy wall of pills, and typing filters instantly (client-side -
            the site list is already loaded, no need to round-trip per keystroke). --}}
            <div
                class="relative w-full sm:w-64"
                wire:key="response-picker-{{ $websiteStatuses->pluck('uuid')->implode('-') }}"
                x-data="{
                    open: false,
                    query: @js($selectedWebsite?->name ?? ''),
                    sites: @js($websiteStatuses->map(fn ($s) => ['uuid' => $s->uuid, 'name' => $s->name])),
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
            >
                <div class="relative">
                    <x-phosphor-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                    <input
                        type="text"
                        x-model="query"
                        @focus="open = true"
                        @input="open = true"
                        @disabled($websiteStatuses->isEmpty())
                        autocomplete="off"
                        placeholder="{{ $websiteStatuses->isEmpty() ? 'Belum ada situs' : 'Cari situs...' }}"
                        class="w-full rounded-lg border border-zinc-200 bg-white py-2 pl-9 pr-8 text-sm text-zinc-700 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                    >
                    <button type="button" x-show="query" x-cloak @click="clear()" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 transition hover:text-zinc-600 dark:hover:text-zinc-300">
                        <x-phosphor-x class="h-3.5 w-3.5" />
                    </button>
                </div>

                <div
                    x-show="open && filtered.length > 0"
                    x-cloak
                    class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800"
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
                    class="absolute z-20 mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-500 shadow-lg dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400"
                >
                    Situs tidak ditemukan.
                </div>
            </div>
        </div>

        @if ($responseSeries->isEmpty())
            <div class="flex flex-col items-center gap-2 px-5 py-14 text-center">
                <x-phosphor-chart-line class="h-7 w-7 text-zinc-300 dark:text-zinc-600" />
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $selectedWebsite ? "Belum ada data pengecekan untuk {$selectedWebsite->name}." : 'Belum ada data pengecekan.' }}
                </p>
            </div>
        @else
            @php
                $chartWidth = 1000;
                $chartHeight = 220;
                $padTop = 22;
                $padBottom = 30;
                $plotBottom = $chartHeight - $padBottom;
                $plotHeight = $plotBottom - $padTop;
                $maxMs = max(50, $responseSeries->max('ms'), $responseThresholdMs ?? 0);
                $n = $responseSeries->count();

                $toY = fn (int $ms) => $padTop + $plotHeight - (min($ms, $maxMs) / $maxMs) * $plotHeight;

                $points = $responseSeries->values()->map(fn (array $point, int $i) => [
                    ...$point,
                    'x' => round($n > 1 ? ($i / ($n - 1)) * $chartWidth : $chartWidth / 2, 1),
                    'y' => round($toY($point['ms']), 1),
                ]);

                $linePath = $points->map(fn ($p) => "{$p['x']},{$p['y']}")->implode(' ');
                $areaPath = "0,{$plotBottom} {$linePath} {$chartWidth},{$plotBottom}";
                $thresholdY = $responseThresholdMs ? round($toY($responseThresholdMs), 1) : null;
                $last = $points->last();
            @endphp

            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $responseCaption }}</p>

            {{-- Hover tooltip: finds the nearest point to the pointer's X and shows its
            time + response time + status. All in Alpine (no chart library) - the point
            data is embedded once as JSON, mousemove just picks the closest one. --}}
            <div
                class="relative mt-4 overflow-hidden"
                x-data="{
                    points: @js($points->map(fn ($p) => [
                        'x' => $p['x'],
                        'y' => $p['y'],
                        'time' => $p['label'],
                        'ms' => $p['ms'],
                        'up' => $p['up'],
                    ])),
                    chartWidth: {{ $chartWidth }},
                    chartHeight: {{ $chartHeight }},
                    hovered: null,
                    onMove(event) {
                        if (this.points.length === 0) return;
                        const rect = event.currentTarget.getBoundingClientRect();
                        const mouseX = (event.clientX - rect.left) * (this.chartWidth / rect.width);
                        this.hovered = this.points.reduce((nearest, p) =>
                            Math.abs(p.x - mouseX) < Math.abs(nearest.x - mouseX) ? p : nearest
                        );
                    },
                    onLeave() { this.hovered = null },
                }"
            >
                <svg
                    viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" class="w-full" preserveAspectRatio="none" style="height: 220px"
                    @mousemove="onMove" @mouseleave="onLeave"
                >
                    {{-- Baseline & max gridlines --}}
                    <line x1="0" y1="{{ $plotBottom }}" x2="{{ $chartWidth }}" y2="{{ $plotBottom }}" class="stroke-zinc-200 dark:stroke-zinc-800" stroke-width="1" />
                    <text x="4" y="{{ $plotBottom - 4 }}" class="text-[10px] fill-zinc-400 dark:fill-zinc-500">0ms</text>
                    <text x="4" y="{{ $padTop + 9 }}" class="text-[10px] fill-zinc-400 dark:fill-zinc-500">{{ number_format($maxMs) }}ms</text>

                    {{-- Threshold reference line. Only one site has a single threshold
                    to draw against; the all-sites overview averages across sites that
                    each set their own, so a line there would compare nothing. --}}
                    @if ($thresholdY !== null)
                        <line x1="0" y1="{{ $thresholdY }}" x2="{{ $chartWidth }}" y2="{{ $thresholdY }}" class="stroke-amber-400/70 dark:stroke-amber-500/60" stroke-width="1" />
                        <text x="{{ $chartWidth - 4 }}" y="{{ $thresholdY - 4 }}" text-anchor="end" class="text-[10px] fill-amber-600 dark:fill-amber-400">Ambang batas {{ number_format($responseThresholdMs) }}ms</text>
                    @endif

                    {{-- Area wash under the line --}}
                    <polygon points="{{ $areaPath }}" class="fill-accent-500/10 dark:fill-accent-400/10" />

                    {{-- The trend line itself --}}
                    <polyline points="{{ $linePath }}" fill="none" class="stroke-accent-500 dark:stroke-accent-400" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />

                    {{-- Crosshair + highlighted dot, following the hovered point --}}
                    <line
                        x-show="hovered" x-cloak
                        :x1="hovered?.x" :x2="hovered?.x" y1="{{ $padTop }}" y2="{{ $plotBottom }}"
                        class="stroke-zinc-300 dark:stroke-zinc-600" stroke-width="1"
                    />
                    <circle
                        x-show="hovered" x-cloak
                        :cx="hovered?.x" :cy="hovered?.y" r="5" stroke-width="2"
                        :class="hovered?.up ? 'fill-accent-500 dark:fill-accent-400' : 'fill-red-500'"
                        class="stroke-white dark:stroke-zinc-900"
                    />

                    {{-- Per-point dots: red when a check there counted as down (incl.
                    over-threshold, see WebsiteChecker). In the all-sites view a single
                    failure anywhere in that hour is enough - see Dashboard::overviewSeries(). --}}
                    @foreach ($points as $p)
                        <circle
                            cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="3"
                            @class(['fill-red-500' => ! $p['up'], 'fill-accent-500 dark:fill-accent-400' => $p['up']])
                        />
                    @endforeach

                    {{-- End marker + direct value label --}}
                    @if ($last)
                        <circle
                            cx="{{ $last['x'] }}" cy="{{ $last['y'] }}" r="5" stroke-width="2"
                            class="stroke-white dark:stroke-zinc-900 {{ $last['up'] ? 'fill-accent-500 dark:fill-accent-400' : 'fill-red-500' }}"
                        />
                        <text
                            x="{{ $last['x'] - 8 }}" y="{{ max(10, $last['y'] - 10) }}" text-anchor="end"
                            @class(['text-[11px] font-semibold', 'fill-red-600 dark:fill-red-400' => ! $last['up'], 'fill-zinc-900 dark:fill-white' => $last['up']])
                        >{{ $last['ms'] }}ms</text>
                    @endif
                </svg>

                <div
                    x-show="hovered" x-cloak
                    class="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-lg bg-zinc-900 px-2.5 py-1.5 text-xs text-white shadow-lg dark:bg-zinc-700"
                    :style="`left: ${hovered ? Math.min(88, Math.max(12, (hovered.x / chartWidth) * 100)) : 0}%; top: ${hovered ? (hovered.y / chartHeight) * 100 : 0}%; margin-top: -8px;`"
                >
                    <p class="font-medium" x-text="hovered?.time"></p>
                    <p class="text-zinc-300 dark:text-zinc-400">
                        <span x-text="hovered?.ms + 'ms'"></span>
                        &middot;
                        <span x-text="hovered?.up ? 'Up' : 'Down'" :class="hovered?.up ? 'text-emerald-400' : 'text-red-400'"></span>
                    </p>
                </div>
            </div>

            <div class="mt-1 flex justify-between text-[11px] text-zinc-400">
                <span>{{ $responseSeries->first()['label'] }}</span>
                <span>{{ $responseSeries->last()['label'] }}</span>
            </div>
        @endif
    </div>

    {{-- Incidents + uptime/website list --}}
    <div class="grid gap-6 lg:grid-cols-3">

        {{-- Recent incidents --}}
        {{-- min-w-0: a grid item defaults to min-width:auto, so without this the card
        refuses to shrink below its longest incident title and the whole card overflows
        the screen on mobile - the title's own "truncate" never gets to apply. --}}
        <div class="enter min-w-0 rounded-2xl border border-zinc-200 bg-white lg:col-span-2 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center justify-between px-5 py-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Insiden Terbaru</h2>
                @can('insiden.view')
                    <a href="{{ route('insiden') }}" wire:navigate class="text-xs font-medium text-accent-600 transition hover:text-accent-700 dark:text-accent-400 dark:hover:text-accent-300">
                        Lihat semua
                    </a>
                @endcan
            </div>

            @if ($recentIncidents->isEmpty())
                <div class="flex flex-col items-center gap-2 px-5 py-10 text-center">
                    <x-phosphor-shield-check class="h-8 w-8 text-emerald-500" />
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Belum ada insiden. Semua situs aman terkendali.</p>
                </div>
            @else
                <div class="space-y-1 px-3 pb-3 overflow-y-auto">
                    @foreach ($recentIncidents as $incident)
                        @php
                            // A judol incident's detail lives on Deteksi Judol, not on the
                            // incidents page, which lists it without the matched keywords,
                            // the affected URLs, or the AI verdict.
                            $incidentHref = $incident->type === 'judol'
                                ? (auth()->user()?->hasPermission('deteksi-judol.view') ? route('deteksi-judol') : null)
                                : (auth()->user()?->hasPermission('insiden.view') ? route('insiden') : null);
                        @endphp
                        <a
                            @if ($incidentHref) href="{{ $incidentHref }}" wire:navigate @endif
                            class="flex items-center gap-2.5 rounded-xl px-2.5 py-2.5 transition hover:bg-zinc-50 sm:gap-3 dark:hover:bg-zinc-800/60"
                        >
                            <span @class([
                                'flex h-9 w-9 shrink-0 items-center justify-center rounded-full',
                                'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400' => $incident->severity === 'critical',
                                'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' => $incident->severity === 'warning',
                                'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400' => $incident->severity === 'info',
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
                                <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $incident->website->name }}</p>
                            </div>
                            <span @class([
                                'shrink-0 rounded-full px-2.5 py-1 text-xs font-medium',
                                'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' => $incident->status === 'open',
                                'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $incident->status === 'resolved',
                            ])>
                                {{ $incident->status === 'open' ? 'Terbuka' : 'Selesai' }}
                            </span>
                            <span class="hidden w-16 shrink-0 text-right font-mono text-xs text-zinc-400 sm:block">{{ $incident->detected_at->diffForHumans(null, true) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- min-w-0 for the same reason as the incidents card: the site list below
        truncates long names and URLs, which only works if this column can shrink. --}}
        <div class="flex min-w-0 flex-col gap-6">
            {{-- 7-day uptime chart: plain CSS bars --}}
            <div class="enter rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Uptime 7 Hari Terakhir</h2>
                @php
                    $uptimeBars = collect($weeklyUptime)->map(fn ($day) => [
                        'height' => max(8, $day['value']),
                        'class' => 'rounded-md bg-accent-500/85 hover:bg-accent-500 dark:bg-accent-500/70',
                        'label' => $day['label'],
                        'value' => $day['value'].'% uptime',
                        'tone' => null,
                    ])->all();
                @endphp
                <div class="mt-6">
                    <x-bar-chart :bars="$uptimeBars" height="h-24" gap="gap-2.5" />
                    <div class="mt-2 flex gap-2.5">
                        @foreach ($weeklyUptime as $day)
                            <span class="flex-1 text-center font-mono text-[11px] text-zinc-400">{{ $day['label'] }}</span>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Website status list --}}
            <div class="enter rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Status Situs</h2>
                    @can('situs.view')
                        <a href="{{ route('situs') }}" wire:navigate class="text-xs font-medium text-accent-600 transition hover:text-accent-700 dark:text-accent-400 dark:hover:text-accent-300">
                            Lihat semua
                        </a>
                    @endcan
                </div>
                <div class="mt-4 space-y-3.5 overflow-y-auto h-35">
                    @forelse ($websiteStatuses as $website)
                        @php $siteHref = auth()->user()?->hasPermission('situs.view') ? route('situs.show', $website) : null; @endphp
                        <a @if ($siteHref) href="{{ $siteHref }}" wire:navigate @endif class="flex items-center gap-3">
                            <x-status-dot :status="$website->statusKey(
                                isset($offlineIds[$website->id]),
                                isset($attackedIds[$website->id]),
                            )" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $website->name }}</p>
                                <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $website->url }}</p>
                            </div>
                        </a>
                    @empty
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">Belum ada situs yang dipantau.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
