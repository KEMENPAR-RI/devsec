@props([
    'target',
    'loading' => null,
    'spinner' => 'h-4 w-4',
    'gap' => 'gap-2',
])

{{--
    A button's contents, sized so the button cannot change width when it is clicked.

    Both states - the normal label and the spinner - sit stacked in the same grid
    cell and swap with `invisible` rather than with display:none. An element that is
    display:none drops out of the width calculation, so hiding one state made the
    button shrink and then stretch again mid-click; kept invisible, both states still
    claim their space, and the button is always as wide as its longest state.

    The spinner takes the leading icon's PLACE rather than being inserted beside it.
    Inserting it was what shoved the label to the right at the exact moment the
    button was pressed - and on a button with no icon at all, it grew one out of
    nowhere. Same technique <x-confirm-dialog> already uses for its trigger.
--}}
<span {{ $attributes->merge(['class' => 'grid']) }}>
    <span
        wire:loading.class="invisible"
        wire:target="{{ $target }}"
        class="col-start-1 row-start-1 inline-flex items-center justify-center {{ $gap }} leading-none"
    >
        {{ $slot }}
    </span>

    <span
        wire:loading.class.remove="invisible"
        wire:target="{{ $target }}"
        class="invisible col-start-1 row-start-1 inline-flex items-center justify-center {{ $gap }} leading-none"
    >
        <x-phosphor-spinner class="{{ $spinner }} shrink-0 animate-spin" />
        @if ($loading)
            <span>{{ $loading }}</span>
        @endif
    </span>
</span>
