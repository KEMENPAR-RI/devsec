@props(['class' => ''])

{{--
    The two ways this app can be installed, shown by resources/js/pwa.js and
    hidden by default so nothing appears on a desktop browser that already has
    the app or cannot install it at all.

    Two elements rather than one because the platforms differ in kind, not in
    wording: Chromium fires beforeinstallprompt and lets us open the real install
    dialog from a button, while iOS Safari never fires it and has no API at all -
    there, the only route is Share > "Tambah ke Layar Utama", so the best we can
    do is say so. Showing the button on iOS would give people a control that
    cannot work.
--}}
<div {{ $attributes->merge(['class' => 'contents']) }}>
    <button
        type="button"
        data-pwa-install
        hidden
        class="{{ $class ?: 'inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-2.5 py-1 font-medium text-zinc-600 transition hover:border-accent-500 hover:text-accent-600 dark:border-zinc-700 dark:text-zinc-300 dark:hover:border-accent-500 dark:hover:text-accent-400' }}"
    >
        <x-phosphor-download-simple class="h-3.5 w-3.5" />
        Instal aplikasi
    </button>

    <span
        data-pwa-ios
        hidden
        class="inline-flex items-center gap-1.5 text-zinc-500 dark:text-zinc-400"
    >
        <x-phosphor-export class="h-3.5 w-3.5 shrink-0" />
        Instal: ketuk Bagikan, lalu <span class="font-medium text-zinc-700 dark:text-zinc-300">Tambah ke Layar Utama</span>
    </span>
</div>
