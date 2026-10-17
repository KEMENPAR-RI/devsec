@props([
    'series' => [],
    'height' => 220,
    'threshold' => null,
    'empty' => 'Belum ada data pengecekan dalam rentang ini.',
])

{{-- Response-time line + uptime strip; points: label, ms, uptime, up. --}}
@php
    $rows = collect($series)->values();
    $n = $rows->count();
    $hasData = $rows->contains(fn ($p) => ($p['ms'] ?? null) !== null || ($p['uptime'] ?? null) !== null);

    // Round the ceiling up to a half step so the top label reads cleanly.
    $rawMax = max(50, (int) $rows->max('ms'), (int) $threshold);
    $step = 10 ** floor(log10($rawMax)) / 2;
    $maxMs = (int) (ceil($rawMax / $step) * $step);

    // Plot spans 8%..96% of the height; x sits on bucket centres to line up with the strip.
    $toY = fn (int $ms) => round(8 + (1 - min($ms, $maxMs) / $maxMs) * 88, 2);
    $points = $rows->map(fn (array $p, int $i) => [
        'label' => $p['label'],
        'ms' => isset($p['ms']) ? (int) $p['ms'] : null,
        'uptime' => isset($p['uptime']) ? round((float) $p['uptime'], 1) : null,
        'up' => $p['up'] ?? null,
        'x' => $n ? round(($i + 0.5) / $n * 100, 2) : 50,
        'y' => isset($p['ms']) ? $toY((int) $p['ms']) : null,
    ]);

    // Nulls break the line into separate segments instead of bridging the gap.
    $segments = [];
    $current = [];
    foreach ($points as $p) {
        if ($p['y'] !== null) {
            $current[] = $p;
        } elseif ($current) {
            $segments[] = $current;
            $current = [];
        }
    }
    if ($current) {
        $segments[] = $current;
    }

    $last = $points->last(fn ($p) => $p['y'] !== null);
    $thresholdY = $threshold ? $toY((int) $threshold) : null;

    $stripTone = function (array $p): string {
        $uptime = $p['uptime'] ?? ($p['up'] === null ? null : ($p['up'] ? 100 : 0));

        return match (true) {
            $uptime === null => 'bg-zinc-200 dark:bg-zinc-700',
            $uptime >= 99.95 => 'bg-emerald-500',
            $uptime >= 95 => 'bg-amber-400',
            default => 'bg-red-500',
        };
    };
@endphp

@if (! $hasData || $n === 0)
    <div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center gap-2 text-center']) }} style="min-height: {{ (int) $height }}px">
        <x-phosphor-chart-line class="h-7 w-7 text-zinc-300 dark:text-zinc-600" />
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $empty }}</p>
    </div>
@else
    <div
        {{ $attributes->merge(['class' => 'relative']) }}
        wire:key="line-chart-{{ md5(json_encode($points)) }}"
        x-data="{
            points: @js($points),
            index: null,
            get hovered() { return this.index === null ? null : this.points[this.index] },
            pick(event) {
                const rect = this.$refs.plot.getBoundingClientRect();
                const i = Math.floor((event.clientX - rect.left) / rect.width * this.points.length);
                this.index = Math.min(this.points.length - 1, Math.max(0, i));
            },
            step(delta) {
                const i = (this.index ?? (delta > 0 ? -1 : this.points.length)) + delta;
                this.index = Math.min(this.points.length - 1, Math.max(0, i));
            },
            fmtMs(ms) { return ms === null ? 'Tidak ada data' : ms.toLocaleString('id-ID') + 'ms' },
            fmtUp(u) { return u === null ? '-' : (u >= 99.95 ? '100' : u.toFixed(1)) + '% uptime' },
        }"
        @click.outside="index = null"
    >
        <div
            x-ref="plot"
            class="relative touch-pan-y select-none rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500/40"
            style="height: {{ (int) $height }}px"
            tabindex="0"
            role="img"
            aria-label="Grafik response time, {{ $n }} titik. Gunakan panah kiri/kanan untuk melihat nilai."
            @pointermove="pick($event)"
            @pointerdown="pick($event)"
            @pointerleave="$event.pointerType === 'mouse' && (index = null)"
            @keydown.arrow-right.prevent="step(1)"
            @keydown.arrow-left.prevent="step(-1)"
            @keydown.escape="index = null"
            @blur="index = null"
        >
            {{-- Gridlines + strokes only; non-scaling so stretch never thickens them. --}}
            <svg viewBox="0 0 1000 100" preserveAspectRatio="none" class="absolute inset-0 h-full w-full overflow-visible" aria-hidden="true">
                <line x1="0" y1="8" x2="1000" y2="8" class="stroke-zinc-100 dark:stroke-zinc-800" stroke-dasharray="4 4" vector-effect="non-scaling-stroke" />
                <line x1="0" y1="52" x2="1000" y2="52" class="stroke-zinc-100 dark:stroke-zinc-800" stroke-dasharray="4 4" vector-effect="non-scaling-stroke" />
                <line x1="0" y1="96" x2="1000" y2="96" class="stroke-zinc-200 dark:stroke-zinc-700" vector-effect="non-scaling-stroke" />

                @if ($thresholdY !== null)
                    <line x1="0" y1="{{ $thresholdY }}" x2="1000" y2="{{ $thresholdY }}" class="stroke-amber-400/70 dark:stroke-amber-500/60" stroke-dasharray="6 4" vector-effect="non-scaling-stroke" />
                @endif

                @foreach ($segments as $segment)
                    @php
                        $line = collect($segment)->map(fn ($p) => ($p['x'] * 10).','.$p['y'])->implode(' ');
                        $first = $segment[0]['x'] * 10;
                        $end = end($segment)['x'] * 10;
                    @endphp
                    <polygon points="{{ $first }},96 {{ $line }} {{ $end }},96" class="fill-accent-500/10 dark:fill-accent-400/10" />
                    <polyline points="{{ $line }}" fill="none" class="stroke-accent-500 dark:stroke-accent-400" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                @endforeach
            </svg>

            {{-- Y-axis labels --}}
            <span class="pointer-events-none absolute left-0 top-[8%] -translate-y-full pb-0.5 font-mono text-[10px] text-zinc-400 dark:text-zinc-500">{{ number_format($maxMs) }}ms</span>
            <span class="pointer-events-none absolute bottom-[4%] left-0 pb-0.5 font-mono text-[10px] text-zinc-400 dark:text-zinc-500">0ms</span>
            @if ($thresholdY !== null)
                <span class="pointer-events-none absolute right-0 -translate-y-full pb-0.5 text-[10px] text-amber-600 dark:text-amber-400" style="top: {{ $thresholdY }}%">Ambang batas {{ number_format($threshold) }}ms</span>
            @endif

            {{-- Dots: red where the bucket saw a failed check --}}
            @foreach ($points as $p)
                @continue($p['y'] === null)
                <span
                    @class([
                        'pointer-events-none absolute h-1.5 w-1.5 -translate-x-1/2 -translate-y-1/2 rounded-full',
                        'bg-red-500' => $p['up'] === false,
                        'bg-accent-500 dark:bg-accent-400' => $p['up'] !== false,
                    ])
                    style="left: {{ $p['x'] }}%; top: {{ $p['y'] }}%"
                ></span>
            @endforeach

            {{-- Latest value, labelled directly --}}
            @if ($last)
                <span
                    @class([
                        'pointer-events-none absolute h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-white dark:ring-zinc-900',
                        'bg-red-500' => $last['up'] === false,
                        'bg-accent-500 dark:bg-accent-400' => $last['up'] !== false,
                    ])
                    style="left: {{ $last['x'] }}%; top: {{ $last['y'] }}%"
                ></span>
                <span
                    x-show="index === null"
                    @class([
                        'pointer-events-none absolute -translate-x-full -translate-y-full pb-1.5 pr-2 font-mono text-[11px] font-semibold whitespace-nowrap',
                        'text-red-600 dark:text-red-400' => $last['up'] === false,
                        'text-zinc-900 dark:text-white' => $last['up'] !== false,
                    ])
                    style="left: {{ $last['x'] }}%; top: {{ $last['y'] }}%"
                >{{ number_format($last['ms']) }}ms</span>
            @endif

            {{-- Crosshair + focused dot --}}
            <template x-if="hovered">
                <div class="pointer-events-none absolute inset-0">
                    <span class="absolute bottom-[4%] top-[8%] w-px -translate-x-1/2 bg-zinc-300 dark:bg-zinc-600" :style="`left: ${hovered.x}%`"></span>
                    <span
                        x-show="hovered.y !== null"
                        class="absolute h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-white dark:ring-zinc-900"
                        :class="hovered.up === false ? 'bg-red-500' : 'bg-accent-500 dark:bg-accent-400'"
                        :style="`left: ${hovered.x}%; top: ${hovered.y}%`"
                    ></span>
                </div>
            </template>

            {{-- Tooltip, clamped inside the card so it never causes sideways scroll --}}
            <div
                x-show="hovered" x-cloak
                class="pointer-events-none absolute top-0 z-10 -translate-x-1/2 rounded-lg bg-zinc-900 px-2.5 py-1.5 text-xs whitespace-nowrap text-white shadow-lg dark:bg-zinc-700"
                :style="hovered ? `left: clamp(4.5rem, ${hovered.x}%, calc(100% - 4.5rem))` : ''"
                aria-live="polite"
            >
                <p class="font-medium" x-text="hovered?.label"></p>
                <p class="text-zinc-300 dark:text-zinc-400">
                    <span x-text="hovered ? fmtMs(hovered.ms) : ''"></span>
                    &middot;
                    <span
                        x-text="hovered ? fmtUp(hovered.uptime) : ''"
                        :class="hovered?.uptime === null ? '' : (hovered?.uptime >= 99.95 ? 'text-emerald-400' : (hovered?.uptime >= 95 ? 'text-amber-300' : 'text-red-400'))"
                    ></span>
                </p>
            </div>
        </div>

        {{-- Uptime per bucket, same strip style as the TV card --}}
        <div class="mt-3 flex h-2.5 w-full gap-0.5" aria-hidden="true">
            @foreach ($points as $i => $p)
                <span
                    class="flex-1 rounded-sm transition-opacity {{ $stripTone($p) }}"
                    :class="index !== null && index !== {{ $i }} ? 'opacity-40' : ''"
                ></span>
            @endforeach
        </div>

        <div class="mt-2 flex justify-between gap-2 text-[11px] text-zinc-400 dark:text-zinc-500">
            <span class="truncate">{{ $points->first()['label'] }}</span>
            @if ($n >= 3)
                <span class="hidden truncate sm:block">{{ $points[intdiv($n, 2)]['label'] }}</span>
            @endif
            <span class="truncate text-right">{{ $points->last()['label'] }}</span>
        </div>
    </div>
@endif
