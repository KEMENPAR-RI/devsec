<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * The break-glass path for an operator who lost both their authenticator and
 * their recovery codes.
 *
 * Deliberately console-only, and deliberately not a button anywhere in the UI:
 * an in-app "disable someone's 2FA" control is the exact thing a social-engineered
 * admin would be talked into clicking. Running this needs shell access to the
 * server, which is a bar a phone call cannot clear. It is still audited.
 */
class DisableTwoFactor extends Command
{
    protected $signature = 'two-factor:disable {email : The account to strip 2FA from}';

    protected $description = 'Disable two-factor authentication for one account (recovery path)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("Tidak ada akun dengan email {$this->argument('email')}.");

            return self::FAILURE;
        }

        if (! $user->hasTwoFactorEnabled()) {
            $this->info("{$user->email} memang belum mengaktifkan verifikasi dua faktor.");

            return self::SUCCESS;
        }

        if (! $this->confirm("Nonaktifkan verifikasi dua faktor untuk {$user->email}?", false)) {
            $this->info('Dibatalkan.');

            return self::SUCCESS;
        }

        $user->disableTwoFactor();

        // No signed-in actor here, so the line records the console as the source.
        AuditLog::record('users.2fa-dinonaktifkan', $user->email, ['sumber' => 'perintah konsol two-factor:disable']);

        $this->info("Verifikasi dua faktor untuk {$user->email} dinonaktifkan. Minta yang bersangkutan mengaktifkannya kembali dari halaman Profil.");

        return self::SUCCESS;
    }
}
