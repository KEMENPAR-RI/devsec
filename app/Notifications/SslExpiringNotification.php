<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\Website;
use App\Notifications\Concerns\SendsIncidentEmail;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * An expiring TLS certificate. Unlike downtime, this one is entirely preventable -
 * but only if somebody hears about it before the date passes, which is exactly
 * what the email is for. The incident is raised 14 days ahead (see
 * WebsiteChecker::maybeCheckSsl), so there is time to renew.
 */
class SslExpiringNotification extends Notification
{
    use SendsIncidentEmail;

    public function __construct(public Website $website, public Incident $incident) {}

    public function toArray(object $notifiable): array
    {
        $expiresAt = ($raw = $this->incident->meta['expires_at'] ?? null)
            ? Carbon::parse($raw)
            : null;

        $days = $expiresAt ? (int) now()->startOfDay()->diffInDays($expiresAt->startOfDay(), false) : null;

        return [
            'type' => 'ssl',
            'severity' => $this->incident->severity,
            'title' => "Sertifikat SSL {$this->website->name} akan kedaluwarsa",
            'message' => $expiresAt
                ? "Sertifikat SSL kedaluwarsa pada {$expiresAt->translatedFormat('d F Y')}"
                    .($days !== null ? " ({$days} hari lagi)" : '').
                    '. Perpanjang sebelum tanggal tersebut agar situs tidak ditolak browser.'
                : $this->incident->description,
            'website_id' => $this->website->id,
            'incident_id' => $this->incident->id,
            'url' => route('situs.show', $this->website),
        ];
    }
}
