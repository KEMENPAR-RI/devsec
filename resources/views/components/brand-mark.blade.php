@props(['size' => 'md', 'image_url' => null])

@php
    $sizes = [
        'sm' => 'h-8 w-8 rounded-lg text-sm',
        'md' => 'h-10 w-10 rounded-xl text-base',
        'lg' => 'h-14 w-14 rounded-2xl text-2xl',
    ];
    // Falls back to 'md' for an unrecognized size instead of an undefined-array-key error.
    $sizeClass = $sizes[$size] ?? $sizes['md'];
    // safe_image_urls() rejects dangerous schemes (javascript:, data:, ...) - null
    // here means either no image was given or what was given wasn't a safe URL,
    // either way falling through to the initials below.
    $safeImageUrl = safe_image_urls($image_url);
@endphp

@if ($safeImageUrl)
    <img src="{{ $safeImageUrl }}" alt="Brand Mark" {{ $attributes->merge(['class' => $sizeClass]) }}>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center justify-center bg-accent-600 font-semibold text-white ' . $sizeClass]) }}>
        {{ Str::upper(substr(config('app.name'), 0, 1)) }}
    </span>
@endif
