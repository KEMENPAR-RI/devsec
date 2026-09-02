<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\Website;
use App\Notifications\Concerns\SendsIncidentEmail;
use Illuminate\Notifications\Notification;

class WebsiteDownNotification extends Notification
{
    use SendsIncidentEmail;

    public function __construct(public Website $website, public Incident $incident) {}

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'downtime',
            'severity' => 'critical',
            'title' => "{$this->website->name} down",
            'message' => "Website {$this->website->name} ({$this->website->url}) tidak dapat diakses.",
            'website_id' => $this->website->id,
            'incident_id' => $this->incident->id,
            'url' => route('situs.show', $this->website),
        ];
    }
}
