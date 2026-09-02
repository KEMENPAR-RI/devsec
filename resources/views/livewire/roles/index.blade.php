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
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Roles</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Tentukan halaman apa saja yang bisa diakses sebuah peran, beserta aksinya. Menu yang tidak diizinkan akan hilang dari sidebar penggunanya.</p>
        </div>
        @can('roles.create')
            <button
                type="button"
                wire:click="openCreate"
                class="inline-flex shrink-0 items-center gap-2 self-start rounded-lg bg-accent-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-accent-700 active:scale-[0.98] sm:self-auto"
            >
                <x-phosphor-plus class="h-4 w-4" />
                Tambah Role
            </button>
        @endcan
    </div>

    <x-list-card
        :is-empty="$roles->isEmpty()"
        empty-icon="phosphor-shield-check"
        empty-title="Belum ada role"
        empty-message="Buat role pertama untuk mulai mengatur hak akses pengguna."
    >
        <x-slot:toolbar>
            <x-search-input model="search" placeholder="Cari nama atau slug role..." />
        </x-slot:toolbar>

        <div class="divide-y divide-zinc-100 transition-opacity dark:divide-zinc-800" wire:loading.class="opacity-40" wire:target="previousPage,nextPage,gotoPage,search">
            @foreach ($roles as $role)
                @php $granted = $role->grantedPermissions(); @endphp
                <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start sm:gap-4">
                    <span @class([
                        'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                        'bg-accent-50 text-accent-600 dark:bg-accent-500/10 dark:text-accent-400' => $role->is_superadmin,
                        'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => ! $role->is_superadmin,
                    ])>
                        <x-phosphor-shield-check class="h-4 w-4" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $role->name }}</span>
                            <code class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $role->slug }}</code>
                            @if ($role->is_locked)
                                <span title="Role bawaan sistem - nama & slug-nya tidak bisa diubah." class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">Bawaan</span>
                            @endif
                        </div>
                        @if ($role->description)
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $role->description }}</p>
                        @endif
                    </div>

                    <div class="flex shrink-0 items-center gap-4 text-xs text-zinc-500 dark:text-zinc-400">
                        <span>{{ $role->is_superadmin ? 'Akses penuh' : count($granted) . ' izin' }}</span>
                        <span>{{ $role->users_count }} pengguna</span>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        @can('roles.update')
                            <button
                                type="button"
                                wire:click="openEdit('{{ $role->uuid }}')"
                                title="Ubah"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                            >
                                <x-phosphor-pencil-simple class="h-4 w-4" />
                            </button>
                        @endcan
                        @can('roles.delete')
                            @unless ($role->is_locked)
                                <x-confirm-dialog
                                    title="Hapus role {{ $role->name }}?"
                                    message="{{ $role->users_count }} pengguna yang memakai role ini akan kehilangan seluruh hak aksesnya sampai diberi role baru."
                                    confirm-text="Ya, Hapus"
                                    variant="danger"
                                    target="delete('{{ $role->uuid }}')"
                                >
                                    <x-slot:trigger>
                                        <button type="button" title="Hapus" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-red-50 hover:text-red-600 dark:text-zinc-400 dark:hover:bg-red-500/10 dark:hover:text-red-400">
                                            <x-phosphor-trash class="h-4 w-4" />
                                        </button>
                                    </x-slot:trigger>
                                    <form wire:submit="delete('{{ $role->uuid }}')" x-ref="form"></form>
                                </x-confirm-dialog>
                            @endunless
                        @endcan
                    </div>
                </div>
            @endforeach
        </div>

        <x-slot:footer>
            {{ $roles->links() }}
        </x-slot:footer>
    </x-list-card>

    @canany(['roles.create', 'roles.update'])
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
                class="relative flex max-h-[90vh] w-full max-w-2xl flex-col rounded-2xl border border-zinc-200 bg-white shadow-xl shadow-zinc-900/10 dark:border-zinc-800 dark:bg-zinc-900"
            >
                <div class="border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $editing ? 'Ubah Role' : 'Tambah Role' }}</h2>
                </div>

                <form wire:submit="save" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-5 py-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="flex flex-col gap-1.5">
                                <label for="roleName" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Nama Role</label>
                                <input
                                    id="roleName" type="text" wire:model="name" placeholder="Contoh: Operator Situs"
                                    @disabled($editing?->is_locked)
                                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 disabled:bg-zinc-50 disabled:text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:disabled:bg-zinc-800/50"
                                >
                                @error('name') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label for="roleSlug" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Slug</label>
                                <input
                                    id="roleSlug" type="text" wire:model="slug" placeholder="operator-situs"
                                    @disabled($editing?->is_locked)
                                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 font-mono text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 disabled:bg-zinc-50 disabled:text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:disabled:bg-zinc-800/50"
                                >
                                @error('slug') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label for="roleDescription" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Keterangan <span class="font-normal text-zinc-400">(opsional)</span></label>
                            <input
                                id="roleDescription" type="text" wire:model="description" placeholder="Untuk siapa role ini dipakai"
                                class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            >
                            @error('description') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        @if ($editing?->is_superadmin)
                            <div class="flex items-start gap-2 rounded-lg bg-accent-50/70 px-3.5 py-3 text-xs text-accent-700 dark:bg-accent-500/5 dark:text-accent-400">
                                <x-phosphor-info class="mt-0.5 h-4 w-4 shrink-0" />
                                <span>Role ini selalu punya akses penuh, termasuk ke halaman yang ditambahkan di kemudian hari. Centang di bawah hanya untuk informasi dan tidak berpengaruh.</span>
                            </div>
                        @endif

                        @php $allPermissions = collect($pages)->flatMap(fn ($page, $key) => collect($page['abilities'])->map(fn ($a) => "{$key}.{$a}"))->all(); @endphp
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                Hak Akses
                                <span class="font-normal text-zinc-400">&middot; {{ count($permissions) }} dari {{ count($allPermissions) }} dipilih</span>
                            </p>
                            <button
                                type="button"
                                wire:click="toggleAll"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-2.5 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            >
                                @if (count($permissions) === count($allPermissions))
                                    <x-phosphor-square class="h-3.5 w-3.5" />
                                    Hapus Semua Centang
                                @else
                                    <x-phosphor-check-square class="h-3.5 w-3.5" />
                                    Pilih Semua
                                @endif
                            </button>
                        </div>

                        {{-- Permission matrix: one row per page, one checkbox per ability.
                        Clicking a page name toggles that whole row at once. --}}
                        <div class="overflow-y rounded-xl border border-zinc-200 dark:border-zinc-800">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                                    <tr>
                                        <th class="px-4 py-2.5 font-medium">Halaman</th>
                                        @foreach ($abilities as $ability => $abilityLabel)
                                            <th class="w-16 px-2 py-2.5 text-center font-medium">{{ $abilityLabel }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach ($pages as $key => $page)
                                        <tr class="odd:bg-white even:bg-zinc-50/60 dark:odd:bg-zinc-900 dark:even:bg-zinc-800/30">
                                            <td class="px-4 py-2.5">
                                                <button type="button" wire:click="togglePage('{{ $key }}')" class="flex items-center gap-2 text-left transition hover:text-accent-600 dark:hover:text-accent-400">
                                                    <x-dynamic-component :component="'phosphor-' . $page['icon']" class="h-4 w-4 shrink-0 text-zinc-400" />
                                                    <span>
                                                        <span class="block text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $page['label'] }}</span>
                                                        <span class="block text-xs text-zinc-400 dark:text-zinc-500">{{ $page['description'] }}</span>
                                                    </span>
                                                </button>
                                            </td>
                                            @foreach ($abilities as $ability => $abilityLabel)
                                                <td class="px-2 py-2.5 text-center">
                                                    @if (in_array($ability, $page['abilities'], true))
                                                        <input
                                                            type="checkbox"
                                                            wire:model="permissions"
                                                            value="{{ $key }}.{{ $ability }}"
                                                            aria-label="{{ $page['label'] }} - {{ $abilityLabel }}"
                                                            class="h-4 w-4 rounded border-zinc-300 text-accent-600 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800"
                                                        >
                                                    @else
                                                        <span class="text-zinc-300 dark:text-zinc-700" title="Aksi ini tidak berlaku di halaman {{ $page['label'] }}">&mdash;</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @error('permissions.*') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end gap-2 border-t border-zinc-100 px-5 py-4 dark:border-zinc-800">
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
