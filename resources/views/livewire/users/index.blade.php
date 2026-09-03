<div class="mx-auto flex max-w-7xl flex-col gap-6">
    @if (session('status'))
        <div class="enter flex items-center gap-2 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
            <x-phosphor-check-circle class="h-4 w-4 shrink-0" />
            {{ session('status') }}
        </div>
    @endif

    {{-- Header --}}
    <div class="enter flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Users</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Kelola akun pengguna dan role yang menentukan hak aksesnya.</p>
        </div>
        @can('users.create')
            <button
                type="button"
                wire:click="openCreate"
                class="inline-flex shrink-0 items-center gap-2 self-start rounded-lg bg-accent-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-accent-700 active:scale-[0.98] sm:self-auto"
            >
                <x-phosphor-plus class="h-4 w-4" />
                Tambah Pengguna
            </button>
        @endcan
    </div>

    <x-list-card
        :is-empty="$users->isEmpty()"
        empty-icon="phosphor-users"
        empty-title="Tidak ada pengguna"
        empty-message="Tidak ada pengguna yang cocok dengan pencarian atau filter ini."
    >
        <x-slot:toolbar>
            <x-search-input model="search" placeholder="Cari nama atau email..." class="sm:max-w-xs" />
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                <select wire:model.live="roleFilter" class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                    <option value="">Semua Role</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->uuid }}">{{ $role->name }}</option>
                    @endforeach
                    <option value="none">Tanpa Role</option>
                </select>
                <select wire:model.live="statusFilter" class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                    <option value="">Semua Status</option>
                    <option value="active">Aktif</option>
                    <option value="banned">Diblokir</option>
                </select>
            </div>
        </x-slot:toolbar>

        <div class="divide-y divide-zinc-100 transition-opacity dark:divide-zinc-800" wire:loading.class="opacity-40" wire:target="previousPage,nextPage,gotoPage,search,roleFilter">
            @foreach ($users as $user)
                <div @class([
                    'flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:gap-4',
                    'bg-red-50/40 dark:bg-red-500/[0.03]' => $user->isBanned(),
                ])>
                    <span @class([
                        'flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                        'bg-zinc-100 text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500' => $user->isBanned(),
                        'bg-accent-100 text-accent-700 dark:bg-accent-500/15 dark:text-accent-400' => ! $user->isBanned(),
                    ])>
                        {{ $user->initials() }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $user->name }}</span>
                            @if ($user->is(auth()->user()))
                                <span class="rounded-full bg-accent-50 px-2 py-0.5 text-xs font-medium text-accent-700 dark:bg-accent-500/10 dark:text-accent-400">Anda</span>
                            @endif
                            @if ($user->isBanned())
                                <span class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-500/10 dark:text-red-400">
                                    <x-phosphor-prohibit class="h-3 w-3" />
                                    Diblokir
                                </span>
                            @endif
                        </div>
                        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $user->email }}</p>
                        @if ($user->isBanned())
                            <p class="mt-0.5 truncate text-xs text-red-600/80 dark:text-red-400/70">
                                Diblokir {{ $user->banned_at->translatedFormat('d M Y, H:i') }}{{ $user->banned_reason ? ' - ' . $user->banned_reason : '' }}
                            </p>
                        @endif
                    </div>

                    <div class="shrink-0">
                        @if ($user->role)
                            <span @class([
                                'rounded-full px-2.5 py-1 text-xs font-medium',
                                'bg-accent-50 text-accent-700 dark:bg-accent-500/10 dark:text-accent-400' => $user->role->is_superadmin,
                                'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => ! $user->role->is_superadmin,
                            ])>{{ $user->role->name }}</span>
                        @else
                            <span title="Tanpa role, pengguna ini tidak bisa membuka halaman apa pun selain profilnya." class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">Tanpa Role</span>
                        @endif
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        @can('users.update')
                            <button
                                type="button"
                                wire:click="openEdit('{{ $user->uuid }}')"
                                title="Ubah"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                            >
                                <x-phosphor-pencil-simple class="h-4 w-4" />
                            </button>
                        @endcan
                        @can('users.update')
                            @unless ($user->is(auth()->user()))
                                @if ($user->isBanned())
                                    <x-confirm-dialog
                                        title="Cabut blokir {{ $user->name }}?"
                                        message="Akun ini bisa masuk kembali seperti semula, dengan role yang sama."
                                        confirm-text="Ya, Cabut Blokir"
                                        variant="success"
                                        target="unban('{{ $user->uuid }}')"
                                    >
                                        <x-slot:trigger>
                                            <button type="button" title="Cabut blokir" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-emerald-50 hover:text-emerald-600 dark:text-zinc-400 dark:hover:bg-emerald-500/10 dark:hover:text-emerald-400">
                                                <x-phosphor-lock-key-open class="h-4 w-4" />
                                            </button>
                                        </x-slot:trigger>
                                        <form wire:submit="unban('{{ $user->uuid }}')" x-ref="form"></form>
                                    </x-confirm-dialog>
                                @else
                                    <button
                                        type="button"
                                        wire:click="openBan('{{ $user->uuid }}')"
                                        title="Blokir akun"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-amber-50 hover:text-amber-600 dark:text-zinc-400 dark:hover:bg-amber-500/10 dark:hover:text-amber-400"
                                    >
                                        <x-phosphor-prohibit class="h-4 w-4" />
                                    </button>
                                @endif
                            @endunless
                        @endcan
                        @can('users.delete')
                            @unless ($user->is(auth()->user()))
                                <x-confirm-dialog
                                    title="Hapus {{ $user->name }}?"
                                    message="Akun ini akan kehilangan akses ke dashboard. Tindakan ini tidak bisa dibatalkan."
                                    confirm-text="Ya, Hapus"
                                    variant="danger"
                                    target="delete('{{ $user->uuid }}')"
                                >
                                    <x-slot:trigger>
                                        <button type="button" title="Hapus" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-red-50 hover:text-red-600 dark:text-zinc-400 dark:hover:bg-red-500/10 dark:hover:text-red-400">
                                            <x-phosphor-trash class="h-4 w-4" />
                                        </button>
                                    </x-slot:trigger>
                                    <form wire:submit="delete('{{ $user->uuid }}')" x-ref="form"></form>
                                </x-confirm-dialog>
                            @endunless
                        @endcan
                    </div>
                </div>
            @endforeach
        </div>

        <x-slot:footer>
            {{ $users->links() }}
        </x-slot:footer>
    </x-list-card>

    @can('users.update')
        {{-- Ban modal: the reason is optional but is shown to the user on their
        next sign-in attempt, so it's worth filling in. --}}
        <div
            x-show="$wire.showBanModal"
            x-cloak
            class="fixed inset-0 z-[100] flex items-center justify-center p-4"
            @keydown.escape.window="$wire.showBanModal = false"
            role="dialog"
            aria-modal="true"
        >
            <div x-show="$wire.showBanModal" x-transition.opacity wire:click="$set('showBanModal', false)" class="absolute inset-0 bg-zinc-900/50"></div>

            <div
                x-show="$wire.showBanModal"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                class="relative w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-5 shadow-xl shadow-zinc-900/10 dark:border-zinc-800 dark:bg-zinc-900"
            >
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400">
                        <x-phosphor-prohibit class="h-5 w-5" />
                    </span>
                    <div class="min-w-0 flex-1 pt-0.5">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Blokir {{ $banning?->name }}?</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Akun tidak dihapus - datanya tetap utuh, hanya tidak bisa masuk. Sesi yang sedang berjalan langsung diakhiri. Blokir bisa dicabut kapan saja.
                        </p>
                    </div>
                </div>

                <form wire:submit="ban" class="mt-4 flex flex-col gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label for="banReason" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Alasan <span class="font-normal text-zinc-400">(opsional, ditampilkan ke pengguna)</span></label>
                        <input
                            id="banReason" type="text" wire:model="banReason" placeholder="Contoh: akun tidak lagi digunakan"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        >
                        @error('banReason') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="$set('showBanModal', false)" class="rounded-lg px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 active:scale-[0.98] dark:text-zinc-300 dark:hover:bg-zinc-800">
                            Batal
                        </button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="ban"
                            class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-amber-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-70"
                        >
                            <x-phosphor-spinner class="h-4 w-4 animate-spin" wire:loading wire:target="ban" />
                            <span wire:loading.remove wire:target="ban">Blokir Akun</span>
                            <span wire:loading wire:target="ban">Memblokir...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endcan

    @canany(['users.create', 'users.update'])
        {{-- Add / edit modal --}}
        <div
            x-show="$wire.showModal"
            x-cloak
            class="fixed inset-0 z-[100] flex items-center justify-center p-4"
            @keydown.escape.window="$wire.showModal = false"
            role="dialog"
            aria-modal="true"
        >
            <div x-show="$wire.showModal" x-transition.opacity wire:click="$set('showModal', false)" class="absolute inset-0 bg-zinc-900/50"></div>

            <div
                x-show="$wire.showModal"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                class="relative w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-5 shadow-xl shadow-zinc-900/10 dark:border-zinc-800 dark:bg-zinc-900"
            >
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $editing ? 'Ubah Pengguna' : 'Tambah Pengguna' }}</h2>

                <form wire:submit="save" class="mt-4 flex flex-col gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label for="userName" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Nama</label>
                        <input
                            id="userName" type="text" wire:model="name" placeholder="Nama lengkap"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        >
                        @error('name') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="userEmail" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Email</label>
                        <input
                            id="userEmail" type="email" wire:model="email" placeholder="nama@instansi.go.id"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        >
                        @error('email') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="userRole" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Role</label>
                        <select
                            id="userRole" wire:model="roleUuid"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        >
                            <option value="">Tanpa role (tidak bisa mengakses apa pun)</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->uuid }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                        @error('roleUuid') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="userPassword" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            Kata Sandi
                            @if ($editing)
                                <span class="font-normal text-zinc-400">(kosongkan bila tidak diubah)</span>
                            @endif
                        </label>
                        <input
                            id="userPassword" type="password" wire:model="password" autocomplete="new-password" placeholder="Minimal 8 karakter"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        >
                        @error('password') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="userPasswordConfirmation" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Konfirmasi Kata Sandi</label>
                        <input
                            id="userPasswordConfirmation" type="password" wire:model="passwordConfirmation" autocomplete="new-password"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        >
                        @error('passwordConfirmation') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-2 flex justify-end gap-2">
                        <button type="button" wire:click="$set('showModal', false)" class="rounded-lg px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 active:scale-[0.98] dark:text-zinc-300 dark:hover:bg-zinc-800">
                            Batal
                        </button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="save"
                            class="inline-flex items-center gap-2 rounded-lg bg-accent-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-accent-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-70"
                        >
                            <x-phosphor-spinner class="h-4 w-4 animate-spin" wire:loading wire:target="save" />
                            <span wire:loading.remove wire:target="save">Simpan</span>
                            <span wire:loading wire:target="save">Menyimpan...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endcanany
</div>
