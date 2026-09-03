@props(['class' => ''])

{{--
    The ways this app can be installed, revealed by resources/js/pwa.js and hidden
    by default so nothing appears where installing is impossible or already done.

    Three elements, because the platforms differ in kind, not in wording:

    - Chromium fires beforeinstallprompt, so a button can open the real dialog.
    - iOS Safari never fires it and exposes no install API at all; the only route
      is Share > "Tambah ke Layar Utama". This used to be plain text, which read
      as a dead button - so it is a real button now, and tapping it shows the
      steps.
    - Neither works off a secure origin. A browser silently refuses both the
      service worker and the install prompt over plain http, which looks exactly
      like the feature having been left out of the build. Saying so beats leaving
      a blank space where the button should be.
--}}
<div {{ $attributes }} x-data="{ ios: false }">
    <button
        type="button"
        data-pwa-install
        hidden
        class="{{ $class ?: 'inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-2.5 py-1 font-medium text-zinc-600 transition hover:border-accent-500 hover:text-accent-600 dark:border-zinc-700 dark:text-zinc-300 dark:hover:border-accent-500 dark:hover:text-accent-400' }}"
    >
        <x-phosphor-download-simple class="h-3.5 w-3.5" />
        Instal aplikasi
    </button>

    {{-- iOS: a real, tappable control rather than a sentence that looks disabled. --}}
    <div data-pwa-ios hidden class="relative inline-block">
        <button
            type="button"
            @click="ios = !ios"
            :aria-expanded="ios"
            class="{{ $class ?: 'inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-2.5 py-1 font-medium text-zinc-600 transition hover:border-accent-500 hover:text-accent-600 dark:border-zinc-700 dark:text-zinc-300 dark:hover:border-accent-500 dark:hover:text-accent-400' }}"
        >
            <x-phosphor-download-simple class="h-3.5 w-3.5" />
            Instal aplikasi
        </button>

        <div
            x-show="ios"
            x-cloak
            x-transition
            @click.outside="ios = false"
            @keydown.escape.window="ios = false"
            {{-- right-0 so it opens inward from the footer's right edge instead of
            pushing the page sideways on a narrow screen. --}}
            class="absolute bottom-full right-0 z-50 mb-2 w-64 rounded-xl border border-zinc-200 bg-white p-3 text-left shadow-xl shadow-zinc-900/10 dark:border-zinc-700 dark:bg-zinc-800"
        >
            <p class="text-xs font-semibold text-zinc-900 dark:text-white">Instal di iPhone / iPad</p>
            <ol class="mt-2 flex flex-col gap-1.5 text-xs text-zinc-600 dark:text-zinc-300">
                <li class="flex gap-1.5">
                    <span class="font-mono text-zinc-400">1.</span>
                    <span>Ketuk tombol <x-phosphor-export class="inline h-3.5 w-3.5 align-text-bottom" /> Bagikan di bilah Safari.</span>
                </li>
                <li class="flex gap-1.5">
                    <span class="font-mono text-zinc-400">2.</span>
                    <span>Geser ke bawah, pilih <span class="font-medium text-zinc-900 dark:text-white">Tambah ke Layar Utama</span>.</span>
                </li>
                <li class="flex gap-1.5">
                    <span class="font-mono text-zinc-400">3.</span>
                    <span>Ketuk <span class="font-medium text-zinc-900 dark:text-white">Tambah</span>.</span>
                </li>
            </ol>
            <p class="mt-2 border-t border-zinc-100 pt-2 text-[11px] leading-relaxed text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                Safari tidak menyediakan tombol instal otomatis, jadi langkah ini harus lewat menu Bagikan.
            </p>
        </div>
    </div>

    {{-- Insecure origin: installing is impossible until the site is on https. --}}
    <span
        data-pwa-insecure
        hidden
        class="inline-flex items-center gap-1.5 text-zinc-500 dark:text-zinc-400"
        title="Browser hanya mengizinkan pemasangan aplikasi web dari origin aman (https, atau localhost saat pengembangan)."
    >
        <x-phosphor-warning class="h-3.5 w-3.5 shrink-0" />
        Instalasi butuh HTTPS
    </span>
</div>
