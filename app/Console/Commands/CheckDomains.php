<?php

namespace App\Console\Commands;

use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use App\Notifications\ReputationFlagged;
use App\Services\Monitoring\ReputationChecker;
use App\Services\Monitoring\WebsiteChecker;
use App\Services\Notifications\MailSettings;
use App\Services\Notifications\WebhookNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The daily pass over DNS records and domain expiry.
 *
 * Separate from websites:check, which runs every minute. These two questions are
 * answered by a resolver and a registry rather than by the site itself, they
 * change on the order of months, and asking them per probe would mean an RDAP
 * round trip on every check of every site.
 *
 * Sites under maintenance are skipped for the same reason the probe skips them:
 * a migration is exactly when DNS is expected to move.
 */
class CheckDomains extends Command
{
    protected $signature = 'websites:check-domains
        {--force : Ignore the once-a-day guard and re-check everything now}';

    protected $description = 'Check DNS, domain expiry, and Safe Browsing reputation for every active website';

    public function handle(WebsiteChecker $checker): int
    {
        $websites = Website::query()->where('is_active', true)->get();

        if ($websites->isEmpty()) {
            $this->info('Tidak ada situs aktif untuk diperiksa.');

            return self::SUCCESS;
        }

        $checked = 0;

        foreach ($websites as $website) {
            if ($website->isUnderMaintenance()) {
                continue;
            }

            if ($this->option('force')) {
                $website->update(['dns_checked_at' => null, 'domain_checked_at' => null]);
            }

            // One bad domain must not stop the rest of the list - this runs
            // unattended, and a single unreachable registry would otherwise mean
            // nothing after it gets checked at all.
            try {
                // A forced drift check from websites:check may have moved dns_records since the query.
                $website->refresh();
                $checker->checkDns($website);
                $checker->checkDomainExpiry($website);
                $this->checkReputation($website);
                $checked++;
            } catch (Throwable $e) {
                $this->warn("{$website->name}: {$e->getMessage()}");
            }
        }

        $this->info("{$checked} situs diperiksa (DNS, masa berlaku domain, reputasi).");

        return self::SUCCESS;
    }

    /**
     * Records what Google says, and raises an incident the first time a site is
     * listed.
     *
     * Without an API key this is a no-op that records nothing - the status stays
     * null rather than being written as "clean", because "we did not ask" must
     * not read as "we asked and it was fine".
     */
    protected function checkReputation(Website $website): void
    {
        $checker = app(ReputationChecker::class);

        if (! $checker->isConfigured()) {
            return;
        }

        $result = $checker->check($website->url);

        if ($result['status'] === 'unknown') {
            return;
        }

        $wasFlagged = $website->reputation_status === 'flagged';

        $website->update([
            'reputation_status' => $result['status'],
            'reputation_threats' => $result['threats'],
            'reputation_checked_at' => now(),
        ]);

        if ($result['status'] === 'clean') {
            // Delisted: close the incident, so the site stops reading as blocked
            // the moment Google stops blocking it.
            $website->incidents()->open()->where('type', 'reputation')->each(fn ($incident) => $incident->resolve());

            return;
        }

        // Already flagged yesterday and still flagged today is not news.
        if ($wasFlagged) {
            return;
        }

        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'reputation',
            'severity' => 'critical',
            'title' => "{$website->name} ditandai Google Safe Browsing",
            'description' => 'Domain masuk daftar blokir Google: '.$checker->describe($result['threats']).'.',
            'meta' => ['ancaman' => $result['threats'], 'ancaman_label' => $checker->describe($result['threats'])],
            'status' => 'open',
            'detected_at' => now(),
        ]);

        $admins = User::all();
        $notification = new ReputationFlagged($website, $incident);

        app(MailSettings::class)->applyToRuntime();
        Notification::send($admins, $notification);

        app(WebhookNotifier::class)->sendNotification($notification, $admins->first());
    }
}
