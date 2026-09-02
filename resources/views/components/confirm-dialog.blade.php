@props([
    'title' => 'Konfirmasi',
    'message' => 'Apakah Anda yakin?',
    'confirmText' => 'Ya, lanjutkan',
    'cancelText' => 'Batal',
    'variant' => 'default', // 'default' | 'danger' | 'success'
    'mode' => 'confirm', // 'confirm' (confirm + cancel, submits x-ref="form" in the slot) | 'alert' (single dismiss button)
    'target' => null, // optional wire:target (the form's wire:submit action, e.g. "deleteAll") - the dialog
    // itself closes the instant it's confirmed, so this shows a spinner on the *trigger* button instead
    // (it's what's back on screen while the request is actually in flight) and blocks it from reopening.
    'triggerClass' => '', // extra classes for the wrapper around the trigger. The wrapper is inline-flex
    // (it has to be positioned, to hold the spinner overlay), so it shrinks to its content by default -
    // pass "w-full" when the trigger is meant to fill its container, e.g. the sidebar's Keluar button.
])

{{--
    Reusable dialog for confirmations and plain alerts.

    Confirm usage (submits a form on confirm):
        <x-confirm-dialog title="Hapus data?" message="Tindakan ini tidak bisa dibatalkan." confirm-text="Hapus" variant="danger" target="deleteAll">
            <x-slot:trigger>
                <button type="button">Hapus</button>
            </x-slot:trigger>
            <form wire:submit="deleteAll" x-ref="form"></form>
        </x-confirm-dialog>

    Plain alert usage (single dismiss button, no form needed):
        <x-confirm-dialog mode="alert" variant="success" title="Berhasil disimpan" message="Perubahan Anda telah disimpan." confirm-text="OK">
            <x-slot:trigger>
                <button type="button">Simpan</button>
            </x-slot:trigger>
        </x-confirm-dialog>
--}}

<div x-data="{ open: false }" class="contents">
    <span @click="open = true" class="relative inline-flex {{ $triggerClass }}">
        <span @if ($target) wire:loading.class="invisible" wire:target="{{ $target }}" @endif class="contents">
            {{ $trigger }}
        </span>
        @if ($target)
            <span wire:loading wire:target="{{ $target }}" class="pointer-events-none absolute inset-0 flex items-center justify-center">
                <x-phosphor-spinner class="h-4 w-4 animate-spin" />
            </span>
        @endif
    </span>

    {{ $slot }}

    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            class="fixed inset-0 z-[100] flex items-center justify-center p-4"
            @keydown.escape.window="open = false"
            role="alertdialog"
            aria-modal="true"
        >
            <div
                x-show="open"
                x-transition.opacity
                @click="open = false"
                class="absolute inset-0 bg-zinc-900/50"
            ></div>

            <div
                x-show="open"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="relative w-full max-w-sm rounded-2xl border border-zinc-200 bg-white p-5 shadow-xl shadow-zinc-900/10 dark:border-zinc-800 dark:bg-zinc-900"
            >
                <div class="flex items-start gap-3">
                    <span @class([
                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-full',
                        'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400' => $variant === 'danger',
                        'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' => $variant === 'success',
                        'bg-accent-50 text-accent-600 dark:bg-accent-500/10 dark:text-accent-400' => $variant === 'default',
                    ])>
                        @if ($variant === 'danger')
                            <x-phosphor-warning-circle class="h-5 w-5" />
                        @elseif ($variant === 'success')
                            <x-phosphor-check-circle class="h-5 w-5" />
                        @else
                            <x-phosphor-question class="h-5 w-5" />
                        @endif
                    </span>
                    <div class="min-w-0 flex-1 pt-0.5">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $title }}</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $message }}</p>
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    @if ($mode === 'confirm')
                        <button
                            type="button"
                            @click="open = false"
                            class="rounded-lg px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 active:scale-[0.98] dark:text-zinc-300 dark:hover:bg-zinc-800"
                        >
                            {{ $cancelText }}
                        </button>
                        <button
                            type="button"
                            @click="open = false; $nextTick(() => $refs.form.requestSubmit())"
                            @class([
                                'rounded-lg px-3.5 py-2 text-sm font-semibold text-white transition active:scale-[0.98]',
                                'bg-red-600 hover:bg-red-700' => $variant === 'danger',
                                'bg-emerald-600 hover:bg-emerald-700' => $variant === 'success',
                                'bg-accent-600 hover:bg-accent-700' => $variant === 'default',
                            ])
                        >
                            {{ $confirmText }}
                        </button>
                    @else
                        <button
                            type="button"
                            @click="open = false"
                            @class([
                                'rounded-lg px-3.5 py-2 text-sm font-semibold text-white transition active:scale-[0.98]',
                                'bg-red-600 hover:bg-red-700' => $variant === 'danger',
                                'bg-emerald-600 hover:bg-emerald-700' => $variant === 'success',
                                'bg-accent-600 hover:bg-accent-700' => $variant === 'default',
                            ])
                        >
                            {{ $confirmText }}
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </template>
</div>
