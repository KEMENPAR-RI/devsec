<?php

namespace App\Livewire\Concerns;

/**
 * Mengirim toast dari komponen Livewire.
 *
 * Dipakai untuk aksi yang TIDAK redirect. session()->flash() hanya terbaca pada
 * request berikutnya, jadi pada aksi yang hanya me-render ulang komponen pesannya
 * baru muncul saat halaman kebetulan dimuat lagi - atau tidak muncul sama sekali.
 * Event ini ditangani resources/js/toast.js dan tampil seketika.
 *
 * Untuk aksi yang diakhiri redirect, tetap pakai session()->flash(): event akan
 * hilang saat halaman berpindah, flash tidak.
 */
trait InteractsWithToasts
{
    protected function toastSuccess(string $message): void
    {
        $this->toastMessage($message, 'success');
    }

    protected function toastError(string $message): void
    {
        $this->toastMessage($message, 'error');
    }

    protected function toastWarning(string $message): void
    {
        $this->toastMessage($message, 'warning');
    }

    protected function toastInfo(string $message): void
    {
        $this->toastMessage($message, 'info');
    }

    protected function toastMessage(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', message: $message, type: $type);
    }
}
