<?php

namespace App\Console\Commands;

use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\IncidentEscalated;
use App\Services\Notifications\MailSettings;
use App\Services\Notifications\WebhookNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Chases critical incidents that nobody has closed.
 *
 * The gap this fills is not detection - the first alert already went out. It is
 * the far more common failure where everyone assumed somebody else picked it up,
 * and the site stayed down for six hours with an unread email about it.
 *
 * Exactly one reminder per incident. A repeating alarm is one people mute, and a
 * muted channel is worse than no channel.
 */
class EscalateIncidents extends Command
{
    protected $signature = 'incidents:escalate';

    protected $description = 'Re-notify about critical incidents that are still open past the escalation window';

    /** Minutes before an unclosed critical incident is chased, when nothing is configured. */
    public const DEFAULT_MINUTES = 30;

    public function handle(): int
    {
        if (Setting::get('escalation_enabled') !== '1') {
            return self::SUCCESS;
        }

        $minutes = max(5, (int) (Setting::get('escalation_minutes') ?: self::DEFAULT_MINUTES));

        $stale = Incident::query()
            ->open()
            ->where('severity', 'critical')
            ->where('detected_at', '<=', now()->subMinutes($minutes))
            // Stamped on the incident itself rather than tracked elsewhere, so
            // "has this been chased" survives a worker restart.
            ->whereNull('meta->escalated_at')
            ->with('website')
            ->get();

        foreach ($stale as $incident) {
            // A site under maintenance is down on purpose; chasing it is the
            // opposite of useful.
            if (! $incident->website || $incident->website->isUnderMaintenance()) {
                continue;
            }

            $this->escalate($incident);
        }

        $this->info("{$stale->count()} insiden dieskalasi.");

        return self::SUCCESS;
    }

    protected function escalate(Incident $incident): void
    {
        $incident->forceFill([
            'meta' => [...($incident->meta ?? []), 'escalated_at' => now()->toIso8601String()],
        ])->save();

        $admins = User::all();
        $notification = new IncidentEscalated($incident->website, $incident);

        app(MailSettings::class)->applyToRuntime();
        Notification::send($admins, $notification);

        app(WebhookNotifier::class)->sendNotification($notification, $admins->first());
    }
}
