<?php

namespace App\Notifications;

use App\Mail\TemplatedMail;
use App\Services\Notifications\MailSettings;
use Illuminate\Auth\Notifications\ResetPassword;

/**
 * The password-reset email, rendered through the app's own editable template
 * instead of Laravel's built-in markdown mailable.
 *
 * Two reasons it isn't the stock one: that template is English, signed "Laravel",
 * and unstyled next to everything else this app sends; and it goes out through
 * the default mailer, which is 'log' in this install - so it was written to a
 * file rather than delivered. Both problems disappear by reusing TemplatedMail.
 *
 * Extends Laravel's ResetPassword so the token creation, expiry, and the
 * Password broker's plumbing all stay exactly as the framework does them - only
 * the presentation is ours. Wired up in App\Models\User::sendPasswordResetNotification().
 */
class ResetPasswordNotification extends ResetPassword
{
    /** Signature mirrors the parent's untyped $notifiable. */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): TemplatedMail
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $mail = app(MailSettings::class);

        return TemplatedMail::for([
            'type' => 'password_reset',
            'title' => 'Atur ulang kata sandi Anda',
            'eyebrow' => 'Keamanan Akun',
            'message' => 'Kami menerima permintaan untuk mengatur ulang kata sandi akun '
                .$notifiable->getEmailForPasswordReset().'. Klik tombol di bawah untuk memilih kata sandi baru.',
            'details' => $mail->renderDetails([
                'Berlaku selama' => $minutes.' menit',
                'Diminta pada' => now()->translatedFormat('d M Y, H:i'),
            ]),
            'action_url' => $url,
            'action_label' => 'Atur Ulang Kata Sandi',
            'footer' => 'Kalau Anda tidak meminta ini, abaikan email ini - kata sandi Anda tidak berubah.',
        ])
            // Laravel's MailChannel does not address a Mailable returned from
            // toMail(), and ->mailer('smtp') pins it to the mailer MailSettings
            // actually configures rather than whatever MAIL_MAILER happens to be.
            ->to($notifiable->getEmailForPasswordReset())
            ->mailer('smtp');
    }
}
