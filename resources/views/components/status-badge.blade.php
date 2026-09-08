@props(['status'])

{{--
    The status as a filled pill in its own colour, for places where a bare dot is
    not enough: a colour has to be learned, carries nothing for a colour-blind
    reader, and the two outage states are the same red - only the name separates
    them. Colours and labels come from Website::STATUSES.
--}}
@php $config = \App\Models\Website::statusConfig($status); @endphp

<span
    title="{{ $config['description'] }}"
    {{ $attributes->merge(['class' => 'inline-flex items-center justify-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap ring-1 ring-inset '.$config['badge']]) }}
>
    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $config['dot'] }}"></span>
    {{ $config['label'] }}
</span>
