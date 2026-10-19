<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\Website;
use App\Notifications\Concerns\QueuesOnChecks;
use App\Notifications\Concerns\SendsIncidentEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AttackAnomalyDetected extends Notification implements ShouldQueue
{
    use QueuesOnChecks, SendsIncidentEmail;

    public function __construct(public Website $website, public Incident $incident) {}

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'attack',
            'severity' => $this->incident->severity,
            'title' => "Anomali terdeteksi di {$this->website->name}",
            'message' => $this->incident->title,
            'website_id' => $this->website->id,
            'incident_id' => $this->incident->id,
            'url' => route('situs.show', $this->website),
        ];
    }
}
