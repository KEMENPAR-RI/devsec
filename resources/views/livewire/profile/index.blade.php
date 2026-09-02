<div class="mx-auto flex max-w-3xl flex-col gap-6">
    {{-- Header --}}
    <div class="enter flex items-center gap-4">
        <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-accent-100 text-lg font-semibold text-accent-700 dark:bg-accent-500/15 dark:text-accent-400">
            {{ $user->initials() }}
        </span>
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Profil</h1>
            <p class="mt-0.5 flex flex-wrap items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                <span class="truncate">{{ $user->email }}</span>
                @if ($user->role)
                    <span @class([
                        'rounded-full px-2 py-0.5 text-xs font-medium',
                        'bg-accent-50 text-accent-700 dark:bg-accent-500/10 dark:text-accent-400' => $user->role->is_superadmin,
                        'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => ! $user->role->is_superadmin,
                    ])>{{ $user->role->name }}</span>
                @else
                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">Tanpa Role</span>
                @endif
            </p>
        </div>
    </div>

    {{-- Account details --}}
    <div class="enter rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Informasi Akun</h2>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Nama dan email yang dipakai untuk masuk serta menerima notifikasi.</p>

        @if (session('profileStatus'))
            <div class="mt-4 flex items-center gap-2 rounded-lg bg-emerald-50 px-3.5 py-2.5 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                <x-phosphor-check-circle class="h-4 w-4 shrink-0" />
                {{ session('profileStatus') }}
            </div>
        @endif

        <form wire:submit="updateProfile" class="mt-4 flex flex-col gap-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <label for="profileName" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Nama</label>
                    <input
                        id="profileName" type="text" wire:model="name"
                        class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                    >
                    @error('name') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="profileEmail" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Email</label>
                    <input
                        id="profileEmail" type="email" wire:model="email"
                        class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                    >
                    @error('email') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex justify-end">
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="updateProfile"
                    class="inline-flex items-center gap-2 rounded-lg bg-accent-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-accent-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-70"
                >
                    <x-phosphor-spinner class="h-4 w-4 animate-spin" wire:loading wire:target="updateProfile" />
                    <span wire:loading.remove wire:target="updateProfile">Simpan Perubahan</span>
                    <span wire:loading wire:target="updateProfile">Menyimpan...</span>
                </button>
            </div>
        </form>
    </div>

    {{-- Password --}}
    <div class="enter rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Ubah Kata Sandi</h2>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Minimal 8 karakter. Kata sandi saat ini diperlukan sebagai verifikasi.</p>

        @if (session('passwordStatus'))
            <div class="mt-4 flex items-center gap-2 rounded-lg bg-emerald-50 px-3.5 py-2.5 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                <x-phosphor-check-circle class="h-4 w-4 shrink-0" />
                {{ session('passwordStatus') }}
            </div>
        @endif

        <form wire:submit="updatePassword" class="mt-4 flex flex-col gap-4">
            <div class="flex flex-col gap-1.5">
                <label for="currentPassword" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Kata Sandi Saat Ini</label>
                <input
                    id="currentPassword" type="password" wire:model="currentPassword" autocomplete="current-password"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                >
                @error('currentPassword') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <label for="newPassword" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Kata Sandi Baru</label>
                    <input
                        id="newPassword" type="password" wire:model="password" autocomplete="new-password"
                        class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                    >
                    @error('password') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="newPasswordConfirmation" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Konfirmasi Kata Sandi Baru</label>
                    <input
                        id="newPasswordConfirmation" type="password" wire:model="passwordConfirmation" autocomplete="new-password"
                        class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                    >
                    @error('passwordConfirmation') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex justify-end">
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="updatePassword"
                    class="inline-flex items-center gap-2 rounded-lg bg-accent-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-accent-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-70"
                >
                    <x-phosphor-spinner class="h-4 w-4 animate-spin" wire:loading wire:target="updatePassword" />
                    <span wire:loading.remove wire:target="updatePassword">Ubah Kata Sandi</span>
                    <span wire:loading wire:target="updatePassword">Menyimpan...</span>
                </button>
            </div>
        </form>
    </div>

    {{-- What this account may do --}}
    <div class="enter rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Hak Akses</h2>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Ditentukan oleh role Anda dan hanya bisa diubah oleh pengelola role.</p>

        @if (! $user->role)
            <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">Akun Anda belum diberi role, jadi belum ada halaman yang bisa diakses. Hubungi superadmin.</p>
        @elseif ($user->role->is_superadmin)
            <p class="mt-4 flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                <x-phosphor-shield-check class="h-4 w-4 shrink-0 text-accent-500" />
                Akses penuh ke seluruh halaman dan seluruh aksi.
            </p>
        @else
            <div class="mt-4 flex flex-wrap gap-1.5">
                @forelse ($user->role->grantedPermissions() as $permission)
                    <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                        {{ \App\Support\Permissions::label($permission) }}
                    </span>
                @empty
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Role {{ $user->role->name }} belum diberi izin apa pun.</p>
                @endforelse
            </div>
        @endif
    </div>
</div>
