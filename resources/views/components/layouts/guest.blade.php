@props(['title' => null])
@php
    $appSettings = app(\App\Services\AppSettings::class);
    $appLogoUrl = $appSettings->logoUrl();
    $appFaviconUrl = $appSettings->faviconUrl();
@endphp
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title . ' - ' . config('app.name') : config('app.name') }}</title>

    @if ($appFaviconUrl)
        <link rel="icon" href="{{ $appFaviconUrl }}">
    @endif

    <x-theme-script />

    {{-- <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.3.1/css/all.min.css" integrity="sha512-QeR2VH+lsBE5LSAe1Q5EnTBbe7XTBubt8dG93Y7gidSgdMCr8nVqKcfKAMyN96SV8KDbZVTDXChatu5G2KQGzg==" crossorigin="anonymous" referrerpolicy="no-referrer" /> --}}

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-white font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <div class="grid min-h-full lg:grid-cols-2">
        {{-- Brand panel: abstract geometric mark, not a stock photo standing in for one --}}
        <div class="relative hidden overflow-hidden bg-accent-950 lg:flex lg:flex-col lg:justify-between lg:p-12">
            <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                <div class="absolute -left-24 -top-24 h-96 w-96 rounded-full bg-accent-500/20 blur-3xl"></div>
                <div class="absolute -bottom-32 -right-16 h-96 w-96 rounded-full bg-accent-400/10 blur-3xl"></div>
                <svg class="absolute inset-0 h-full w-full opacity-[0.07]" viewBox="0 0 400 400" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M0 40H400" stroke="white" stroke-width="1"/>
                    <path d="M0 120H400" stroke="white" stroke-width="1"/>
                    <path d="M0 200H400" stroke="white" stroke-width="1"/>
                    <path d="M0 280H400" stroke="white" stroke-width="1"/>
                    <path d="M0 360H400" stroke="white" stroke-width="1"/>
                    <path d="M40 0V400" stroke="white" stroke-width="1"/>
                    <path d="M160 0V400" stroke="white" stroke-width="1"/>
                    <path d="M280 0V400" stroke="white" stroke-width="1"/>
                </svg>
            </div>

            <div class="relative flex items-center gap-3">
                <x-brand-mark size="md" :image_url="$appLogoUrl" class="object-contain" />
                <div class="flex flex-col">
                    <span class="text-lg font-semibold tracking-tight text-white">
                        {{ config('app.name') }}
                    </span>
                    <small class="text-sm text-accent-200">
                        Kementerian Pariwisata Republik Indonesia
                    </small>
                </div>
            </div>

            <div class="relative max-w-sm">
                <p class="text-2xl font-medium leading-snug tracking-tight text-white">
                    Security App Monitoring
                </p>
                <p class="mt-4 text-sm text-accent-200">
                    Monitor and manage your security applications with ease.
                </p>
            </div>
        </div>

        {{-- Form panel --}}
        <div class="flex flex-col justify-center px-6 py-12 sm:px-12 lg:px-16">
            <div class="mx-auto w-full max-w-sm">
                <div class="mb-8 flex items-center justify-between lg:hidden">
                    <div class="flex items-center gap-2.5">
                        <x-brand-mark size="sm" :image_url="$appLogoUrl" class="object-contain" />
                        <span class="font-semibold tracking-tight">{{ config('app.name') }}</span>
                    </div>
                    <x-theme-toggle x-data />
                </div>

                <div class="mb-8 hidden justify-end lg:flex">
                    <x-theme-toggle x-data />
                </div>

                {{ $slot }}
            </div>
        </div>
    </div>
    @livewireScripts
</body>
</html>
