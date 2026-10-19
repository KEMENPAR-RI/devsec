<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\Website;
use App\Notifications\Concerns\QueuesOnChecks;
use App\Notifications\Concerns\SendsIncidentEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * A TLS certificate problem: expiring/expired, a hostname mismatch, or an
 * untrusted chain (incident meta.reason). Expiry is raised ahead of the date
 * (config monitoring.ssl.*, see WebsiteChecker::maybeCheckSsl), so there is time to renew.
 */
class SslExpiringNotification extends Notification implements ShouldQueue
{
    use QueuesOnChecks, SendsIncidentEmail;

    public function __construct(public Website $website, public Incident $incident) {}

    public function toArray(object $notifiable): array
    {
        $expiresAt = ($raw = $this->incident->meta['expires_at'] ?? null)
            ? Carbon::parse($raw)->setTimezone(config('app.timezone'))
            : null;

        $days = $expiresAt ? (int) now()->startOfDay()->diffInDays($expiresAt->startOfDay(), false) : null;
        $date = $expiresAt?->translatedFormat('d F Y');
        $host = $this->incident->meta['host'] ?? null;

        // Legacy incidents carry no reason: they were all expiry warnings.
        [$title, $message] = match ($this->incident->meta['reason'] ?? 'expiring') {
            'expired' => [
                "Sertifikat SSL {$this->website->name} sudah kedaluwarsa",
                ($date ? "Sertifikat SSL kedaluwarsa sejak {$date}. " : '')
                    .'Browser kini menolak membuka situs ini - segera pasang sertifikat baru.',
            ],
            'hostname' => [
                "Sertifikat SSL {$this->website->name} tidak cocok dengan nama host",
                'Sertifikat yang dipasang tidak berlaku untuk '.($host ?: 'alamat situs ini')
                    .'. Browser akan menampilkan peringatan keamanan - pasang sertifikat yang mencakup nama host tersebut.',
            ],
            'chain' => [
                "Rantai sertifikat SSL {$this->website->name} tidak valid",
                'Rantai sertifikat tidak dapat diverifikasi ke CA tepercaya. Biasanya karena sertifikat intermediate '
                    .'tidak ikut dipasang di server - sebagian browser dan aplikasi akan menolak koneksi.',
            ],
            default => [
                "Sertifikat SSL {$this->website->name} akan kedaluwarsa",
                $expiresAt
                    ? "Sertifikat SSL kedaluwarsa pada {$date}"
                        .($days !== null ? " ({$days} hari lagi)" : '').
                        '. Perpanjang sebelum tanggal tersebut agar situs tidak ditolak browser.'
                    : $this->incident->description,
            ],
        };

        return [
            'type' => 'ssl',
            'severity' => $this->incident->severity,
            'title' => $title,
            'message' => $message,
            'website_id' => $this->website->id,
            'incident_id' => $this->incident->id,
            'url' => route('situs.show', $this->website),
        ];
    }
}
