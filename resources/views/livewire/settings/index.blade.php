<div class="mx-auto flex max-w-3xl flex-col gap-6">
    {{-- Header --}}
    <div class="enter">
        <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Pengaturan</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Konfigurasi AI untuk deteksi judol - tersimpan di database, berlaku langsung tanpa deploy ulang.</p>
    </div>

    @if (session('status'))
        <div class="enter flex items-center gap-2 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
            <x-phosphor-check-circle class="h-4 w-4 shrink-0" />
            {{ session('status') }}
        </div>
    @endif

    @php
        $tabs = [
            'ai' => ['label' => 'Klasifikasi AI', 'icon' => 'robot', 'errors' => ['apiKey', 'workspaceId', 'aiModel', 'confidenceThreshold', 'maxImages']],
            'email' => ['label' => 'Konfigurasi Email', 'icon' => 'envelope-simple', 'errors' => ['mailHost', 'mailPort', 'mailUsername', 'mailPassword', 'mailEncryption', 'mailFromAddress', 'mailFromName', 'mailTemplateSubject', 'mailTemplateBody']],
            'app' => ['label' => 'Konfigurasi Aplikasi', 'icon' => 'sliders-horizontal', 'errors' => ['appName', 'appTimezone', 'logo', 'favicon', 'defaultCheckIntervalMinutes', 'defaultJudolScanIntervalMinutes', 'defaultDownThresholdFailures', 'defaultResponseTimeThresholdMs', 'recaptchaSiteKey', 'recaptchaSecretKey', 'recaptchaMinScore', 'backupFrequency', 'backupTime', 'backupKeep']],
        ];
    @endphp

    {{-- Alpine, not Livewire: switching tabs is a pure show/hide, so nothing is
    unmounted and a half-filled form on another tab keeps its values. The choice is
    remembered so saving (which re-renders) doesn't throw you back to the first tab. --}}
    <div
        x-data="{
            tab: localStorage.getItem('settingsTab') ?? 'ai',
            select(name) { this.tab = name; localStorage.setItem('settingsTab', name); },
        }"
        x-init="if (!{{ \Illuminate\Support\Js::from(array_keys($tabs)) }}.includes(tab)) select('ai')"
        class="enter flex flex-col gap-6"
    >
        {{-- Tab bar --}}
        <div class="flex gap-1 overflow-x-auto rounded-xl border border-zinc-200 bg-white p-1 dark:border-zinc-800 dark:bg-zinc-900" role="tablist">
            @foreach ($tabs as $key => $meta)
                @php $tabHasErrors = collect($meta['errors'])->contains(fn ($field) => $errors->has($field)); @endphp
                <button
                    type="button"
                    role="tab"
                    @click="select('{{ $key }}')"
                    :aria-selected="tab === '{{ $key }}'"
                    :class="tab === '{{ $key }}'
                        ? 'bg-accent-50 text-accent-700 dark:bg-accent-500/10 dark:text-accent-400'
                        : 'text-zinc-500 hover:bg-zinc-50 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                    class="inline-flex flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium transition"
                >
                    <x-dynamic-component :component="'phosphor-' . $meta['icon']" class="h-4 w-4 shrink-0" />
                    {{ $meta['label'] }}
                    @if ($tabHasErrors)
                        {{-- A hidden tab's validation errors would otherwise be invisible. --}}
                        <span title="Ada isian yang perlu diperbaiki di tab ini" class="h-1.5 w-1.5 shrink-0 rounded-full bg-red-500"></span>
                    @endif
                </button>
            @endforeach
        </div>

    {{-- Plain div, not <form> - the "Hapus API key" confirm dialog below needs its
    own <form> in its slot, and forms can't nest. "Simpan Pengaturan" uses wire:click
    instead of wire:submit accordingly. --}}
    <div x-show="tab === 'ai'" x-cloak class="flex flex-col gap-6 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
            <x-phosphor-robot class="h-4.5 w-4.5 text-accent-500" />
            Klasifikasi AI (Judol)
        </div>

        {{-- API Key --}}
        <div class="flex flex-col gap-2">
            <label for="apiKey" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Anthropic API Key</label>
            <div class="relative" x-data="{ show: false }">
                <x-phosphor-key class="pointer-events-none absolute left-3 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-zinc-400" />
                <input
                    wire:model="apiKey"
                    id="apiKey"
                    :type="show ? 'text' : 'password'"
                    autocomplete="off"
                    placeholder="{{ $apiKeyIsSet ? 'Tersimpan - isi untuk mengganti' : 'sk-ant-...' }}"
                    class="w-full rounded-lg border border-zinc-200 bg-white py-2.5 pl-10 pr-10 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white dark:placeholder:text-zinc-500 @error('apiKey') border-red-400 @enderror"
                >
                <button
                    type="button"
                    @click="show = !show"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 transition hover:text-zinc-600 dark:hover:text-zinc-300"
                    :aria-label="show ? 'Sembunyikan' : 'Tampilkan'"
                >
                    <x-phosphor-eye x-show="!show" class="h-4.5 w-4.5" />
                    <x-phosphor-eye-slash x-show="show" class="h-4.5 w-4.5" style="display: none;" />
                </button>
            </div>
            @error('apiKey')
                <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror

            <div class="flex flex-wrap items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400">
                @if ($apiKeyIsSet)
                    <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                        <x-phosphor-check-circle class="h-3.5 w-3.5" /> API key tersimpan di database
                    </span>
                    <x-confirm-dialog
                        title="Hapus API Key Tersimpan?"
                        message="Sistem akan memakai ANTHROPIC_API_KEY dari .env (jika ada)."
                        confirm-text="Ya, Hapus"
                        variant="danger"
                        target="clearApiKey"
                    >
                        <x-slot:trigger>
                            <button type="button" class="text-red-500 hover:underline">Hapus</button>
                        </x-slot:trigger>
                        <form wire:submit="clearApiKey" x-ref="form"></form>
                    </x-confirm-dialog>
                @else
                    <span class="inline-flex items-center gap-1">
                        <x-phosphor-warning-circle class="h-3.5 w-3.5" /> Belum diatur - pakai ANTHROPIC_API_KEY dari .env bila tersedia
                    </span>
                @endif
                <button type="button" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection" class="ml-auto inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-zinc-200 px-2.5 py-1 font-medium text-zinc-600 transition hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    <x-phosphor-plug-charging class="h-3.5 w-3.5" wire:loading.remove wire:target="testConnection" />
                    <x-phosphor-spinner class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="testConnection" />
                    Tes Koneksi
                </button>
            </div>

            @if ($testResult)
                <div @class([
                    'flex items-center gap-2 rounded-lg px-3 py-2 text-xs',
                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $testResult['success'],
                    'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' => ! $testResult['success'],
                ])>
                    <x-dynamic-component :component="$testResult['success'] ? 'phosphor-check-circle' : 'phosphor-x-circle'" class="h-3.5 w-3.5 shrink-0" />
                    {{ $testResult['message'] }}
                </div>

                @if (! $testResult['success'] && str_contains($testResult['message'], 'anthropic-workspace-id'))
                    <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                        API key ini "identity-linked" (personal/OAuth, menaungi banyak workspace) - isi field <strong>Workspace ID</strong> di bawah. Atau lebih simpel: generate API key baru yang scoped ke satu workspace di
                        <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener" class="underline">console.anthropic.com &rarr; Settings &rarr; API Keys</a> - key jenis itu tidak butuh Workspace ID sama sekali.
                    </p>
                @endif
            @endif
        </div>

        {{-- Workspace ID (opsional) --}}
        <div class="flex flex-col gap-2">
            <label for="workspaceId" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                Workspace ID <span class="font-normal text-zinc-400">(opsional)</span>
            </label>
            <input
                wire:model="workspaceId"
                id="workspaceId"
                type="text"
                autocomplete="off"
                placeholder="mis. wrkspc_01AbCdEfGhIjKlMnOp"
                class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white dark:placeholder:text-zinc-500 @error('workspaceId') border-red-400 @enderror"
            >
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                Hanya perlu diisi kalau API key-nya "identity-linked" dan Tes Koneksi gagal minta anthropic-workspace-id. Cari di <a href="https://console.anthropic.com/settings/workspaces" target="_blank" rel="noopener" class="text-accent-600 hover:underline dark:text-accent-400">console.anthropic.com &rarr; Settings &rarr; Workspaces</a>, buka workspace-nya, ID-nya ada di URL. Kosongkan kalau pakai API key biasa.
            </p>
            @error('workspaceId')
                <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div class="border-t border-zinc-100 dark:border-zinc-800"></div>

        {{-- Toggle aktif --}}
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-zinc-900 dark:text-white">Aktifkan Klasifikasi AI</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Konfirmasi/bantah temuan keyword filter lewat Claude (teks & gambar) sebelum insiden dibuat.</p>
            </div>
            <button
                type="button"
                role="switch"
                :aria-checked="$wire.aiEnabled"
                wire:click="$toggle('aiEnabled')"
                @class([
                    'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition',
                    'bg-accent-600' => $aiEnabled,
                    'bg-zinc-200 dark:bg-zinc-700' => ! $aiEnabled,
                ])
            >
                <span @class([
                    'inline-block h-4.5 w-4.5 transform rounded-full bg-white shadow transition',
                    'translate-x-6' => $aiEnabled,
                    'translate-x-1' => ! $aiEnabled,
                ])></span>
            </button>
        </div>

        {{-- Model --}}
        <div class="flex flex-col gap-2">
            <label for="aiModel" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Model</label>
            <select
                wire:model="aiModel"
                id="aiModel"
                class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
            >
                <option value="claude-haiku-4-5">Claude Haiku 4.5 - murah & cepat (direkomendasikan)</option>
                <option value="claude-sonnet-5">Claude Sonnet 5 - lebih akurat, lebih mahal</option>
                <option value="claude-opus-5">Claude Opus 5 - paling akurat, paling mahal</option>
            </select>
        </div>

        {{-- Confidence & max images --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="flex flex-col gap-2">
                <label for="confidenceThreshold" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Ambang Batas Confidence</label>
                <input
                    wire:model="confidenceThreshold"
                    id="confidenceThreshold"
                    type="number"
                    step="0.05"
                    min="0"
                    max="1"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('confidenceThreshold') border-red-400 @enderror"
                >
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Di bawah ini, bantahan AI dianggap belum yakin - insiden tetap dibuat dari keyword match.</p>
                @error('confidenceThreshold')
                    <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-2">
                <label for="maxImages" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Maks. Gambar per Halaman</label>
                <input
                    wire:model="maxImages"
                    id="maxImages"
                    type="number"
                    min="0"
                    max="10"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('maxImages') border-red-400 @enderror"
                >
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Gambar promosi (banner) yang dikirim ke Claude untuk dibaca. 0 menonaktifkan pembacaan gambar.</p>
                @error('maxImages')
                    <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="flex justify-end border-t border-zinc-100 pt-5 dark:border-zinc-800">
            <button
                type="button"
                wire:click="save"
                wire:loading.attr="disabled"
                wire:target="save"
                class="flex items-center gap-2 rounded-lg bg-accent-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-accent-700 disabled:cursor-not-allowed disabled:opacity-70"
            >
                <x-phosphor-floppy-disk class="h-4 w-4" wire:loading.remove wire:target="save" />
                <x-phosphor-spinner class="h-4 w-4 animate-spin" wire:loading wire:target="save" />
                Simpan Pengaturan
            </button>
        </div>

        <p class="text-xs text-zinc-400 dark:text-zinc-500">
            Kelola kata kunci judol & ambang batas keyword filter dari halaman <a href="{{ route('deteksi-judol') }}" wire:navigate class="font-medium text-accent-600 hover:underline dark:text-accent-400">Deteksi Judol</a>.
        </p>
    </div>

    {{-- Email notifications --}}
    <div x-show="tab === 'email'" x-cloak class="flex flex-col gap-6 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                <x-phosphor-envelope-simple class="h-4.5 w-4.5 text-accent-500" />
                Konfigurasi Email
            </div>
            <button
                type="button"
                role="switch"
                :aria-checked="$wire.mailEnabled"
                wire:click="$toggle('mailEnabled')"
                @class([
                    'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition',
                    'bg-accent-600' => $mailEnabled,
                    'bg-zinc-200 dark:bg-zinc-700' => ! $mailEnabled,
                ])
            >
                <span @class([
                    'inline-block h-4.5 w-4.5 transform rounded-full bg-white shadow transition',
                    'translate-x-6' => $mailEnabled,
                    'translate-x-1' => ! $mailEnabled,
                ])></span>
            </button>
        </div>
        <p class="-mt-4 text-xs text-zinc-500 dark:text-zinc-400">Kirim salinan setiap notifikasi (down time, serangan, SSL, judol) lewat email ke semua akun terdaftar, selain yang sudah tampil di lonceng notifikasi.</p>

        {{-- SMTP --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="flex flex-col gap-2 sm:col-span-2">
                <label for="mailHost" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">SMTP Host</label>
                <input
                    wire:model="mailHost"
                    id="mailHost"
                    type="text"
                    autocomplete="off"
                    placeholder="mis. smtp.gmail.com"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white dark:placeholder:text-zinc-500 @error('mailHost') border-red-400 @enderror"
                >
                @error('mailHost') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="flex flex-col gap-2">
                <label for="mailPort" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Port</label>
                <input
                    wire:model="mailPort"
                    id="mailPort"
                    type="number"
                    min="1"
                    max="65535"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('mailPort') border-red-400 @enderror"
                >
                @error('mailPort') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="flex flex-col gap-2">
                <label for="mailUsername" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Username</label>
                <input
                    wire:model="mailUsername"
                    id="mailUsername"
                    type="text"
                    autocomplete="off"
                    placeholder="mis. notifikasi@instansi.go.id"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white dark:placeholder:text-zinc-500"
                >
            </div>
            <div class="flex flex-col gap-2">
                <label for="mailPassword" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Password</label>
                <div class="relative" x-data="{ show: false }">
                    <input
                        wire:model="mailPassword"
                        id="mailPassword"
                        :type="show ? 'text' : 'password'"
                        autocomplete="off"
                        placeholder="{{ $mailPasswordIsSet ? 'Tersimpan - isi untuk mengganti' : '••••••••' }}"
                        class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 pr-10 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white dark:placeholder:text-zinc-500"
                    >
                    <button
                        type="button"
                        @click="show = !show"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 transition hover:text-zinc-600 dark:hover:text-zinc-300"
                        :aria-label="show ? 'Sembunyikan' : 'Tampilkan'"
                    >
                        <x-phosphor-eye x-show="!show" class="h-4.5 w-4.5" />
                        <x-phosphor-eye-slash x-show="show" class="h-4.5 w-4.5" style="display: none;" />
                    </button>
                </div>
                @if ($mailPasswordIsSet)
                    <x-confirm-dialog
                        title="Hapus Password SMTP Tersimpan?"
                        message="Sistem akan memakai MAIL_PASSWORD dari .env (jika ada)."
                        confirm-text="Ya, Hapus"
                        variant="danger"
                        target="clearMailPassword"
                    >
                        <x-slot:trigger>
                            <button type="button" class="self-start text-xs text-red-500 hover:underline">Hapus password tersimpan</button>
                        </x-slot:trigger>
                        <form wire:submit="clearMailPassword" x-ref="form"></form>
                    </x-confirm-dialog>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="flex flex-col gap-2">
                <label for="mailEncryption" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Enkripsi</label>
                <select
                    wire:model="mailEncryption"
                    id="mailEncryption"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                >
                    <option value="tls">TLS</option>
                    <option value="ssl">SSL</option>
                    <option value="">Tidak ada</option>
                </select>
            </div>
            <div class="flex flex-col gap-2">
                <label for="mailFromAddress" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Alamat Pengirim</label>
                <input
                    wire:model="mailFromAddress"
                    id="mailFromAddress"
                    type="email"
                    autocomplete="off"
                    placeholder="noreply@instansi.go.id"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white dark:placeholder:text-zinc-500 @error('mailFromAddress') border-red-400 @enderror"
                >
                @error('mailFromAddress') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="flex flex-col gap-2">
                <label for="mailFromName" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Nama Pengirim</label>
                <input
                    wire:model="mailFromName"
                    id="mailFromName"
                    type="text"
                    autocomplete="off"
                    placeholder="{{ config('app.name') }} Monitoring"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white dark:placeholder:text-zinc-500"
                >
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <button type="button" wire:click="testEmail" wire:loading.attr="disabled" wire:target="testEmail" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-zinc-200 px-2.5 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                <x-phosphor-paper-plane-tilt class="h-3.5 w-3.5" wire:loading.remove wire:target="testEmail" />
                <x-phosphor-spinner class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="testEmail" />
                Kirim Email Tes
            </button>
        </div>

        @if ($mailTestResult)
            <div @class([
                'flex items-center gap-2 rounded-lg px-3 py-2 text-xs',
                'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $mailTestResult['success'],
                'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' => ! $mailTestResult['success'],
            ])>
                <x-dynamic-component :component="$mailTestResult['success'] ? 'phosphor-check-circle' : 'phosphor-x-circle'" class="h-3.5 w-3.5 shrink-0" />
                {{ $mailTestResult['message'] }}
            </div>
        @endif

        <div class="border-t border-zinc-100 dark:border-zinc-800"></div>

        {{-- Template: collapsed by default so the SMTP fields above stay the focus,
        but still here rather than on a tab of its own - it is email configuration. --}}
        <div x-data="{ openTemplate: {{ $errors->hasAny(['mailTemplateSubject', 'mailTemplateBody']) ? 'true' : 'false' }} }" class="flex flex-col gap-6">
            <div class="flex items-center justify-between gap-4">
                <button type="button" @click="openTemplate = !openTemplate" class="flex items-center gap-2 text-sm font-semibold text-zinc-900 transition hover:text-accent-600 dark:text-white dark:hover:text-accent-400">
                    <x-phosphor-caret-right class="h-3.5 w-3.5 text-zinc-400 transition-transform duration-200" x-bind:class="openTemplate ? 'rotate-90' : ''" />
                    Template Email
                </button>
                <button type="button" wire:click="resetMailTemplate" x-show="openTemplate" x-cloak class="text-xs font-medium text-zinc-500 transition hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                    Reset ke Default
                </button>
            </div>

            <div x-show="openTemplate" x-cloak class="flex flex-col gap-6">

        <div class="flex flex-col gap-2">
            <label for="mailTemplateSubject" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Subjek</label>
            <input
                wire:model="mailTemplateSubject"
                id="mailTemplateSubject"
                type="text"
                class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('mailTemplateSubject') border-red-400 @enderror"
            >
            @error('mailTemplateSubject') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div class="flex flex-col gap-2">
            <label for="mailTemplateBody" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Isi (HTML)</label>
            <textarea
                wire:model="mailTemplateBody"
                id="mailTemplateBody"
                rows="12"
                spellcheck="false"
                class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 font-mono text-xs text-zinc-900 transition focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('mailTemplateBody') border-red-400 @enderror"
            >{{ $mailTemplateBody }}</textarea>
            @error('mailTemplateBody') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        @php $placeholders = app(\App\Services\Notifications\MailSettings::class)->placeholders(); @endphp
        <div class="flex flex-col gap-1.5 rounded-lg bg-zinc-50 p-3 text-xs dark:bg-zinc-800/60">
            <p class="font-medium text-zinc-600 dark:text-zinc-300">Placeholder yang tersedia (dipakai di Subjek & Isi):</p>
            <dl class="grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2">
                @foreach ($placeholders as $token => $description)
                    <div class="flex items-baseline gap-1.5">
                        <dt class="shrink-0 font-mono text-accent-600 dark:text-accent-400">{{ $token }}</dt>
                        <dd class="truncate text-zinc-500 dark:text-zinc-400">{{ $description }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
            </div>
        </div>

        <div class="flex justify-end border-t border-zinc-100 pt-5 dark:border-zinc-800">
            <button
                type="button"
                wire:click="save"
                wire:loading.attr="disabled"
                wire:target="save"
                class="flex items-center gap-2 rounded-lg bg-accent-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-accent-700 disabled:cursor-not-allowed disabled:opacity-70"
            >
                <x-phosphor-floppy-disk class="h-4 w-4" wire:loading.remove wire:target="save" />
                <x-phosphor-spinner class="h-4 w-4 animate-spin" wire:loading wire:target="save" />
                Simpan Pengaturan
            </button>
        </div>
    </div>

    {{-- Application configuration --}}
    <div x-show="tab === 'app'" x-cloak class="flex flex-col gap-6 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
            <x-phosphor-sliders-horizontal class="h-4.5 w-4.5 text-accent-500" />
            Konfigurasi Aplikasi
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="flex flex-col gap-2">
                <label for="appName" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Nama Aplikasi</label>
                <input
                    wire:model="appName"
                    id="appName"
                    type="text"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('appName') border-red-400 @enderror"
                >
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Tampil di sidebar, judul tab browser, dan subjek email notifikasi.</p>
                @error('appName') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-col gap-2">
                <label for="appTimezone" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Zona Waktu</label>
                <select
                    wire:model="appTimezone"
                    id="appTimezone"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('appTimezone') border-red-400 @enderror"
                >
                    @foreach ($timezoneOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Dipakai untuk menampilkan semua waktu (insiden, deteksi, notifikasi). Sekarang: {{ now()->translatedFormat('d M Y, H:i') }}.</p>
                @error('appTimezone') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex flex-col gap-2">
            <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Logo Aplikasi</label>
            <div class="flex flex-wrap items-center gap-4">
                <span class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-950">
                    <x-brand-mark size="lg" :image_url="$logoUrl" class="h-full w-full object-contain" />
                </span>

                <div class="flex min-w-0 flex-1 flex-col gap-2">
                    <input
                        type="file"
                        wire:model="logo"
                        accept="image/png,image/jpeg,image/webp,image/svg+xml"
                        class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-accent-600 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-accent-700 dark:text-zinc-300"
                    >
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        PNG, JPG, WEBP, atau SVG. Maksimal 1 MB. Tampil di sidebar dan halaman masuk; kalau kosong dipakai inisial nama aplikasi.
                    </p>
                    <p wire:loading wire:target="logo" class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                        <x-phosphor-spinner class="h-3.5 w-3.5 animate-spin" />
                        Mengunggah...
                    </p>
                    @error('logo') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                @if ($logoUrl)
                    <x-confirm-dialog
                        title="Hapus Logo Aplikasi?"
                        message="Sidebar dan halaman masuk kembali memakai inisial nama aplikasi."
                        confirm-text="Ya, Hapus"
                        variant="danger"
                        target="removeLogo"
                    >
                        <x-slot:trigger>
                            <button type="button" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-2 text-xs font-medium text-zinc-600 transition hover:bg-red-50 hover:text-red-600 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-red-500/10 dark:hover:text-red-400">
                                <x-phosphor-trash class="h-3.5 w-3.5" />
                                Hapus Logo
                            </button>
                        </x-slot:trigger>
                        <form wire:submit="removeLogo" x-ref="form"></form>
                    </x-confirm-dialog>
                @endif
            </div>
        </div>

        <div class="flex flex-col gap-2">
            <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Favicon</label>
            <div class="flex flex-wrap items-center gap-4">
                <span class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-950">
                    @if ($faviconUrl)
                        <img src="{{ $faviconUrl }}" alt="Favicon" class="h-8 w-8 object-contain">
                    @else
                        <x-phosphor-browser class="h-6 w-6 text-zinc-300 dark:text-zinc-600" />
                    @endif
                </span>

                <div class="flex min-w-0 flex-1 flex-col gap-2">
                    <input
                        type="file"
                        wire:model="favicon"
                        accept=".ico,image/png,image/svg+xml,image/webp,image/x-icon"
                        class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-accent-600 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-accent-700 dark:text-zinc-300"
                    >
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        Ikon di tab browser. ICO, PNG, SVG, atau WEBP, maksimal 512 KB. Ukuran ideal 32&times;32 atau 64&times;64 piksel.
                    </p>
                    <p wire:loading wire:target="favicon" class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                        <x-phosphor-spinner class="h-3.5 w-3.5 animate-spin" />
                        Mengunggah...
                    </p>
                    @error('favicon') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                @if ($faviconUrl)
                    <x-confirm-dialog
                        title="Hapus Favicon?"
                        message="Tab browser kembali memakai ikon bawaan."
                        confirm-text="Ya, Hapus"
                        variant="danger"
                        target="removeFavicon"
                    >
                        <x-slot:trigger>
                            <button type="button" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-2 text-xs font-medium text-zinc-600 transition hover:bg-red-50 hover:text-red-600 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-red-500/10 dark:hover:text-red-400">
                                <x-phosphor-trash class="h-3.5 w-3.5" />
                                Hapus Favicon
                            </button>
                        </x-slot:trigger>
                        <form wire:submit="removeFavicon" x-ref="form"></form>
                    </x-confirm-dialog>
                @endif
            </div>
        </div>

        <div class="border-t border-zinc-100 dark:border-zinc-800"></div>

        <div>
            <div class="text-sm font-semibold text-zinc-900 dark:text-white">Bawaan Situs Baru</div>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Nilai awal yang terisi saat menambah situs (satuan maupun impor massal). Situs yang sudah ada tidak ikut berubah - atur per situs lewat tombol "Ubah" di halaman Situs.
            </p>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="flex flex-col gap-2">
                <label for="defaultCheckIntervalMinutes" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Interval Cek Uptime (menit)</label>
                <input
                    wire:model="defaultCheckIntervalMinutes" id="defaultCheckIntervalMinutes" type="number" min="1" max="1440"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('defaultCheckIntervalMinutes') border-red-400 @enderror"
                >
                @error('defaultCheckIntervalMinutes') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-col gap-2">
                <label for="defaultJudolScanIntervalMinutes" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Interval Scan Judol (menit)</label>
                <input
                    wire:model="defaultJudolScanIntervalMinutes" id="defaultJudolScanIntervalMinutes" type="number" min="5" max="10080"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('defaultJudolScanIntervalMinutes') border-red-400 @enderror"
                >
                @error('defaultJudolScanIntervalMinutes') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-col gap-2">
                <label for="defaultDownThresholdFailures" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Ambang Batas Down (gagal berturut-turut)</label>
                <input
                    wire:model="defaultDownThresholdFailures" id="defaultDownThresholdFailures" type="number" min="1" max="20"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('defaultDownThresholdFailures') border-red-400 @enderror"
                >
                @error('defaultDownThresholdFailures') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-col gap-2">
                <label for="defaultResponseTimeThresholdMs" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Ambang Batas Response Time (ms)</label>
                <input
                    wire:model="defaultResponseTimeThresholdMs" id="defaultResponseTimeThresholdMs" type="number" min="200" max="60000" step="100"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('defaultResponseTimeThresholdMs') border-red-400 @enderror"
                >
                @error('defaultResponseTimeThresholdMs') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="border-t border-zinc-100 dark:border-zinc-800"></div>

        {{-- Google reCAPTCHA v3 --}}
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                <x-phosphor-shield-check class="h-4.5 w-4.5 text-accent-500" />
                Keamanan Login (reCAPTCHA v3)
            </div>
            <button
                type="button"
                role="switch"
                :aria-checked="$wire.recaptchaEnabled"
                wire:click="$toggle('recaptchaEnabled')"
                @class([
                    'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition',
                    'bg-accent-600' => $recaptchaEnabled,
                    'bg-zinc-200 dark:bg-zinc-700' => ! $recaptchaEnabled,
                ])
            >
                <span @class([
                    'inline-block h-4.5 w-4.5 transform rounded-full bg-white shadow transition',
                    'translate-x-6' => $recaptchaEnabled,
                    'translate-x-1' => ! $recaptchaEnabled,
                ])></span>
            </button>
        </div>
        <p class="-mt-4 text-xs text-zinc-500 dark:text-zinc-400">
            Menyaring bot di halaman Masuk dan Lupa Kata Sandi tanpa teka-teki apa pun - pengguna asli tidak merasakan apa-apa.
            Ambil pasangan kunci di <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener" class="font-medium text-accent-600 hover:underline dark:text-accent-400">google.com/recaptcha/admin</a>, pilih tipe <span class="font-medium">reCAPTCHA v3</span>, lalu daftarkan domain aplikasi ini.
        </p>

        {{-- Without this, an admin whose .env says false would flip the toggle and
             wonder why nothing happens. --}}
        @if ($recaptchaLockedByEnv)
            <div class="-mt-4 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                <x-phosphor-warning class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>
                    Dimatikan paksa dari server lewat <span class="font-mono font-medium">RECAPTCHA_ENABLED=false</span> di berkas <span class="font-mono font-medium">.env</span>.
                    Pengaturan di bawah tetap tersimpan, tetapi reCAPTCHA belum berjalan sampai nilai itu dikembalikan ke <span class="font-mono font-medium">true</span> lalu <span class="font-mono font-medium">php artisan config:clear</span> dijalankan.
                </span>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="flex flex-col gap-2">
                <label for="recaptchaSiteKey" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Site Key</label>
                <input
                    wire:model="recaptchaSiteKey"
                    id="recaptchaSiteKey"
                    type="text"
                    placeholder="6Lc..."
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('recaptchaSiteKey') border-red-400 @enderror"
                >
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Tidak rahasia - kunci ini memang tampil di halaman login.</p>
                @error('recaptchaSiteKey') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-col gap-2">
                <label for="recaptchaSecretKey" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    Secret Key
                    @if ($recaptchaSecretIsSet)
                        <span class="font-normal text-zinc-400">(tersimpan - kosongkan bila tidak diubah)</span>
                    @endif
                </label>
                <div class="flex items-start gap-2">
                    <input
                        wire:model="recaptchaSecretKey"
                        id="recaptchaSecretKey"
                        type="password"
                        autocomplete="new-password"
                        placeholder="{{ $recaptchaSecretIsSet ? '••••••••••••' : '6Lc...' }}"
                        class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 font-mono text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('recaptchaSecretKey') border-red-400 @enderror"
                    >
                    @if ($recaptchaSecretIsSet)
                        <x-confirm-dialog
                            title="Hapus Secret Key reCAPTCHA?"
                            message="Verifikasi reCAPTCHA akan berhenti berlaku sampai secret key baru diisi."
                            confirm-text="Ya, Hapus"
                            variant="danger"
                            target="clearRecaptchaSecret"
                        >
                            <x-slot:trigger>
                                <button type="button" title="Hapus secret key" class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-zinc-200 text-zinc-500 transition hover:bg-red-50 hover:text-red-600 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-red-500/10 dark:hover:text-red-400">
                                    <x-phosphor-trash class="h-4 w-4" />
                                </button>
                            </x-slot:trigger>
                            <form wire:submit="clearRecaptchaSecret" x-ref="form"></form>
                        </x-confirm-dialog>
                    @endif
                </div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Disimpan terenkripsi, tidak pernah dikirim kembali ke browser.</p>
                @error('recaptchaSecretKey') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex flex-col gap-2">
            <label for="recaptchaMinScore" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Ambang Skor Minimal</label>
            <input
                wire:model="recaptchaMinScore"
                id="recaptchaMinScore"
                type="number"
                step="0.1"
                min="0"
                max="1"
                class="w-32 rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('recaptchaMinScore') border-red-400 @enderror"
            >
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                Google memberi skor 0.0 (hampir pasti bot) sampai 1.0 (hampir pasti manusia). Bawaan 0.5. Naikkan bila masih kebobolan, turunkan bila pengguna asli ikut tertolak.
            </p>
            @error('recaptchaMinScore') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div class="border-t border-zinc-100 dark:border-zinc-800"></div>

        {{-- Database backup --}}
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                <x-phosphor-database class="h-4.5 w-4.5 text-accent-500" />
                Backup Database
            </div>
            <button
                type="button"
                wire:click="backupNow"
                wire:loading.attr="disabled"
                wire:target="backupNow"
                class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-2 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
            >
                <x-phosphor-download-simple class="h-3.5 w-3.5" wire:loading.remove wire:target="backupNow" />
                <x-phosphor-spinner class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="backupNow" />
                <span wire:loading.remove wire:target="backupNow">Backup Sekarang</span>
                <span wire:loading wire:target="backupNow">Membuat backup...</span>
            </button>
        </div>
        <p class="-mt-4 text-xs text-zinc-500 dark:text-zinc-400">
            Menulis dump .sql lengkap, dikompresi jadi satu berkas <span class="font-mono">.tar.gz</span>, ke penyimpanan server (di luar folder publik - berkasnya berisi hash kata sandi setiap akun).
            Untuk memulihkan: <span class="font-mono">tar -xzf berkas.tar.gz</span> lalu impor <span class="font-mono">.sql</span> di dalamnya.
            @if ($backupLastRunAt)
                Terakhir: <span class="font-medium text-zinc-600 dark:text-zinc-300">{{ app_datetime($backupLastRunAt) }}</span>.
            @else
                Belum pernah dijalankan.
            @endif
        </p>

        @if ($backupResult)
            <div @class([
                '-mt-2 flex items-start gap-1.5 rounded-lg px-3 py-2 text-xs',
                'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $backupResult['success'],
                'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' => ! $backupResult['success'],
            ])>
                <x-dynamic-component :component="$backupResult['success'] ? 'phosphor-check-circle' : 'phosphor-x-circle'" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                {{ $backupResult['message'] }}
            </div>
        @endif

        <div class="flex items-center justify-between gap-4">
            <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Jadwal Otomatis</div>
            <button
                type="button"
                role="switch"
                :aria-checked="$wire.backupScheduleEnabled"
                wire:click="$toggle('backupScheduleEnabled')"
                @class([
                    'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition',
                    'bg-accent-600' => $backupScheduleEnabled,
                    'bg-zinc-200 dark:bg-zinc-700' => ! $backupScheduleEnabled,
                ])
            >
                <span @class([
                    'inline-block h-4.5 w-4.5 transform rounded-full bg-white shadow transition',
                    'translate-x-6' => $backupScheduleEnabled,
                    'translate-x-1' => ! $backupScheduleEnabled,
                ])></span>
            </button>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="flex flex-col gap-2">
                <label for="backupFrequency" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Frekuensi</label>
                <select
                    wire:model="backupFrequency" id="backupFrequency"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('backupFrequency') border-red-400 @enderror"
                >
                    <option value="daily">Setiap hari</option>
                    <option value="weekly">Setiap Senin</option>
                    <option value="monthly">Setiap tanggal 1</option>
                </select>
                @error('backupFrequency') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-col gap-2">
                <label for="backupTime" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Jam ({{ app_timezone_label() }})</label>
                <input
                    wire:model="backupTime" id="backupTime" type="time"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('backupTime') border-red-400 @enderror"
                >
                @error('backupTime') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-col gap-2">
                <label for="backupKeep" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Simpan Terakhir</label>
                <input
                    wire:model="backupKeep" id="backupKeep" type="number" min="1" max="{{ \App\Services\Backup\DatabaseBackup::MAX_KEEP }}"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white @error('backupKeep') border-red-400 @enderror"
                >
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Maksimal {{ \App\Services\Backup\DatabaseBackup::MAX_KEEP }} berkas. Yang terlama dihapus otomatis setiap backup baru dibuat.</p>
                @error('backupKeep') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Spelled out in the UI because "the scheduler must be running" is the one
        thing that silently turns automatic backups into no backups at all. --}}
        <div x-data="{ open: false }" class="-mt-2 rounded-lg bg-zinc-50 px-3.5 py-3 dark:bg-zinc-800/60">
            <button type="button" @click="open = !open" class="flex w-full items-center gap-2 text-left text-xs font-medium text-zinc-600 transition hover:text-zinc-800 dark:text-zinc-300 dark:hover:text-zinc-100">
                <x-phosphor-caret-right class="h-3.5 w-3.5 shrink-0 text-zinc-400 transition-transform duration-200" x-bind:class="open ? 'rotate-90' : ''" />
                Cara menghubungkan jadwal ini ke cron server
            </button>

            <div x-show="open" x-cloak class="mt-3 flex flex-col gap-3 text-xs leading-relaxed text-zinc-600 dark:text-zinc-300">
                <p>
                    Jadwal di atas hanya berupa aturan di dalam aplikasi. Yang benar-benar membangunkannya adalah satu entri cron di server yang memanggil penjadwal Laravel <strong>setiap menit</strong> - Laravel sendiri yang memutuskan tugas mana yang jatuh tempo pada menit itu.
                </p>

                <div>
                    <p class="font-medium text-zinc-700 dark:text-zinc-200">Linux / cPanel - jalankan <code class="rounded bg-white px-1 py-0.5 dark:bg-zinc-900">crontab -e</code>, lalu tambahkan satu baris:</p>
                    <pre class="mt-1.5 overflow-x-auto rounded-lg bg-zinc-900 px-3 py-2 font-mono text-[11px] text-zinc-100">* * * * * cd {{ base_path() }} &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</pre>
                    <p class="mt-1 text-zinc-500 dark:text-zinc-400">Di cPanel, isian yang sama ada di menu "Cron Jobs": pilih "Once Per Minute", tempel bagian setelah tanda bintang sebagai Command.</p>
                </div>

                <div>
                    <p class="font-medium text-zinc-700 dark:text-zinc-200">Windows - buat Task Scheduler yang berulang tiap 1 menit dengan aksi:</p>
                    <pre class="mt-1.5 overflow-x-auto rounded-lg bg-zinc-900 px-3 py-2 font-mono text-[11px] text-zinc-100">php {{ base_path() }}\artisan schedule:run</pre>
                </div>

                <div>
                    <p class="font-medium text-zinc-700 dark:text-zinc-200">Saat pengembangan di komputer sendiri, cukup biarkan perintah ini berjalan:</p>
                    <pre class="mt-1.5 overflow-x-auto rounded-lg bg-zinc-900 px-3 py-2 font-mono text-[11px] text-zinc-100">php artisan schedule:work</pre>
                </div>

                <p class="text-zinc-500 dark:text-zinc-400">
                    Satu entri cron ini juga yang menjalankan pengecekan uptime dan pemindaian judol. Untuk memastikan sudah benar, jalankan <code class="rounded bg-white px-1 py-0.5 dark:bg-zinc-900">php artisan schedule:list</code> - kalau <code class="rounded bg-white px-1 py-0.5 dark:bg-zinc-900">db:backup</code> muncul di sana, sisanya ditangani cron.
                </p>
            </div>
        </div>

        @if ($backups->isNotEmpty())
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-800">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Berkas</th>
                            <th class="px-4 py-2.5 font-medium">Ukuran</th>
                            <th class="w-24 px-4 py-2.5 text-right font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($backups as $file)
                            <tr>
                                <td class="px-4 py-2.5">
                                    <span class="block truncate font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ $file['name'] }}</span>
                                    <span class="block text-xs text-zinc-400">{{ app_datetime($file['created_at']) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ number_format($file['size'] / 1024, 1) }} KB
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center justify-end gap-1">
                                        <a
                                            href="{{ route('pengaturan.backup.download', ['filename' => $file['name']]) }}"
                                            title="Unduh"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                                        >
                                            <x-phosphor-download-simple class="h-4 w-4" />
                                        </a>
                                        <x-confirm-dialog
                                            title="Hapus berkas backup?"
                                            message="{{ $file['name'] }} akan dihapus permanen dari server."
                                            confirm-text="Ya, Hapus"
                                            variant="danger"
                                            target="deleteBackup('{{ $file['name'] }}')"
                                        >
                                            <x-slot:trigger>
                                                <button type="button" title="Hapus" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-red-50 hover:text-red-600 dark:text-zinc-400 dark:hover:bg-red-500/10 dark:hover:text-red-400">
                                                    <x-phosphor-trash class="h-4 w-4" />
                                                </button>
                                            </x-slot:trigger>
                                            <form wire:submit="deleteBackup('{{ $file['name'] }}')" x-ref="form"></form>
                                        </x-confirm-dialog>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="flex justify-end border-t border-zinc-100 pt-5 dark:border-zinc-800">
            <button
                type="button"
                wire:click="save"
                wire:loading.attr="disabled"
                wire:target="save"
                class="flex items-center gap-2 rounded-lg bg-accent-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-accent-700 disabled:cursor-not-allowed disabled:opacity-70"
            >
                <x-phosphor-floppy-disk class="h-4 w-4" wire:loading.remove wire:target="save" />
                <x-phosphor-spinner class="h-4 w-4 animate-spin" wire:loading wire:target="save" />
                Simpan Pengaturan
            </button>
        </div>
    </div>
    </div>
</div>
