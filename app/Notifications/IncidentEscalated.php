<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\Website;
use App\Notifications\Concerns\SendsIncidentEmail;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * A critical incident nobody has closed yet.
 *
 * The first alert can be missed - it arrives at 02:00, the phone is on silent,
 * the person who saw it assumed somebody else was on it. This is the second one,
 * and its whole job is to say how long the problem has been running, because
 * "masih down" and "sudah 45 menit down" prompt very different responses.
 */
class IncidentEscalated extends Notification
{
    use SendsIncidentEmail;

    public function __construct(public Website $website, public Incident $incident) {}

    public function toArray(object $notifiable): array
    {
        $minutes = (int) $this->incident->detected_at->diffInMinutes(now());

        return [
            'type' => 'escalation',
            'severity' => 'critical',
            'title' => "BELUM SELESAI: {$this->incident->title}",
            'message' => "Insiden ini sudah terbuka {$minutes} menit sejak "
                .Carbon::parse($this->incident->detected_at)->translatedFormat('d M Y, H:i')
                .' dan belum ditandai selesai. Mohon dicek kembali atau diteruskan ke penanggung jawab lain.',
            'website_id' => $this->website->id,
            'incident_id' => $this->incident->id,
            'url' => route('insiden'),
        ];
    }
}
