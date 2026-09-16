<?php

namespace App\Notifications;

use App\Mail\TemplatedMail;
use App\Services\Notifications\MailSettings;
use Illuminate\Notifications\Notification;

/**
 * Tells one account holder that something security-relevant happened to their
 * own account - a sign-in from an address never seen before, a changed password,
 * a second factor switched off.
 *
 * This is the half of the audit trail that reaches the person who would notice.
 * An audit log answers "what happened" after somebody thinks to look; this is
 * what makes them look.
 */
class AccountSecurityAlert extends Notification
{
    /**
     * @param  array<string, string>  $details  label => value rows for the email table
     */
    public function __construct(
        public string $event,
        public string $title,
        public string $message,
        public array $details = [],
        public string $severity = 'warning',
    ) {}

    public function via(object $notifiable): array
    {
        return app(MailSettings::class)->enabled()
            ? ['database', 'mail']
            : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->event,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            'url' => route('profil'),
        ];
    }

    public function toMail(object $notifiable): TemplatedMail
    {
        $mailSettings = app(MailSettings::class);

        return TemplatedMail::for([
            'type' => $this->event,
            'severity' => $this->severity,
            'eyebrow' => 'Keamanan Akun',
            'title' => $this->title,
            'message' => $this->message,
            'details' => $mailSettings->renderDetails($this->details),
            'action_url' => route('profil'),
            'action_label' => 'Buka Profil Saya',
            'detected_at' => now()->translatedFormat('d M Y, H:i'),
            'footer' => 'Bila ini bukan Anda, segera ganti kata sandi dan keluarkan semua perangkat lain dari halaman Profil.',
        ])
            // Same reasons as SendsIncidentEmail: MailChannel does not address a
            // Mailable for us, and MAIL_MAILER would otherwise swallow this.
            ->to($notifiable->routeNotificationFor('mail', $this) ?? $notifiable)
            ->mailer('smtp');
    }
}
