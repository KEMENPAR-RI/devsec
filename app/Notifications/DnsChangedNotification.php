<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\Website;
use App\Notifications\Concerns\QueuesOnChecks;
use App\Notifications\Concerns\SendsIncidentEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The DNS answering for a domain has changed.
 *
 * This is the one alert that fires while the site still looks perfectly healthy -
 * a hijacked domain serves a 200 from somebody else's server. If it was a planned
 * migration the operator dismisses it in a second; if it was not, this is the
 * earliest anyone could possibly have known.
 */
class DnsChangedNotification extends Notification implements ShouldQueue
{
    use QueuesOnChecks, SendsIncidentEmail;

    public function __construct(public Website $website, public Incident $incident) {}

    public function toArray(object $notifiable): array
    {
        $types = implode(', ', array_keys($this->incident->meta['changes'] ?? []));

        return [
            'type' => 'dns',
            'severity' => $this->incident->severity,
            'title' => "Record DNS {$this->website->name} berubah",
            'message' => "Record {$types} untuk domain ini berbeda dari yang terakhir tercatat. "
                .'Kalau perubahan ini bukan dari pihak Anda, segera periksa akun registrar dan DNS panel.',
            'website_id' => $this->website->id,
            'incident_id' => $this->incident->id,
            'url' => route('situs.show', $this->website),
        ];
    }
}
