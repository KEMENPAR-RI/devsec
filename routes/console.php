<?php

use App\Services\Backup\DatabaseBackup;
use Illuminate\Support\Facades\Schedule;

// Monitoring: probe every active website for down/attack signals every minute
// (each website only actually checked once its own interval has elapsed).
Schedule::command('websites:check')->everyMinute()->withoutOverlapping();

// Monitoring: crawl every active website for judol (online gambling) spam content.
Schedule::command('websites:scan-judol')->everyMinute()->withoutOverlapping();

// Monitoring: DNS records and domain expiry, once a day. Separate from the
// per-minute probe because these are answered by a resolver and a registry, not
// by the site, and they change on the order of months.
Schedule::command('websites:check-domains')->dailyAt('00:30')->withoutOverlapping();

// Monitoring: security headers and TLS of the watched sites. Daily, because
// browser-hardening headers change about once a year - see GradeSecurity.
Schedule::command('websites:grade-security')->dailyAt('00:35')->withoutOverlapping();

// Monitoring: foreign scripts, hidden frames, and obfuscated code on each
// homepage. Daily - injected code stays put, so more often buys nothing.
Schedule::command('websites:scan-defacement')->dailyAt('00:40')->withoutOverlapping();

// Monitoring: chase critical incidents nobody has closed. Every five minutes
// so the reminder lands close to the configured window rather than up to an hour
// late - it sends at most one reminder per incident either way.
Schedule::command('incidents:escalate')->everyFiveMinutes()->withoutOverlapping();

// Backup: ticks every minute and asks the service whether this is the scheduled
// moment. The frequency and time live in the database, so they can't be baked into
// a cron expression here - reading them at registration would hit the settings
// table before migrations have created it (fresh install, and every test run).
Schedule::command('db:backup')
    ->everyMinute()
    ->when(fn () => app(DatabaseBackup::class)->isDue())
    ->withoutOverlapping();

// Monitoring: roll raw checks up into permanent daily totals, then prune those
// past the retention window - see App\Console\Commands\PruneWebsiteChecks.
Schedule::command('websites:prune-checks')->dailyAt('03:15')->withoutOverlapping();

// Audit: the only thing that removes a trail line, and it runs unattended - see
// App\Console\Commands\PruneAuditLogs for why there is no button for this.
Schedule::command('audit:prune')->dailyAt('03:30')->withoutOverlapping();
