{{-- Alpine state lives on the component root, which morphing keeps across polls; no x-cloak (morph would re-add it). --}}
<div
    @fullscreenchange.document="fs = !! document.fullscreenElement"
    class="flex h-full min-h-0 flex-col"
    x-data="{
        slide: 0,
        total: 1,
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
            this.slideTimer = setInterval(() => { this.slide = (this.slide + 1) % this.total }, 30000)
        },
        // Called by the keyed sync element below on first load and whenever a poll changes it.
        sync(total, eventAt, event) {
            this.total = total
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
    <span
        hidden
        wire:key="tv-sync-{{ $slides->count() }}-{{ $latestEventAt ?? 'none' }}"
        x-init="sync({{ $slides->count() }}, @js($latestEventAt), @js($latestEvent))"
    ></span>

    {{-- Keyed by interval: a change swaps the element, and Livewire stops a poll once its element is disconnected. --}}
    <span hidden wire:key="tv-poll-{{ $pollSeconds }}" wire:poll.{{ $pollSeconds }}s></span>

    {{-- Summary from the already-loaded cards; no extra query. --}}
    @php($counts = $slides->flatten(1)->countBy('status'))

    <header class="flex flex-wrap items-center justify-between gap-x-8 gap-y-3 px-8 pb-4 pt-5">
        <div class="flex min-w-0 items-center gap-4">
            <x-brand-mark size="lg" :image_url="$appLogoUrl ?? null" class="shrink-0 ring-2 ring-secondary-300/60" />
            <div class="min-w-0">
                <p class="truncate text-xl font-bold tracking-tight text-white">{{ $appName ?? app_name() }}</p>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-secondary-300">Monitoring Website</p>
            </div>
        </div>

        <div class="min-w-0 flex-1 text-center">
            {{-- Clock is Alpine-only; ignore keeps morph from blanking it between ticks. --}}
            <p wire:ignore data-timezone="{{ $timezone }}" class="truncate text-3xl font-bold tabular-nums tracking-tight text-secondary-300" x-text="clock"></p>
            <p class="text-sm text-accent-300">Diperbarui: <span class="tabular-nums">{{ $diperbarui }}</span></p>
        </div>

        <ul class="flex shrink-0 items-center gap-2" aria-label="Ringkasan status">
            @foreach ([['Online', 'bg-emerald-500', $counts->get('up', 0)], ['Bermasalah', 'bg-red-500', $counts->get('down', 0) + $counts->get('attack', 0)], ['Offline', 'bg-zinc-400', $counts->get('offline', 0) + $counts->get('unknown', 0)]] as [$label, $dot, $count])
                <li class="inline-flex items-center gap-2 rounded-full border border-accent-500/40 bg-accent-600 px-3 py-1.5 text-sm text-accent-200">
                    <span class="h-2.5 w-2.5 rounded-full {{ $dot }}" aria-hidden="true"></span>
                    {{ $label }}
                    <span class="font-bold tabular-nums text-white">{{ $count }}</span>
                </li>
            @endforeach
        </ul>

        {{-- Controls read Alpine state only, so morph is kept out entirely. --}}
        <div wire:ignore class="flex shrink-0 items-center gap-3">
            <button type="button" x-show="total > 1" @style(['display: none' => $slides->count() < 2]) @click="go(-1)" aria-label="Slide sebelumnya" class="inline-flex h-12 w-12 items-center justify-center rounded-xl border border-accent-500/40 bg-accent-600 text-secondary-300 shadow-md shadow-black/20 transition-colors hover:bg-accent-500 hover:text-secondary-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-300 focus-visible:ring-offset-2 focus-visible:ring-offset-accent-800 motion-reduce:transition-none">
                <x-phosphor-caret-left-bold class="h-6 w-6" aria-hidden="true" />
            </button>
            <p class="min-w-24 text-center text-sm font-medium tabular-nums text-accent-200" x-text="`Slide ${slide + 1} / ${total}`">Slide 1 / {{ $slides->count() }}</p>
            <button type="button" x-show="total > 1" @style(['display: none' => $slides->count() < 2]) @click="go(1)" aria-label="Slide berikutnya" class="inline-flex h-12 w-12 items-center justify-center rounded-xl border border-accent-500/40 bg-accent-600 text-secondary-300 shadow-md shadow-black/20 transition-colors hover:bg-accent-500 hover:text-secondary-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-300 focus-visible:ring-offset-2 focus-visible:ring-offset-accent-800 motion-reduce:transition-none">
                <x-phosphor-caret-right-bold class="h-6 w-6" aria-hidden="true" />
            </button>
            <span x-show="fsOk" style="display: none" class="mx-1 h-8 w-px bg-accent-500/50" aria-hidden="true"></span>
            {{-- Hidden where the Fullscreen API is missing (iOS Safari, some TV browsers). --}}
            <button type="button" x-show="fsOk" style="display: none" @click="toggleFullscreen()" :aria-label="fs ? 'Keluar layar penuh' : 'Layar penuh'" aria-label="Layar penuh" :aria-pressed="fs.toString()" class="inline-flex h-12 w-12 items-center justify-center rounded-xl border border-accent-500/40 bg-accent-600 text-secondary-300 shadow-md shadow-black/20 transition-colors hover:bg-accent-500 hover:text-secondary-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-300 focus-visible:ring-offset-2 focus-visible:ring-offset-accent-800 motion-reduce:transition-none">
                <x-phosphor-corners-out-bold x-show="! fs" class="h-6 w-6" aria-hidden="true" />
                <x-phosphor-corners-in-bold x-show="fs" style="display: none" class="h-6 w-6" aria-hidden="true" />
            </button>
        </div>
    </header>
    <div class="mx-8 h-px bg-linear-to-r from-transparent via-secondary-300/70 to-transparent" aria-hidden="true"></div>

    {{-- Morph must not touch the toast; Alpine alone drives it. --}}
    <div
        wire:ignore
        x-show="open && notif"
        style="display: none"
        x-transition.opacity.duration.500ms
        role="status"
        aria-live="polite"
        class="fixed right-6 top-28 z-50 flex w-96 items-start gap-3 rounded-2xl border px-5 py-4 shadow-2xl shadow-black/40 ring-1 ring-black/10"
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

    {{-- ignore.self: morph keeps Alpine's display state on slide wrappers but still updates their cards. --}}
    <main class="min-h-0 flex-1 px-8 py-6">
        @foreach ($slides as $index => $slide)
            <div wire:key="tv-slide-{{ $index }}" wire:ignore.self x-show="slide === {{ $index }}" @style(['display: none' => $index > 0]) class="grid h-full grid-cols-4 grid-rows-4 gap-4">
                @foreach ($slide as $site)
                    <div class="relative flex min-h-0 flex-col justify-between gap-1 overflow-hidden rounded-2xl border py-4 pl-6 pr-4 shadow-lg shadow-black/30 {{ $site['card'] }}">
                        {{-- Status bar: same colour as the dot, readable from across the room. --}}
                        <span class="absolute inset-y-0 left-0 w-2 {{ $site['dot'] }}" aria-hidden="true"></span>
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-3 shrink-0 rounded-full {{ $site['dot'] }}"></span>
                            <p class="truncate text-lg font-bold leading-tight text-zinc-900">{{ $site['name'] }}</p>
                        </div>
                        @if ($site['host'])
                            <p class="truncate text-sm text-zinc-600">{{ $site['host'] }}</p>
                        @else
                            <p class="truncate text-sm italic text-zinc-600">Jaringan internal</p>
                        @endif
                        <div class="flex items-end justify-between gap-2">
                            <p class="text-sm font-bold {{ $site['text'] }}">{{ $site['status_label'] }}</p>
                            {{-- Server text is the first paint; Alpine keeps it live from the timestamp. --}}
                            <p class="truncate text-xs text-zinc-600" @if ($site['occurred_at_ts']) x-text="ago({{ (int) $site['occurred_at_ts'] }})" @endif>{{ $site['occurred_at'] }}</p>
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
