<?php

use App\Services\Backup\DatabaseBackup;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Monitoring: probe every active website for down/attack signals every minute
// (each website only actually checked once its own interval has elapsed).
Schedule::command('websites:check')->everyMinute()->withoutOverlapping();

// Monitoring: crawl every active website for judol (online gambling) spam content.
Schedule::command('websites:scan-judol')->everyMinute()->withoutOverlapping();

// Backup: ticks every minute and asks the service whether this is the scheduled
// moment. The frequency and time live in the database, so they can't be baked into
// a cron expression here - reading them at registration would hit the settings
// table before migrations have created it (fresh install, and every test run).
Schedule::command('db:backup')
    ->everyMinute()
    ->when(fn () => app(DatabaseBackup::class)->isDue())
    ->withoutOverlapping();
