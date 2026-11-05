<?php

namespace App\Notifications\Concerns;

use App\Mail\IncidentEmail;
use App\Services\Notifications\MailSettings;

/**
 * Mixed into every dispatched Notification class (WebsiteDownNotification,
 * WebsiteRecoveredNotification, AttackAnomalyDetected) to
 * add email delivery on top of the existing in-app (database) notification,
 * without duplicating the same via()/toMail() pair four times.
 *
 * Requires the consuming class to have a public Website $website property and a
 * toArray(object $notifiable): array method (all four already do, for the
 * database channel) - toArray()'s uniform shape (title/message/type/severity/url)
 * is exactly what feeds the customizable email template.
 */
trait SendsIncidentEmail
{
    /**
     * Email is entirely opt-in - only added once someone has actually configured
     * & enabled it on the Pengaturan page, so a fresh install with nothing set
     * behaves exactly as before (database-only).
     */
    public function via(object $notifiable): array
    {
        return app(MailSettings::class)->enabled()
            ? ['database', 'mail']
            : ['database'];
    }

    public function toMail(object $notifiable): IncidentEmail
    {
        $data = $this->toArray($notifiable);
        $data['website_name'] = $this->website->name;
        $data['website_url'] = $this->website->url;
        $data['action_url'] = $data['url'] ?? url('/');

        // A batched notification sets its own detected_at from the earliest item it
        // covers; the single-incident ones fall back to their incident's timestamp.
        $data['detected_at'] ??= ($this->incident ?? null)?->detected_at?->translatedFormat('d M Y, H:i')
            ?? now()->translatedFormat('d M Y, H:i');

        // {{items}} renders the individual findings behind a batched notification,
        // so one email still says exactly which pages were hit.
        $data['items'] = $this->renderItemsList($data['items'] ?? [], $data['items_remaining'] ?? 0);

        // The shared template builds its label/value table from {{details}}, so
        // incident emails supply the rows that used to be hard-coded in it.
        $data['details'] = app(MailSettings::class)->renderDetails([
            'Situs' => $data['website_name'],
            'Waktu' => $data['detected_at'],
        ]);
        $data['action_label'] = 'Lihat Detail';
        $data['footer'] = 'Notifikasi otomatis dari '.config('app.name').' - '.$data['website_url'];

        // Unlike the built-in MailMessage path, Laravel's MailChannel does NOT set
        // the recipient for a Mailable returned from toMail() - that's on us here.
        // ->mailer('smtp') pins it to the mailer applyToRuntime() actually configured -
        // MAIL_MAILER defaults to "log" in .env, which would otherwise silently
        // swallow every one of these instead of sending it.
        return (new IncidentEmail($data))
            ->to($notifiable->routeNotificationFor('mail', $this) ?? $notifiable)
            ->mailer('smtp');
    }

    /**
     * @param  array<int, string>  $items
     */
    protected function renderItemsList(array $items, int $remaining): string
    {
        if ($items === []) {
            return '';
        }

        $rows = collect($items)
            ->map(fn (string $item) => '<li style="margin:0 0 4px;">'.e($item).'</li>')
            ->implode('');

        if ($remaining > 0) {
            $rows .= '<li style="margin:0;color:#71717a;">dan '.$remaining.' lainnya</li>';
        }

        return '<ul style="margin:0 0 20px;padding-left:20px;font-size:13px;line-height:1.6;color:#52525b;word-break:break-all;">'.$rows.'</ul>';
    }
}
