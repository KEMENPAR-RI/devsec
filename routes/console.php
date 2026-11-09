<?php

use App\Services\Backup\DatabaseBackup;
use Illuminate\Support\Facades\Schedule;

// Queue workers run from the scheduler so the server needs only the one
// `schedule:run` cron line (no systemd/supervisor). Each tick's worker stays up
// for --max-time, polling every --sleep seconds even when idle: --stop-when-empty
// would exit on a delayed (released) job, stretching the 8s no-response retry and
// the Warning re-check to the next minute. withoutOverlapping keeps one worker alive, and runInBackground
// stops a long check from holding up every other task here. The overlap lock
// expiry (10 min) must exceed --max-time plus the longest job timeout (55+150s),
// or a second worker could start beside a healthy one. Timeouts must stay below
// DB_QUEUE_RETRY_AFTER (320).
// checks-low (Warning re-checks) last: a run of slow sites only uses idle worker time.
Schedule::command('queue:work database --queue='.implode(',', [config('monitoring.queues.checks_high'), config('monitoring.queues.checks'), 'default', config('monitoring.queues.checks_low')]).' --max-time=55 --sleep=3 --tries=1 --timeout=150 --memory=128')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

// Monitoring: probe every active website for down/attack signals every minute
// (each website only actually checked once its own interval has elapsed).
Schedule::command('websites:check')->everyMinute()->withoutOverlapping(10);

// Monitoring: sites in Warning (slow) are re-checked within seconds (see
// monitoring.warning_recheck_seconds), so they reach Down or recover quickly.
// A sub-minute event keeps `schedule:run` resident for the whole minute.
Schedule::command('websites:check --warning')->everyFifteenSeconds()->withoutOverlapping(1);

// Monitoring: DNS records and domain expiry, once a day. Separate from the
// per-minute probe because these are answered by a resolver and a registry, not
// by the site, and they change on the order of months.
Schedule::command('websites:check-domains')->dailyAt('00:30')->withoutOverlapping(120)->runInBackground();

// Monitoring: security headers and TLS of the watched sites. Daily, because
// browser-hardening headers change about once a year - see GradeSecurity.
Schedule::command('websites:grade-security')->dailyAt('00:35')->withoutOverlapping(120)->runInBackground();

// Monitoring: chase critical incidents nobody has closed. Every five minutes
// so the reminder lands close to the configured window rather than up to an hour
// late - it sends at most one reminder per incident either way.
Schedule::command('incidents:escalate')->everyFiveMinutes()->withoutOverlapping(10);

// Backup: ticks every minute and asks the service whether this is the scheduled
// moment. The frequency and time live in the database, so they can't be baked into
// a cron expression here - reading them at registration would hit the settings
// table before migrations have created it (fresh install, and every test run).
Schedule::command('db:backup')
    ->everyMinute()
    ->when(fn () => app(DatabaseBackup::class)->isDue())
    ->withoutOverlapping(120)
    // A long dump must not hold up the 15-second Warning re-check above.
    ->runInBackground();

// Monitoring: roll raw checks up into permanent daily totals, then prune those
// past the retention window - see App\Console\Commands\PruneWebsiteChecks.
Schedule::command('websites:prune-checks')->dailyAt('03:15')->withoutOverlapping(120);

// Audit: the only thing that removes a trail line, and it runs unattended - see
// App\Console\Commands\PruneAuditLogs for why there is no button for this.
Schedule::command('audit:prune')->dailyAt('03:30')->withoutOverlapping(120);

// Queue: failed jobs older than a week are noise; without this the table only grows.
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:45')->withoutOverlapping(120);
