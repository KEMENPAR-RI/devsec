@props(['status'])

{{--
    The coloured dot for a website's Website::statusKey(). Colours and labels come
    from Website::STATUSES so the dot, <x-status-badge> and the detail header can
    never disagree about what a status is called or what colour it is.
--}}
@php $config = \App\Models\Website::statusConfig($status); @endphp

<span
    title="{{ $config['label'] }} - {{ $config['description'] }}"
    {{ $attributes->merge(['class' => "h-2.5 w-2.5 shrink-0 rounded-full {$config['dot']}"]) }}
></span>
