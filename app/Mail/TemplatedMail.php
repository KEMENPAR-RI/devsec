<?php

namespace App\Mail;

use App\Services\Notifications\MailSettings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one mailable behind every email this app sends.
 *
 * It renders the admin-editable subject/body templates from the Pengaturan page
 * (App\Services\Notifications\MailSettings) against a plain data array, so a new
 * kind of email means supplying different data - never a new layout, and never a
 * second place to restyle when the branding changes.
 *
 * Recognised keys (all optional; see MailSettings::placeholders()):
 *   title, message, eyebrow, items, details, action_url, action_label, footer,
 *   website_name, website_url, severity, type, detected_at
 *
 * Use TemplatedMail::for() for one-off emails; subclass it (see IncidentEmail)
 * only when a family of emails needs its own name for readability.
 *
 * Not ShouldQueue: sent synchronously like the rest of this app's mail, so no
 * queue worker has to be running for an alert - or a password reset - to arrive.
 */
class TemplatedMail extends Mailable
{
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public array $data) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function for(array $data): static
    {
        return new static($data);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: app(MailSettings::class)->renderSubject($this->data),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.templated',
            with: ['bodyHtml' => app(MailSettings::class)->renderBody($this->data)],
        );
    }
}
