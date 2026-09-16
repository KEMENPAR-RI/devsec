<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\Website;
use App\Notifications\Concerns\SendsIncidentEmail;
use Illuminate\Notifications\Notification;

/**
 * Google has put this domain on its blocklist.
 *
 * For visitors this is indistinguishable from an outage - Chrome shows a red
 * interstitial and almost nobody clicks through - but nothing else this app
 * measures would notice, because the server answers perfectly.
 */
class ReputationFlagged extends Notification
{
    use SendsIncidentEmail;

    public function __construct(public Website $website, public Incident $incident) {}

    public function toArray(object $notifiable): array
    {
        $threats = $this->incident->meta['ancaman_label'] ?? '';

        return [
            'type' => 'reputation',
            'severity' => 'critical',
            'title' => "{$this->website->name} ditandai Google Safe Browsing",
            'message' => "Domain ini masuk daftar blokir Google dengan kategori: {$threats}. "
                .'Pengunjung Chrome akan melihat halaman peringatan merah sebelum masuk. '
                .'Bersihkan penyebabnya, lalu ajukan peninjauan ulang lewat Google Search Console.',
            'website_id' => $this->website->id,
            'incident_id' => $this->incident->id,
            'url' => route('situs.show', $this->website),
        ];
    }
}
