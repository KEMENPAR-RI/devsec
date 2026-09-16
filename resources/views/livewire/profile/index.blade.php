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
                    <x-loading-label target="updateProfile" loading="Menyimpan...">Simpan Perubahan</x-loading-label>
                </button>
            </div>
        </form>
    </div>

    {{-- Password --}}
    <div class="enter rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Ubah Kata Sandi</h2>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Minimal 12 karakter, mengandung huruf besar, huruf kecil, dan angka. Kata sandi saat ini diperlukan sebagai verifikasi.</p>

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
                    <x-loading-label target="updatePassword" loading="Menyimpan...">Ubah Kata Sandi</x-loading-label>
                </button>
            </div>
        </form>
    </div>

    {{-- Live sessions --}}
    @if ($sessionsSupported)
        <div class="enter rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Perangkat yang Sedang Masuk</h2>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Sesi yang masih aktif dengan akun Anda. Bila ada yang tidak Anda kenali, keluarkan semuanya lalu ganti kata sandi.</p>

            <div class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($activeSessions as $session)
                    <div class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-zinc-900 dark:text-white">
                                {{ $session->device }}
                                @if ($session->is_current)
                                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">Perangkat ini</span>
                                @endif
                            </p>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $session->ip_address ?? 'IP tidak tercatat' }}
                                &middot; aktif {{ \App\Support\Dates::relative(\Illuminate\Support\Carbon::createFromTimestamp($session->last_activity)) }}
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="py-2 text-sm text-zinc-500 dark:text-zinc-400">Belum ada sesi yang tercatat.</p>
                @endforelse
            </div>

            <form wire:submit="logoutOtherDevices" class="mt-4 border-t border-zinc-100 pt-4 dark:border-zinc-800" novalidate>
                <label for="sessionPassword" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Keluarkan semua perangkat lain</label>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Sesi di perangkat ini tetap aktif. Masukkan kata sandi Anda untuk melanjutkan.</p>

                <div class="mt-2 flex flex-wrap gap-2">
                    <input
                        wire:model="sessionPassword"
                        id="sessionPassword"
                        type="password"
                        autocomplete="current-password"
                        placeholder="Kata sandi Anda"
                        class="w-56 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white @error('sessionPassword') border-red-400 @enderror"
                    >
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                        <x-loading-label target="logoutOtherDevices" loading="Memproses...">
                            <x-phosphor-sign-out class="h-4 w-4" />
                            <span>Keluarkan</span>
                        </x-loading-label>
                    </button>
                </div>
                @error('sessionPassword')
                    <p class="mt-2 flex items-center gap-1.5 text-sm text-red-600 dark:text-red-400">
                        <x-phosphor-warning-circle class="h-4 w-4 shrink-0" />
                        {{ $message }}
                    </p>
                @enderror
            </form>
        </div>
    @endif

    {{-- Second factor --}}
    <div class="enter rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Verifikasi Dua Faktor</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Kata sandi saja tidak cukup untuk masuk - dibutuhkan juga kode dari aplikasi autentikator di perangkat Anda.</p>
            </div>

            @if ($twoFactorEnabled)
                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                    <x-phosphor-shield-check class="h-3.5 w-3.5" />
                    Aktif
                </span>
            @else
                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                    <x-phosphor-warning class="h-3.5 w-3.5" />
                    Belum aktif
                </span>
            @endif
        </div>

        {{-- Shown once, right after enrolling or regenerating. --}}
        @if ($freshRecoveryCodes)
            <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
                <p class="flex items-start gap-2 text-sm font-medium text-amber-800 dark:text-amber-300">
                    <x-phosphor-warning class="mt-0.5 h-4 w-4 shrink-0" />
                    Simpan kode pemulihan ini sekarang. Kode hanya ditampilkan satu kali.
                </p>
                <p class="mt-1 pl-6 text-xs text-amber-700 dark:text-amber-400">Setiap kode bisa dipakai sekali untuk masuk bila perangkat autentikator Anda hilang.</p>

                <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach ($freshRecoveryCodes as $recovery)
                        <code class="rounded bg-white px-2 py-1.5 text-center font-mono text-xs text-zinc-800 dark:bg-zinc-900 dark:text-zinc-200">{{ $recovery }}</code>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($pendingSecret)
            {{-- Enrolling: scan it, then prove a working code. --}}
            <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-start">
                <div class="shrink-0 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700">
                    {!! $qrCodeSvg !!}
                </div>

                <div class="flex-1 space-y-3">
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">Pindai kode QR ini dengan Google Authenticator, Authy, atau aplikasi sejenis. Bila kamera tidak bisa dipakai, masukkan kunci berikut secara manual:</p>

                    <code class="block rounded-lg bg-zinc-100 px-3 py-2 font-mono text-xs tracking-wider text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">{{ $formattedSecret }}</code>

                    <form wire:submit="confirmTwoFactor" class="space-y-2" novalidate>
                        <label for="twoFactorCode" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Masukkan kode dari aplikasi</label>
                        <div class="flex flex-wrap gap-2">
                            <input
                                wire:model="twoFactorCode"
                                id="twoFactorCode"
                                type="text"
                                inputmode="numeric"
                                maxlength="6"
                                autocomplete="one-time-code"
                                placeholder="000000"
                                class="w-32 rounded-lg border border-zinc-200 bg-white px-3 py-2 font-mono text-sm tracking-[0.3em] text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white @error('twoFactorCode') border-red-400 @enderror"
                            >
                            <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-accent-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-accent-700">
                                <x-loading-label target="confirmTwoFactor" loading="Memeriksa...">
                                    <x-phosphor-check class="h-4 w-4" />
                                    <span>Aktifkan</span>
                                </x-loading-label>
                            </button>
                            <button type="button" wire:click="cancelTwoFactor" class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                                Batal
                            </button>
                        </div>
                        @error('twoFactorCode')
                            <p class="flex items-center gap-1.5 text-sm text-red-600 dark:text-red-400">
                                <x-phosphor-warning-circle class="h-4 w-4 shrink-0" />
                                {{ $message }}
                            </p>
                        @enderror
                    </form>
                </div>
            </div>
        @elseif ($twoFactorEnabled)
            <p class="mt-4 text-sm text-zinc-600 dark:text-zinc-300">Sisa kode pemulihan: <span class="font-semibold">{{ $remainingRecoveryCodes }}</span> dari {{ \App\Services\Security\TwoFactor::RECOVERY_CODE_COUNT }}.</p>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                <button type="button" wire:click="regenerateRecoveryCodes" class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    <x-phosphor-arrows-clockwise class="h-4 w-4" />
                    Buat ulang kode pemulihan
                </button>
            </div>

            <form wire:submit="disableTwoFactor" class="mt-4 border-t border-zinc-100 pt-4 dark:border-zinc-800" novalidate>
                <label for="disablePassword" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Nonaktifkan verifikasi dua faktor</label>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Masukkan kata sandi Anda untuk memastikan ini benar-benar Anda.</p>

                <div class="mt-2 flex flex-wrap gap-2">
                    <input
                        wire:model="disablePassword"
                        id="disablePassword"
                        type="password"
                        autocomplete="current-password"
                        placeholder="Kata sandi Anda"
                        class="w-56 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white @error('disablePassword') border-red-400 @enderror"
                    >
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3.5 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-red-500/30 dark:text-red-400 dark:hover:bg-red-500/10">
                        <x-loading-label target="disableTwoFactor" loading="Memproses...">
                            <x-phosphor-shield-slash class="h-4 w-4" />
                            <span>Nonaktifkan</span>
                        </x-loading-label>
                    </button>
                </div>
                @error('disablePassword')
                    <p class="mt-2 flex items-center gap-1.5 text-sm text-red-600 dark:text-red-400">
                        <x-phosphor-warning-circle class="h-4 w-4 shrink-0" />
                        {{ $message }}
                    </p>
                @enderror
            </form>
        @else
            <button type="button" wire:click="startTwoFactor" class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-accent-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-accent-700">
                <x-loading-label target="startTwoFactor" loading="Menyiapkan...">
                    <x-phosphor-shield-check class="h-4 w-4" />
                    <span>Aktifkan verifikasi dua faktor</span>
                </x-loading-label>
            </button>
        @endif
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
