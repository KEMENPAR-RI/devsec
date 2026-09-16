<div class="enter">
    <div class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Verifikasi dua faktor</h1>
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
            @if ($useRecovery)
                Masukkan salah satu kode pemulihan yang Anda simpan saat mengaktifkan verifikasi dua faktor. Setiap kode hanya bisa dipakai sekali.
            @else
                Buka aplikasi autentikator Anda dan masukkan enam digit kode yang sedang ditampilkan.
            @endif
        </p>
    </div>

    <form wire:submit="verify" class="space-y-5" novalidate>
        @if ($useRecovery)
            <div class="flex flex-col gap-2">
                <label for="recoveryCode" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Kode Pemulihan</label>
                <div class="relative">
                    <x-phosphor-key class="pointer-events-none absolute left-3 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-zinc-400" />
                    <input
                        wire:model="recoveryCode"
                        id="recoveryCode"
                        type="text"
                        autocomplete="one-time-code"
                        autofocus
                        placeholder="xxxxx-xxxxx"
                        class="w-full rounded-lg border border-zinc-200 bg-white py-2.5 pl-10 pr-3 font-mono text-sm text-zinc-900 placeholder:text-zinc-500 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white dark:placeholder:text-zinc-400 @error('recoveryCode') border-red-400 focus:border-red-500 focus:ring-red-500/30 @enderror"
                    >
                </div>
                @error('recoveryCode')
                    <p class="flex items-center gap-1.5 text-sm text-red-600 dark:text-red-400">
                        <x-phosphor-warning-circle class="h-4 w-4 shrink-0" />
                        {{ $message }}
                    </p>
                @enderror
            </div>
        @else
            <div class="flex flex-col gap-2">
                <label for="code" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Kode Verifikasi</label>
                <div class="relative">
                    <x-phosphor-device-mobile class="pointer-events-none absolute left-3 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-zinc-400" />
                    <input
                        wire:model="code"
                        id="code"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        autofocus
                        placeholder="000000"
                        class="w-full rounded-lg border border-zinc-200 bg-white py-2.5 pl-10 pr-3 font-mono text-lg tracking-[0.4em] text-zinc-900 placeholder:text-zinc-500 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white dark:placeholder:text-zinc-400 @error('code') border-red-400 focus:border-red-500 focus:ring-red-500/30 @enderror"
                    >
                </div>
                @error('code')
                    <p class="flex items-center gap-1.5 text-sm text-red-600 dark:text-red-400">
                        <x-phosphor-warning-circle class="h-4 w-4 shrink-0" />
                        {{ $message }}
                    </p>
                @enderror
            </div>
        @endif

        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="verify"
            class="flex w-full items-center justify-center gap-2 rounded-lg bg-accent-600 py-2.5 text-sm font-semibold text-white transition hover:bg-accent-700 focus:outline-none focus:ring-2 focus:ring-accent-500/40 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-70"
        >
            <x-loading-label target="verify" loading="Memverifikasi...">
                <x-phosphor-shield-check class="h-4 w-4 shrink-0" />
                <span>Verifikasi</span>
            </x-loading-label>
        </button>
    </form>

    <div class="mt-6 flex flex-col items-center gap-2 text-center">
        <button type="button" wire:click="toggleRecovery" class="text-sm font-medium text-accent-600 transition hover:text-accent-700 dark:text-accent-400 dark:hover:text-accent-300">
            {{ $useRecovery ? 'Gunakan kode dari aplikasi autentikator' : 'Kehilangan perangkat? Gunakan kode pemulihan' }}
        </button>

        <a href="{{ route('login') }}" wire:navigate class="text-xs text-zinc-500 transition hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300">
            Kembali ke halaman masuk
        </a>
    </div>

    <small class="mt-5 block text-center text-xs text-zinc-500 dark:text-zinc-400">
        Copyright © {{ date('Y') }} Kementerian Pariwisata Republik Indonesia. All rights reserved.
    </small>
</div>
