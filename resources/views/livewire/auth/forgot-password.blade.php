<div class="enter">
    <div class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Lupa kata sandi?</h1>
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
            Masukkan email akun Anda. Kami kirimkan tautan untuk mengatur ulang kata sandi.
        </p>
    </div>

    @if ($sent)
        {{-- Same wording whether or not the address has an account here, so this
        page can't be used to check which emails are registered. --}}
        <div class="flex flex-col items-start gap-4">
            <div class="flex w-full items-start gap-3 rounded-lg bg-emerald-50 px-4 py-3.5 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                <x-phosphor-check-circle class="mt-0.5 h-4 w-4 shrink-0" />
                <div>
                    <p class="font-medium">Tautan sudah dikirim.</p>
                    <p class="mt-1 text-emerald-600/90 dark:text-emerald-400/80">
                        Kalau <span class="font-mono">{{ $email }}</span> terdaftar, tautan reset kata sandi sudah masuk ke kotak masuknya. Periksa juga folder spam. Tautan berlaku 60 menit.
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <a
                    href="{{ route('login') }}"
                    wire:navigate
                    class="inline-flex items-center gap-2 rounded-lg bg-accent-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-accent-700 active:scale-[0.98]"
                >
                    Kembali ke halaman masuk
                </a>
                <button
                    type="button"
                    wire:click="$set('sent', false)"
                    class="text-sm font-medium text-zinc-500 transition hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200"
                >
                    Kirim ulang
                </button>
            </div>
        </div>
    @else
        <x-recaptcha action="password_reset" />

        <form @submit.prevent="$recaptcha(() => $wire.sendResetLink())" x-data class="space-y-5" novalidate>
            <div class="flex flex-col gap-2">
                <label for="email" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Email</label>
                <div class="relative">
                    <x-phosphor-envelope-simple class="pointer-events-none absolute left-3 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-zinc-400" />
                    <input
                        wire:model="email"
                        id="email"
                        type="email"
                        autocomplete="username"
                        placeholder="nama@email.com"
                        class="w-full rounded-lg border border-zinc-200 bg-white py-2.5 pl-10 pr-3 text-sm text-zinc-900 placeholder:text-zinc-500 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white dark:placeholder:text-zinc-400 @error('email') border-red-400 focus:border-red-500 focus:ring-red-500/30 @enderror"
                    >
                </div>
                @error('email')
                    <p class="flex items-center gap-1.5 text-sm text-red-600 dark:text-red-400">
                        <x-phosphor-warning-circle class="h-4 w-4 shrink-0" />
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="sendResetLink"
                class="flex w-full items-center justify-center gap-2 rounded-lg bg-accent-600 py-2.5 text-sm font-semibold text-white transition hover:bg-accent-700 focus:outline-none focus:ring-2 focus:ring-accent-500/40 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-70"
            >
                <x-loading-label target="sendResetLink" loading="Mengirim...">
                    <x-phosphor-paper-plane-tilt class="h-4 w-4 shrink-0" />
                    <span>Kirim Tautan Reset</span>
                </x-loading-label>
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
            Ingat kata sandi Anda?
            <a href="{{ route('login') }}" wire:navigate class="font-medium text-accent-600 transition hover:text-accent-700 dark:text-accent-400 dark:hover:text-accent-300">
                Masuk di sini
            </a>
        </p>
    @endif
</div>
