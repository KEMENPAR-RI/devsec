<?php

namespace App\Notifications\Middleware;

use App\Services\Notifications\MailSettings;
use Closure;

/**
 * The worker is a separate process from the one that queued the mail, so the
 * saved SMTP settings must be applied again right before the send.
 */
class ApplyMailSettings
{
    public function handle(object $job, Closure $next): mixed
    {
        app(MailSettings::class)->applyToRuntime();

        return $next($job);
    }
}
