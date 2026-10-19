<?php

namespace App\Notifications\Concerns;

use App\Notifications\Middleware\ApplyMailSettings;
use Illuminate\Bus\Queueable;

/**
 * Moves the SMTP send of a check-time notification off the check job: mail goes
 * to the checks-high queue, the in-app (database) entry stays immediate.
 */
trait QueuesOnChecks
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return ['mail' => config('monitoring.queues.checks_high')];
    }

    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    /** @return array<int, object> */
    public function middleware(object $notifiable, string $channel): array
    {
        return $channel === 'mail' ? [new ApplyMailSettings] : [];
    }
}
