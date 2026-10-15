{{-- Alpine state lives on the component root, which morphing keeps across polls; no x-cloak (morph would re-add it). --}}
<div
    @fullscreenchange.document="fs = !! document.fullscreenElement"
    class="flex h-full min-h-0 flex-col"
    x-data="{
        slide: 0,
        total: 1,
        problemSlides: [],
        lastEventAt: undefined,
        notif: null,
        open: false,
        slideTimer: null,
        notifTimer: null,
        clockTimer: null,
        clock: '',
        now: Date.now(),
        fs: !! document.fullscreenElement,
        fsOk: !! document.fullscreenEnabled,
        fmt: null,
        tones: {
            amber: { box: 'border-amber-300 bg-amber-50', text: 'text-amber-800', lamp: 'bg-amber-500', ping: 'bg-amber-400' },
            emerald: { box: 'border-emerald-300 bg-emerald-50', text: 'text-emerald-800', lamp: 'bg-emerald-500', ping: 'bg-emerald-400' },
            red: { box: 'border-red-300 bg-red-50', text: 'text-red-800', lamp: 'bg-red-500', ping: 'bg-red-400' },
        },
        // Rotation pauses while the shown slide has a down site.
        get paused() { return this.problemSlides.includes(this.slide) },
        get tone() {
            let status = this.notif?.status
            return this.tones[status === 'offline' || status === 'unknown' ? 'amber' : (status === 'up' ? 'emerald' : 'red')]
        },
        init() {
            // Read once; a timezone change applies after reload so root x-data never changes.
            let opts = { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }
            try { this.fmt = new Intl.DateTimeFormat('id-ID', { ...opts, timeZone: this.$el.querySelector('[data-timezone]')?.dataset.timezone }) } catch (e) { this.fmt = new Intl.DateTimeFormat('id-ID', opts) }
            this.schedule()
            this.tick()
            let ticks = 0
            this.clockTimer = setInterval(() => {
                this.tick()
                if (++ticks % 30 === 0) this.now = Date.now()
            }, 1000)
        },
        destroy() { clearInterval(this.slideTimer); clearInterval(this.clockTimer); clearTimeout(this.notifTimer) },
        tick() {
            let p = {}
            this.fmt.formatToParts(new Date()).forEach(part => p[part.type] = part.value)
            this.clock = `${p.weekday}, ${p.day} ${p.month} ${p.year} · ${p.hour}:${p.minute}:${p.second}`
        },
        // Relative time from a unix timestamp (seconds), re-evaluated whenever `now` moves.
        ago(ts) {
            let diff = Math.min(0, Math.round(ts - this.now / 1000))
            let rtf = new Intl.RelativeTimeFormat('id-ID')
            for (let [unit, size] of [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60]]) {
                if (-diff >= size) return rtf.format(Math.trunc(diff / size), unit)
            }
            return rtf.format(diff, 'second')
        },
        // Elapsed time since a unix timestamp (seconds), two largest units, e.g. '3 hari 4 jam'.
        duration(ts) {
            let s = Math.max(0, Math.floor(this.now / 1000) - ts)
            let parts = [[Math.floor(s / 86400), 'hari'], [Math.floor(s % 86400 / 3600), 'jam'], [Math.floor(s % 3600 / 60), 'mnt']]
            let i = parts.findIndex(([n]) => n > 0)
            if (i < 0) return '< 1 mnt'
            return parts.slice(i, i + 2).filter(([n]) => n > 0).map(([n, unit]) => `${n} ${unit}`).join(' ')
        },
        go(step) {
            this.slide = (this.slide + step + this.total) % this.total
            this.schedule()
        },
        toggleFullscreen() {
            if (! this.fsOk) return
            let request = document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen()
            request?.catch(() => {})
        },
        schedule() {
            clearInterval(this.slideTimer)
            this.slideTimer = setInterval(() => { if (! this.paused) this.slide = (this.slide + 1) % this.total }, 30000)
        },
        // Called by the keyed sync element below on first load and whenever a poll changes it.
        sync(total, eventAt, event, problemSlides) {
            this.total = total
            this.problemSlides = problemSlides ?? []
            if (this.slide >= total) this.slide = 0
            if (this.lastEventAt === undefined) { this.lastEventAt = eventAt; return }
            // Only a newer outage start counts; a recovery surfacing an older one does not.
            if (! eventAt || (this.lastEventAt && eventAt <= this.lastEventAt)) return
            this.lastEventAt = eventAt
            this.slide = 0
            this.schedule()
            if (event) this.show(event)
        },
        show(event) {
            this.notif = event
            this.open = true
            clearTimeout(this.notifTimer)
            this.notifTimer = setTimeout(() => { this.open = false }, 10000)
        },
    }"
>
    @php($problemSlides = $slides->filter(fn ($slide) => collect($slide)->contains(fn ($site) => in_array($site['status'], ['down', 'attack'], true)))->keys()->all())
    <span
        hidden
        wire:key="tv-sync-{{ $slides->count() }}-{{ $latestEventAt ?? 'none' }}-{{ implode('.', $problemSlides) ?: 'ok' }}"
        x-init="sync({{ $slides->count() }}, @js($latestEventAt), @js($latestEvent), @js($problemSlides))"
    ></span>

    {{-- Keyed by interval: a change swaps the element, and Livewire stops a poll once its element is disconnected. --}}
    <span hidden wire:key="tv-poll-{{ $pollSeconds }}" wire:poll.{{ $pollSeconds }}s></span>

    {{-- Summary from the already-loaded cards; no extra query. --}}
    @php($counts = $slides->flatten(1)->countBy('status'))
    @php($online = $counts->get('up', 0))
    @php($problems = $counts->get('down', 0) + $counts->get('attack', 0))
    @php($offline = $counts->get('offline', 0) + $counts->get('unknown', 0))
    @php($totalSites = $counts->sum())
    {{-- Share is over monitored public sites only; maintenance/internal are not checked publicly. --}}
    @php($monitored = $totalSites - $counts->get('maintenance', 0) - $counts->get('internal', 0))
    @php($onlinePct = $monitored > 0 ? (int) round($online / $monitored * 100) : 0)
    @php($problemPct = $monitored > 0 ? round($problems / $monitored * 100, 2) : 0)
    @php($heroState = $problems ? 'problem' : ($offline ? 'offline' : ($totalSites ? 'ok' : 'empty')))

    <header class="flex items-center justify-between gap-6 px-8 pb-2 pt-3">
        <div class="flex min-w-0 items-center gap-3">
            <x-brand-mark size="md" :image_url="$appLogoUrl ?? null" class="shrink-0 object-contain" />
            <div class="min-w-0">
                <p class="truncate text-lg font-bold leading-tight tracking-tight text-white">{{ $appName ?? app_name() }}</p>
                <p class="text-[0.65rem] font-semibold uppercase tracking-[0.2em] text-secondary-300">Kementerian Pariwisata Republik Indonesia</p>
            </div>
        </div>

        {{-- Clock is Alpine-only; ignore keeps morph from blanking it between ticks. --}}
        <p wire:ignore data-timezone="{{ $timezone }}" class="min-w-0 flex-1 truncate text-center text-xl font-bold tabular-nums tracking-tight text-secondary-300 2xl:text-2xl" x-text="clock"></p>

        {{-- Controls read Alpine state only, so morph is kept out entirely. --}}
        <div wire:ignore class="flex shrink-0 items-center gap-2">
            <button type="button" x-show="total > 1" @style(['display: none' => $slides->count() < 2]) @click="go(-1)" aria-label="Slide sebelumnya" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-accent-500/40 bg-accent-600 text-secondary-300 shadow-md shadow-black/20 transition-colors hover:bg-accent-500 hover:text-secondary-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-300 focus-visible:ring-offset-2 focus-visible:ring-offset-accent-800 motion-reduce:transition-none">
                <x-phosphor-caret-left-bold class="h-5 w-5" aria-hidden="true" />
            </button>
            <p class="min-w-20 text-center text-sm font-medium tabular-nums text-accent-200" x-text="`Slide ${slide + 1} / ${total}`">Slide 1 / {{ $slides->count() }}</p>
            <span x-show="paused && total > 1" style="display: none" class="text-xs font-semibold text-red-300">Dijeda: ada situs down</span>
            <button type="button" x-show="total > 1" @style(['display: none' => $slides->count() < 2]) @click="go(1)" aria-label="Slide berikutnya" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-accent-500/40 bg-accent-600 text-secondary-300 shadow-md shadow-black/20 transition-colors hover:bg-accent-500 hover:text-secondary-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-300 focus-visible:ring-offset-2 focus-visible:ring-offset-accent-800 motion-reduce:transition-none">
                <x-phosphor-caret-right-bold class="h-5 w-5" aria-hidden="true" />
            </button>
            <span x-show="fsOk" style="display: none" class="mx-1 h-7 w-px bg-accent-500/50" aria-hidden="true"></span>
            {{-- Hidden where the Fullscreen API is missing (iOS Safari, some TV browsers). --}}
            <button type="button" x-show="fsOk" style="display: none" @click="toggleFullscreen()" :aria-label="fs ? 'Keluar layar penuh' : 'Layar penuh'" aria-label="Layar penuh" :aria-pressed="fs.toString()" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-accent-500/40 bg-accent-600 text-secondary-300 shadow-md shadow-black/20 transition-colors hover:bg-accent-500 hover:text-secondary-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-300 focus-visible:ring-offset-2 focus-visible:ring-offset-accent-800 motion-reduce:transition-none">
                <x-phosphor-corners-out-bold x-show="! fs" class="h-5 w-5" aria-hidden="true" />
                <x-phosphor-corners-in-bold x-show="fs" style="display: none" class="h-5 w-5" aria-hidden="true" />
            </button>
        </div>
    </header>

    {{-- Hero: the one thing to read from across the room. --}}
    <section @class([
        'mx-8 mt-1 flex items-center gap-6 rounded-2xl border px-6 py-3 2xl:py-4',
        'border-emerald-400/40 bg-emerald-500/10 shadow-lg shadow-black/30' => $heroState === 'ok',
        'border-amber-400/50 bg-amber-500/10 shadow-lg shadow-black/30' => $heroState === 'offline',
        'border-accent-500/40 bg-accent-900/40 shadow-lg shadow-black/30' => $heroState === 'empty',
        'border-red-400/60 bg-red-500/15 shadow-xl shadow-red-500/20 ring-2 ring-red-500/40' => $heroState === 'problem',
    ])>
        <div class="flex min-w-0 flex-1 flex-col gap-2">
            <div class="flex min-w-0 items-center gap-4">
                @if ($heroState === 'problem')
                    <span class="relative flex h-5 w-5 shrink-0 2xl:h-6 2xl:w-6" aria-hidden="true">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75 motion-reduce:animate-none"></span>
                        <span class="relative inline-flex h-full w-full rounded-full bg-red-500"></span>
                    </span>
                    <p class="truncate text-2xl font-black uppercase tracking-tight text-red-200 2xl:text-4xl">{{ $problems }} Situs Bermasalah</p>
                @elseif ($heroState === 'offline')
                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-500 text-white 2xl:h-10 2xl:w-10" aria-hidden="true">
                        <svg class="h-5 w-5 2xl:h-6 2xl:w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3M12 9v4M12 17h.01"/></svg>
                    </span>
                    <p class="truncate text-2xl font-black uppercase tracking-tight text-amber-200 2xl:text-4xl">{{ $offline }} Situs Offline</p>
                @elseif ($heroState === 'empty')
                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-600 text-accent-200 2xl:h-10 2xl:w-10" aria-hidden="true">
                        <svg class="h-5 w-5 2xl:h-6 2xl:w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/></svg>
                    </span>
                    <p class="truncate text-2xl font-black uppercase tracking-tight text-accent-200 2xl:text-4xl">Belum ada situs dipantau</p>
                @else
                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white 2xl:h-10 2xl:w-10" aria-hidden="true">
                        <svg class="h-5 w-5 2xl:h-6 2xl:w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </span>
                    <p class="truncate text-2xl font-black uppercase tracking-tight text-emerald-200 2xl:text-4xl">Semua Sistem Normal</p>
                @endif
            </div>
            <p class="text-sm text-accent-200">Diperbarui <span class="tabular-nums">{{ $diperbarui }}</span></p>
            {{-- Health bar: online share, problem share in red. --}}
            <div class="flex items-center gap-3">
                <div class="flex h-2.5 min-w-0 flex-1 overflow-hidden rounded-full bg-accent-900/70 2xl:h-3" aria-hidden="true">
                    <span class="h-full bg-emerald-500" style="width: {{ $onlinePct }}%"></span>
                    <span class="h-full bg-red-500" style="width: {{ $problemPct }}%"></span>
                </div>
                <p class="shrink-0 text-sm font-bold tabular-nums text-white 2xl:text-base">{{ $onlinePct }}% <span class="font-medium text-accent-200">online</span></p>
            </div>
        </div>

        <ul class="flex shrink-0 items-stretch gap-3" aria-label="Ringkasan status">
            @foreach ([
                ['Online', $online, 'bg-emerald-500', 'text-emerald-300', true],
                ['Bermasalah', $problems, 'bg-red-500', 'text-red-300', true],
                ['Offline', $offline, 'bg-zinc-400', 'text-zinc-300', true],
                ['Pemeliharaan', $counts->get('maintenance', 0), 'bg-sky-500', 'text-sky-300', $counts->get('maintenance', 0) > 0],
                ['Internal', $counts->get('internal', 0), 'bg-amber-500', 'text-amber-300', $counts->get('internal', 0) > 0],
            ] as [$label, $count, $bar, $text, $show])
                @continue(! $show)
                <li @class([
                    'relative flex min-w-24 flex-col items-center justify-center overflow-hidden rounded-xl border px-4 pb-2 pt-3 2xl:min-w-32',
                    'border-red-400/60 bg-red-500/20' => $label === 'Bermasalah' && $count,
                    'border-accent-500/30 bg-accent-900/50' => ! ($label === 'Bermasalah' && $count),
                ])>
                    <span class="absolute inset-x-0 top-0 h-1 {{ $bar }}" aria-hidden="true"></span>
                    <span class="text-3xl font-black leading-none tabular-nums text-white 2xl:text-5xl">{{ $count }}</span>
                    <span class="mt-1 text-[0.65rem] font-bold uppercase tracking-widest 2xl:text-xs {{ $text }}">{{ $label }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Morph must not touch the toast; Alpine alone drives it. --}}
    <div
        wire:ignore
        x-show="open && notif"
        style="display: none"
        x-transition.opacity.duration.500ms
        role="status"
        aria-live="polite"
        class="fixed bottom-6 right-6 z-50 flex w-96 items-start gap-3 rounded-2xl border px-5 py-4 shadow-2xl shadow-black/40 ring-1 ring-black/10"
        :class="tone.box"
    >
        <span class="relative mt-1 flex h-3 w-3 shrink-0">
            <span class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-75 motion-reduce:animate-none" :class="tone.ping"></span>
            <span class="relative inline-flex h-3 w-3 animate-pulse rounded-full motion-reduce:animate-none" :class="tone.lamp"></span>
        </span>
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold" :class="tone.text" x-text="notif?.name"></p>
            <p class="truncate text-xs" :class="tone.text" x-text="notif?.pesan"></p>
        </div>
    </div>

    {{-- Pill tone per public status; literal classes so Tailwind picks them up. --}}
    @php($pillTones = ['up' => 'bg-emerald-500/15 text-emerald-300 ring-emerald-400/40', 'down' => 'bg-red-500 text-white ring-red-300/60', 'attack' => 'bg-red-500 text-white ring-red-300/60', 'maintenance' => 'bg-sky-500/15 text-sky-300 ring-sky-400/40', 'internal' => 'bg-amber-500/15 text-amber-300 ring-amber-400/40', 'offline' => 'bg-zinc-500/20 text-zinc-200 ring-zinc-400/40', 'unknown' => 'bg-zinc-500/20 text-zinc-200 ring-zinc-400/40'])

    {{-- ignore.self: morph keeps Alpine's display state on slide wrappers but still updates their cards. --}}
    <main class="min-h-0 flex-1 px-8 pb-6 pt-4">
        @foreach ($slides as $index => $slide)
            <div wire:key="tv-slide-{{ $index }}" wire:ignore.self x-show="slide === {{ $index }}" @style(['display: none' => $index > 0]) class="grid h-full grid-cols-4 grid-rows-4 gap-3 2xl:gap-4">
                @foreach ($slide as $site)
                    @php($problem = in_array($site['status'], ['down', 'attack'], true))
                    <div class="relative flex min-h-0 flex-col justify-between gap-1 overflow-hidden rounded-2xl border py-2 pl-5 pr-3 2xl:py-3 2xl:pl-6 2xl:pr-4 {{ $site['card'] }} {{ $problem ? 'shadow-xl shadow-red-500/30 ring-2 ring-red-500/60' : 'shadow-lg shadow-black/30' }}">
                        @if ($problem)
                            {{-- Inset ring pulses so the text itself stays steady. --}}
                            <span class="pointer-events-none absolute inset-0 animate-pulse rounded-2xl ring-2 ring-inset ring-red-400/70 motion-reduce:animate-none" aria-hidden="true"></span>
                        @endif
                        {{-- Status bar: same colour as the dot, readable from across the room. --}}
                        <span class="absolute inset-y-0 left-0 w-2 {{ $site['dot'] }}" aria-hidden="true"></span>

                        <div class="flex items-center justify-between gap-2">
                            <span class="inline-flex min-w-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold uppercase tracking-wide ring-1 2xl:text-sm {{ $pillTones[$site['status']] ?? $pillTones['unknown'] }}">
                                <svg class="h-3.5 w-3.5 shrink-0 2xl:h-4 2xl:w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    @if ($site['status'] === 'up')
                                        <path d="M20 6 9 17l-5-5"/>
                                    @elseif ($problem)
                                        <path d="M18 6 6 18M6 6l12 12"/>
                                    @elseif ($site['status'] === 'maintenance')
                                        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                                    @elseif ($site['status'] === 'internal')
                                        <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18ZM6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2M10 6h4M10 10h4M10 14h4M10 18h4"/>
                                    @elseif ($site['status'] === 'offline')
                                        <path d="M12 20h.01M8.5 16.43a5 5 0 0 1 7 0M2 8.82a15 15 0 0 1 4.17-2.65M10.66 5c4.01-.36 8.14.9 11.34 3.76M16.85 11.25a10 10 0 0 1 2.22 1.68M5 13a10 10 0 0 1 5.24-2.76M2 2l20 20"/>
                                    @else
                                        <circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3M12 17h.01"/>
                                    @endif
                                </svg>
                                <span class="truncate">{{ $site['status_label'] }}</span>
                            </span>
                            {{-- Server text is the first paint; Alpine keeps it live from the timestamp. --}}
                            <p class="shrink-0 whitespace-nowrap text-xs text-accent-200 2xl:text-sm" @if ($site['occurred_at_ts']) x-text="ago({{ (int) $site['occurred_at_ts'] }})" @endif>{{ $site['occurred_at'] }}</p>
                        </div>

                        <div class="min-w-0">
                            <p class="truncate text-lg font-bold leading-tight text-white 2xl:text-2xl">{{ $site['name'] }}</p>
                            @if ($site['host'])
                                <p class="truncate text-xs text-accent-200 2xl:text-sm">{{ $site['host'] }}</p>
                            @else
                                <p class="truncate text-xs italic text-accent-200 2xl:text-sm">Jaringan internal</p>
                            @endif
                        </div>

                        {{-- Two short lines so the date is never cut at 1366px. --}}
                        <div class="min-w-0 text-xs leading-tight text-accent-200 2xl:text-sm">
                            @if ($site['up_since_ts'] ?? 0)
                                <p class="truncate"><span class="text-accent-300">Online</span> <span class="tabular-nums" x-text="duration({{ (int) $site['up_since_ts'] }})">{{ $site['up_for'] ?? '' }}</span></p>
                            @endif
                            <p class="truncate"><span class="text-accent-300">Terakhir down</span> {{ $site['last_down_at'] ?? '-' }}</p>
                        </div>
                    </div>
                @endforeach

                {{-- Dashed placeholders keep the 4x4 shape on a partial slide. --}}
                @for ($i = $slide->count(); $i < 16; $i++)
                    <div class="rounded-2xl border border-dashed border-accent-600 bg-accent-900/20"></div>
                @endfor
            </div>
        @endforeach
    </main>
</div>
