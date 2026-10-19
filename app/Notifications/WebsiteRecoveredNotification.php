<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\Website;
use App\Notifications\Concerns\QueuesOnChecks;
use App\Notifications\Concerns\SendsIncidentEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WebsiteRecoveredNotification extends Notification implements ShouldQueue
{
    use QueuesOnChecks, SendsIncidentEmail;

    public function __construct(public Website $website, public ?Incident $incident = null) {}

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'recovered',
            'severity' => 'info',
            'title' => "{$this->website->name} pulih kembali",
            'message' => "Website {$this->website->name} ({$this->website->url}) sudah dapat diakses kembali.",
            'website_id' => $this->website->id,
            'incident_id' => $this->incident?->id,
            'url' => route('situs.show', $this->website),
        ];
    }
}
