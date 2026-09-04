{{--
    Sits at the bottom of the scrolling area on every dashboard page, so it
    follows the content instead of stealing a strip of the viewport on short
    screens.

    Also the home of the PWA controls (see resources/js/pwa.js): the install
    affordance, which stays hidden until the browser actually offers an install,
    and the offline badge - worth having in a monitoring tool, where "no
    incidents" and "no connection" look identical on screen.
--}}
<footer class="border-t border-zinc-200 bg-white px-4 py-4 sm:px-6 lg:px-8 dark:border-zinc-800 dark:bg-zinc-900">
    <div class="flex flex-col gap-3 text-xs text-zinc-500 sm:flex-row sm:items-center sm:justify-between dark:text-zinc-400">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span>
                &copy; {{ now()->year }}
                <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ config('app.name') }}</span>
            </span>
            <span class="hidden h-3 w-px bg-zinc-200 sm:block dark:bg-zinc-700" aria-hidden="true"></span>
            <span>Kementerian Pariwisata Republik Indonesia</span>
        </div>

        <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-2">
            <span
                data-pwa-offline
                hidden
                class="inline-flex items-center justify-center gap-1.5 rounded-full bg-amber-50 px-2 py-1 font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-400"
            >
                <span class="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                Mode offline
            </span>

            <x-pwa-install />

            <a href="{{ route('profil') }}" wire:navigate class="transition hover:text-zinc-900 dark:hover:text-zinc-200">Profil</a>

            @can('pengaturan.view')
                <a href="{{ route('pengaturan') }}" wire:navigate class="transition hover:text-zinc-900 dark:hover:text-zinc-200">Pengaturan</a>
            @endcan
        </div>
    </div>
</footer>
