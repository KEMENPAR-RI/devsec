<?php

namespace App\Notifications;

use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Notifications\Concerns\QueuesOnChecks;
use App\Notifications\Concerns\SendsIncidentEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WebsiteSlowNotification extends Notification implements ShouldQueue
{
    use QueuesOnChecks, SendsIncidentEmail;

    public function __construct(public Website $website, public WebsiteCheck $check) {}

    public function toArray(object $notifiable): array
    {
        $message = $this->check->error_type === 'read_timeout'
            ? "Website {$this->website->name} ({$this->website->url}) mengalami gangguan: terhubung tetapi tidak selesai merespons dalam batas waktu."
            : "Website {$this->website->name} ({$this->website->url}) mengalami gangguan: merespons dalam {$this->check->response_time_ms} ms, melebihi ambang {$this->website->response_time_threshold_ms} ms.";

        return [
            'type' => 'slow_response',
            'severity' => 'warning',
            'title' => "{$this->website->name} mengalami gangguan",
            'message' => $message.' Akan dianggap Down bila tetap lambat.',
            'website_id' => $this->website->id,
            'incident_id' => null,
            'url' => route('situs.show', $this->website),
        ];
    }
}
