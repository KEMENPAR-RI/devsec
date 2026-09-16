<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AccountSecurityAlert;
use App\Support\DeviceName;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides when an account holder should hear from us about their own account.
 *
 * Reads the audit trail rather than keeping a second list of known devices:
 * audit_logs already records the IP of every successful sign-in, so "have we
 * seen this address before" is a question it can already answer, and there is
 * no parallel table to keep honest.
 */
class AccountAlerts
{
    /**
     * Warns the user when this sign-in comes from an address they have never
     * signed in from.
     *
     * Call it BEFORE recording this login, or the address always looks familiar.
     */
    public function notifyIfNewDevice(User $user): void
    {
        $ip = request()?->ip();
        $previous = AuditLog::query()->where('user_id', $user->getKey())->where('event', 'login.berhasil');

        // A first-ever sign-in is the account being used, not an intrusion - and
        // an alert there would land before the owner has any way to act on it.
        if ((clone $previous)->doesntExist()) {
            return;
        }

        if ((clone $previous)->where('ip_address', $ip)->exists()) {
            return;
        }

        $this->send($user, new AccountSecurityAlert(
            'login_perangkat_baru',
            'Masuk dari perangkat baru',
            'Akun Anda baru saja dipakai untuk masuk dari alamat IP yang belum pernah tercatat sebelumnya.',
            [
                'Alamat IP' => $ip ?: 'tidak tercatat',
                'Perangkat' => DeviceName::fromUserAgent(request()?->userAgent()),
                'Waktu' => now()->translatedFormat('d M Y, H:i'),
            ],
        ));
    }

    public function notifyPasswordChanged(User $user): void
    {
        $this->send($user, new AccountSecurityAlert(
            'kata_sandi_diubah',
            'Kata sandi akun Anda diubah',
            'Kata sandi akun ini baru saja diganti. Semua sesi di perangkat lain ikut dikeluarkan.',
            [
                'Alamat IP' => request()?->ip() ?: 'tidak tercatat',
                'Waktu' => now()->translatedFormat('d M Y, H:i'),
            ],
            severity: 'critical',
        ));
    }

    public function notifyTwoFactorDisabled(User $user): void
    {
        $this->send($user, new AccountSecurityAlert(
            '2fa_dinonaktifkan',
            'Verifikasi dua faktor dinonaktifkan',
            'Verifikasi dua faktor pada akun ini baru saja dimatikan, sehingga kata sandi kini menjadi satu-satunya pengaman.',
            [
                'Alamat IP' => request()?->ip() ?: 'tidak tercatat',
                'Waktu' => now()->translatedFormat('d M Y, H:i'),
            ],
            severity: 'critical',
        ));
    }

    /**
     * Sends the alert, and never lets it break what it is reporting on.
     *
     * Mail here goes out synchronously over SMTP (see TemplatedMail), so an
     * unreachable mail server throws. Letting that bubble up would mean a password
     * change failing *after* the new password was already saved - the user would
     * see an error for something that actually succeeded, and would likely try
     * again. The change is the point; the email is the courtesy.
     */
    protected function send(User $user, AccountSecurityAlert $alert): void
    {
        try {
            $user->notify($alert);
        } catch (Throwable $e) {
            Log::warning("AccountAlerts: gagal mengirim peringatan '{$alert->event}' ke {$user->email}: {$e->getMessage()}");
        }
    }
}
