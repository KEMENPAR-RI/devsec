<div class="enter">
    <div class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Atur ulang kata sandi</h1>
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
            Pilih kata sandi baru untuk akun Anda. Minimal 8 karakter.
        </p>
    </div>

    <form wire:submit="resetPassword" class="space-y-5" novalidate>
        <div class="flex flex-col gap-2">
            <label for="email" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Email</label>
            <div class="relative">
                <x-phosphor-envelope-simple class="pointer-events-none absolute left-3 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-zinc-400" />
                <input
                    wire:model="email"
                    id="email"
                    type="email"
                    autocomplete="username"
                    class="w-full rounded-lg border border-zinc-200 bg-white py-2.5 pl-10 pr-3 text-sm text-zinc-900 placeholder:text-zinc-500 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white dark:placeholder:text-zinc-400 @error('email') border-red-400 focus:border-red-500 focus:ring-red-500/30 @enderror"
                >
            </div>
            @error('email')
                <p class="flex items-start gap-1.5 text-sm text-red-600 dark:text-red-400">
                    <x-phosphor-warning-circle class="mt-0.5 h-4 w-4 shrink-0" />
                    {{ $message }}
                </p>
            @enderror
        </div>

        <div class="flex flex-col gap-2" x-data="{ show: false }">
            <label for="password" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Kata Sandi Baru</label>
            <div class="relative">
                <x-phosphor-lock-simple class="pointer-events-none absolute left-3 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-zinc-400" />
                <input
                    wire:model="password"
                    id="password"
                    :type="show ? 'text' : 'password'"
                    autocomplete="new-password"
                    placeholder="********"
                    class="w-full rounded-lg border border-zinc-200 bg-white py-2.5 pl-10 pr-10 text-sm text-zinc-900 placeholder:text-zinc-500 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white dark:placeholder:text-zinc-400 @error('password') border-red-400 focus:border-red-500 focus:ring-red-500/30 @enderror"
                >
                <button
                    type="button"
                    @click="show = !show"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 transition hover:text-zinc-600 dark:hover:text-zinc-300"
                    :aria-label="show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                >
                    <x-phosphor-eye x-show="!show" class="h-4.5 w-4.5" />
                    <x-phosphor-eye-slash x-show="show" class="h-4.5 w-4.5" style="display: none;" />
                </button>
            </div>
            @error('password')
                <p class="flex items-center gap-1.5 text-sm text-red-600 dark:text-red-400">
                    <x-phosphor-warning-circle class="h-4 w-4 shrink-0" />
                    {{ $message }}
                </p>
            @enderror
        </div>

        <div class="flex flex-col gap-2">
            <label for="passwordConfirmation" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Konfirmasi Kata Sandi Baru</label>
            <div class="relative">
                <x-phosphor-lock-simple class="pointer-events-none absolute left-3 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-zinc-400" />
                <input
                    wire:model="passwordConfirmation"
                    id="passwordConfirmation"
                    type="password"
                    autocomplete="new-password"
                    placeholder="********"
                    class="w-full rounded-lg border border-zinc-200 bg-white py-2.5 pl-10 pr-3 text-sm text-zinc-900 placeholder:text-zinc-500 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white dark:placeholder:text-zinc-400 @error('passwordConfirmation') border-red-400 focus:border-red-500 focus:ring-red-500/30 @enderror"
                >
            </div>
            @error('passwordConfirmation')
                <p class="flex items-center gap-1.5 text-sm text-red-600 dark:text-red-400">
                    <x-phosphor-warning-circle class="h-4 w-4 shrink-0" />
                    {{ $message }}
                </p>
            @enderror
        </div>

        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="resetPassword"
            class="flex w-full items-center justify-center gap-2 rounded-lg bg-accent-600 py-2.5 text-sm font-semibold text-white transition hover:bg-accent-700 focus:outline-none focus:ring-2 focus:ring-accent-500/40 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-70"
        >
            <x-loading-label target="resetPassword" loading="Menyimpan...">
                <x-phosphor-check class="h-4 w-4 shrink-0" />
                <span>Simpan Kata Sandi Baru</span>
            </x-loading-label>
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('password.request') }}" wire:navigate class="font-medium text-accent-600 transition hover:text-accent-700 dark:text-accent-400 dark:hover:text-accent-300">
            Minta tautan baru
        </a>
    </p>
</div>
