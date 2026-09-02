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
<body class="h-full bg-zinc-50 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <div
        x-data="{
            sidebarOpen: false,
            desktopSidebarOpen: (localStorage.getItem('sidebarCollapsed') ?? 'false') !== 'true',
            toggleDesktopSidebar() {
                this.desktopSidebarOpen = !this.desktopSidebarOpen;
                localStorage.setItem('sidebarCollapsed', this.desktopSidebarOpen ? 'false' : 'true');
            },
        }"
        class="flex h-full"
    >

        {{-- Mobile overlay --}}
        <div
            x-show="sidebarOpen"
            x-cloak
            x-transition.opacity
            @click="sidebarOpen = false"
            class="fixed inset-0 z-30 bg-zinc-900/40 lg:hidden"
        ></div>

        {{-- Sidebar --}}
        <aside
            :class="{
                'translate-x-0': sidebarOpen,
                '-translate-x-full': !sidebarOpen,
                'lg:w-64': desktopSidebarOpen,
                'lg:w-0 lg:border-r-0': !desktopSidebarOpen,
            }"
            class="fixed inset-y-0 left-0 z-40 w-64 shrink-0 overflow-hidden border-r border-zinc-200 bg-white transition-all duration-200 ease-out lg:static lg:flex lg:translate-x-0 dark:border-zinc-800 dark:bg-zinc-900"
        >
            <div class="flex h-full w-64 shrink-0 flex-col">
                <div class="flex h-16 items-center gap-2.5 border-b border-zinc-200 px-5 dark:border-zinc-800">
                    <x-brand-mark size="sm" :image_url="$appLogoUrl" class="object-contain" />
                    <span class="font-semibold tracking-tight">{{ config('app.name') }}</span>
                </div>

                {{-- Every entry is gated by the same "{page}.view" permission that
                guards its route, so a menu item is only ever visible to someone who
                could actually open it - see App\Support\Permissions. --}}
                <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
                    @can('dashboard.view')
                        <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" icon="house">
                            Dashboard
                        </x-nav-link>
                    @endcan
                    @can('situs.view')
                        <x-nav-link :href="route('situs')" :active="request()->routeIs('situs')" icon="globe">
                            Situs
                        </x-nav-link>
                    @endcan
                    @can('insiden.view')
                        <x-nav-link :href="route('insiden')" :active="request()->routeIs('insiden')" icon="bell-ringing">
                            Insiden
                        </x-nav-link>
                    @endcan
                    @can('deteksi-judol.view')
                        <x-nav-link :href="route('deteksi-judol')" :active="request()->routeIs('deteksi-judol')" icon="shield-warning">
                            Deteksi Judol
                        </x-nav-link>
                    @endcan
                    @can('notifikasi.view')
                        <x-nav-link :href="route('notifikasi')" :active="request()->routeIs('notifikasi')" icon="bell">
                            Notifikasi
                        </x-nav-link>
                    @endcan

                    @canany(['roles.view', 'users.view'])
                        <div class="my-3 border-t border-zinc-200 dark:border-zinc-800"></div>
                        <p class="px-3 pb-1 text-xs font-medium uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Manajemen Akses</p>
                        @can('roles.view')
                            <x-nav-link :href="route('roles')" :active="request()->routeIs('roles')" icon="shield-check">
                                Roles
                            </x-nav-link>
                        @endcan
                        @can('users.view')
                            <x-nav-link :href="route('users')" :active="request()->routeIs('users')" icon="users">
                                Users
                            </x-nav-link>
                        @endcan
                    @endcanany

                    @can('pengaturan.view')
                        <div class="my-3 border-t border-zinc-200 dark:border-zinc-800"></div>
                        <x-nav-link :href="route('pengaturan')" :active="request()->routeIs('pengaturan')" icon="gear">
                            Pengaturan
                        </x-nav-link>
                    @endcan
                </nav>

                <div class="border-t border-zinc-200 p-3 dark:border-zinc-800">
                    <x-confirm-dialog
                        title="Keluar dari akun?"
                        message="Anda perlu masuk kembali untuk mengakses dashboard ini."
                        confirm-text="Keluar"
                        variant="danger"
                        trigger-class="w-full"
                    >
                        <x-slot:trigger>
                            <button type="button" class="group flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100">
                                <x-phosphor-sign-out class="h-5 w-5 shrink-0 text-zinc-400 group-hover:text-zinc-500 dark:group-hover:text-zinc-300" />
                                Keluar
                            </button>
                        </x-slot:trigger>
                        <form method="POST" action="{{ route('logout') }}" x-ref="form">
                            @csrf
                        </form>
                    </x-confirm-dialog>
                </div>
            </div>
        </aside>

        {{-- Main column --}}
        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-16 shrink-0 items-center gap-2 border-b border-zinc-200 bg-white px-4 sm:px-6 dark:border-zinc-800 dark:bg-zinc-900">
                <button
                    type="button"
                    @click="sidebarOpen = true"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800 lg:hidden"
                    aria-label="Buka menu"
                >
                    <x-phosphor-list class="h-5 w-5" />
                </button>

                <button
                    type="button"
                    @click="toggleDesktopSidebar()"
                    class="hidden h-9 w-9 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800 lg:inline-flex"
                    :aria-label="desktopSidebarOpen ? 'Sembunyikan sidebar' : 'Tampilkan sidebar'"
                >
                    <x-phosphor-sidebar-simple class="h-5 w-5" />
                </button>

                {{-- Was purely decorative (no name/action) - submits to the Situs page's
                own #[Url]-bound search, which reads it straight from the query string
                on load. A plain GET form needs no JS and works whether or not the
                current page was itself reached via wire:navigate. --}}
                @can('situs.view')
                <form method="GET" action="{{ route('situs') }}" class="relative ml-1 hidden flex-1 max-w-md sm:block">
                    <x-phosphor-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                    <input
                        type="search"
                        name="search"
                        placeholder="Cari nama situs atau URL..."
                        class="w-full rounded-lg border border-zinc-200 bg-zinc-50 py-2 pl-9 pr-3 text-sm text-zinc-700 placeholder:text-zinc-500 transition focus:border-accent-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:placeholder:text-zinc-400 dark:focus:bg-zinc-900"
                    >
                </form>
                @endcan

                <div class="ml-auto flex items-center gap-1.5">
                    <x-theme-toggle x-data />

                    {{-- Notifications --}}
                    @can('notifikasi.view')
                        <livewire:notifications-dropdown />
                    @endcan

                    <div class="mx-1 h-6 w-px bg-zinc-200 dark:bg-zinc-800"></div>

                    {{-- User menu --}}
                    <div x-data="{ open: false }" class="relative">
                        <button type="button" @click="open = !open" @click.outside="open = false" class="flex items-center gap-2 rounded-lg py-1.5 pl-1.5 pr-2 transition hover:bg-zinc-100 dark:hover:bg-zinc-800">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-accent-100 text-xs font-semibold text-accent-700 dark:bg-accent-500/15 dark:text-accent-400">
                                {{ auth()->user()?->initials() ?? '?' }}
                            </span>
                            <span class="hidden text-sm font-medium sm:block">{{ auth()->user()?->name }}</span>
                            <x-phosphor-caret-down class="hidden h-3.5 w-3.5 text-zinc-400 sm:block" />
                        </button>

                        <x-dropdown-panel class="w-52">
                            <div class="border-b border-zinc-100 px-3.5 py-2.5 dark:border-zinc-800">
                                <p class="truncate text-sm font-medium">{{ auth()->user()?->name }}</p>
                                <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ auth()->user()?->email }}</p>
                                @if ($currentRole = auth()->user()?->role)
                                    <p class="mt-1.5 inline-flex rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $currentRole->name }}</p>
                                @endif
                            </div>
                            <a href="{{ route('profil') }}" wire:navigate class="flex items-center gap-2.5 px-3.5 py-2 text-sm text-zinc-600 transition hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800">
                                <x-phosphor-user-circle class="h-4 w-4 text-zinc-400" />
                                Profil
                            </a>
                            @can('pengaturan.view')
                                <a href="{{ route('pengaturan') }}" wire:navigate class="flex items-center gap-2.5 px-3.5 py-2 text-sm text-zinc-600 transition hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800">
                                    <x-phosphor-gear class="h-4 w-4 text-zinc-400" />
                                    Pengaturan
                                </a>
                            @endcan
                            <x-confirm-dialog
                                title="Keluar dari akun?"
                                message="Anda perlu masuk kembali untuk mengakses dashboard ini."
                                confirm-text="Keluar"
                                variant="danger"
                                trigger-class="w-full"
                            >
                                <x-slot:trigger>
                                    <button type="button" class="flex w-full items-center gap-2.5 px-3.5 py-2 text-left text-sm text-zinc-600 transition hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800 text-red-600 dark:text-red-500 dark:hover:text-red-400">
                                        <x-phosphor-sign-out class="h-4 w-4 text-zinc-400" />
                                        Keluar
                                    </button>
                                </x-slot:trigger>
                                <form method="POST" action="{{ route('logout') }}" x-ref="form">
                                    @csrf
                                </form>
                            </x-confirm-dialog>
                        </x-dropdown-panel>
                    </div>
                </div>
            </header>

            <main class="flex-1 overflow-y-auto px-4 py-6 sm:px-6 lg:px-8">
                {{ $slot }}
            </main>
        </div>
    </div>
    @livewireScripts
</body>
</html>
