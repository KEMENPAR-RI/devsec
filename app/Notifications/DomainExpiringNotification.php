<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\Website;
use App\Notifications\Concerns\SendsIncidentEmail;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * The domain registration is running out.
 *
 * Nothing else in this app catches this: the site is up, the certificate is fine,
 * the content is clean - right until the registration lapses and the whole domain
 * stops resolving. The warning only has value ahead of the date.
 */
class DomainExpiringNotification extends Notification
{
    use SendsIncidentEmail;

    public function __construct(public Website $website, public Incident $incident) {}

    public function toArray(object $notifiable): array
    {
        $expiresAt = ($raw = $this->incident->meta['expires_at'] ?? null) ? Carbon::parse($raw) : null;
        $days = $expiresAt ? (int) now()->startOfDay()->diffInDays($expiresAt->startOfDay(), false) : null;

        return [
            'type' => 'domain',
            'severity' => $this->incident->severity,
            'title' => "Domain {$this->website->name} akan kedaluwarsa",
            'message' => $expiresAt
                ? 'Registrasi domain berakhir pada '.$expiresAt->translatedFormat('d F Y')
                    .($days !== null ? " ({$days} hari lagi)" : '')
                    .'. Perpanjang sebelum tanggal tersebut - setelah lewat, seluruh domain berhenti resolve, bukan hanya situsnya.'
                : $this->incident->description,
            'website_id' => $this->website->id,
            'incident_id' => $this->incident->id,
            'url' => route('situs.show', $this->website),
        ];
    }
}
