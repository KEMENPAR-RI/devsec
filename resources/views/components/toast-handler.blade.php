{{-- Menampilkan flash message dari server sebagai toast.

Dua sumber mengisi antrean toast yang sama:
  - session flash (session()->flash('status', '...')), satu-satunya yang bertahan
    melewati redirect, jadi ini yang dipakai saat full page load;
  - Livewire $this->dispatch('toast', ...), ditangani di resources/js/toast.js
    untuk aksi yang tidak redirect.

Pesan ditulis lewat @js agar teks yang memuat kutip atau nama dari input user
tidak bisa keluar dari literal JavaScript. --}}
@php
    // Key yang sudah dipakai aplikasi, dipetakan ke jenis toast-nya. 'error',
    // 'warning' dan 'info' adalah key konvensional untuk aksi yang gagal.
    $toastFlashKeys = [
        'status' => 'success',
        'keyword-status' => 'success',
        'profileStatus' => 'success',
        'passwordStatus' => 'success',
        'sessionStatus' => 'success',
        'twoFactorStatus' => 'success',
        'success' => 'success',
        'info' => 'info',
        'warning' => 'warning',
        'error' => 'error',
    ];

    $toasts = [];

    foreach ($toastFlashKeys as $key => $type) {
        if (filled(session($key))) {
            $toasts[] = ['message' => (string) session($key), 'type' => $type];
        }
    }
@endphp

@if ($toasts)
    <script>
        (function () {
            const queued = @js($toasts);

            function flush() {
                if (typeof window.appToast !== 'function') {
                    return false;
                }

                queued.forEach((item) => window.appToast(item.message, item.type));
                queued.length = 0;

                return true;
            }

            // resources/js/toast.js dimuat sebagai module (defer), jadi saat skrip
            // inline ini jalan window.appToast biasanya belum ada - tunggu load.
            if (!flush()) {
                window.addEventListener('load', flush, { once: true });
            }
        })();
    </script>
@endif
