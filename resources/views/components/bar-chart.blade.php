@props([
    'bars',
    'height' => 'h-32',
    'gap' => 'gap-1',
])

{{--
    A bar chart with the same hover tooltip as the response-time line chart, rather
    than the browser's native title attribute - which waits about a second, cannot
    be styled, and never appears at all on a touch screen.

    Each bar is ['height' => 0-100, 'class' => '…', 'label' => '…', 'value' => '…',
    'tone' => 'up'|'down'|null].
--}}
<div
    class="relative"
    x-data="{
        hovered: null,
        show(index, event) {
            // Measured against x-ref='plot', not $el: inside an event handler $el is
            // the element the handler sits on - the hovered column - so the offsets
            // came out relative to the bar itself and every tooltip landed at the
            // same place. The coloured bar is measured rather than its full-height
            // wrapper, so the tooltip sits on top of the bar and not the chart.
            const bar = event.currentTarget.querySelector('[data-bar]').getBoundingClientRect();
            const plot = this.$refs.plot.getBoundingClientRect();

            this.hovered = {
                ...this.bars[index],
                left: bar.left - plot.left + bar.width / 2,
                top: bar.top - plot.top,
            };
        },
        bars: @js($bars),
    }"
    @mouseleave="hovered = null"
>
    <div class="flex {{ $height }} items-end {{ $gap }}" x-ref="plot">
        @foreach ($bars as $i => $bar)
            <div
                class="flex h-full flex-1 items-end"
                @mouseenter="show({{ $i }}, $event)"
            >
                <div
                    data-bar
                    class="w-full rounded-sm transition-all duration-300 {{ $bar['class'] }}"
                    style="height: {{ max(4, $bar['height']) }}%"
                ></div>
            </div>
        @endforeach
    </div>

    {{-- Clamped so a tooltip on the first or last bar cannot hang outside the card
    and let the page be dragged sideways on a phone. --}}
    <div
        x-show="hovered" x-cloak
        class="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-full rounded-lg bg-zinc-900 px-2.5 py-1.5 text-xs whitespace-nowrap text-white shadow-lg dark:bg-zinc-700"
        :style="hovered
            ? `left: clamp(3rem, ${hovered.left}px, ${$refs.plot.clientWidth - 48}px); top: ${hovered.top}px; margin-top: -8px;`
            : ''"
    >
        <p class="font-medium" x-text="hovered?.label"></p>
        <p class="text-zinc-300 dark:text-zinc-400">
            <span x-text="hovered?.value"></span>
            <template x-if="hovered?.tone">
                <span>
                    &middot;
                    <span
                        x-text="hovered?.tone === 'up' ? 'Up' : 'Down'"
                        :class="hovered?.tone === 'up' ? 'text-emerald-400' : 'text-red-400'"
                    ></span>
                </span>
            </template>
        </p>
    </div>
</div>
