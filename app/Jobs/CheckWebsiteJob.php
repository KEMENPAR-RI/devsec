<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\Monitoring\MonitorConnectivity;
use App\Services\Monitoring\WebsiteChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;

class CheckWebsiteJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Must clear the checker's own worst case, not match it. WebsiteChecker caps
    // retries and the SSL fallback with one deadline (timeout + 5s): ~35s at most.
    //
    // This was 20s, i.e. below its own probe. queue:work enforces a job timeout by
    // SIGKILLing the worker, so every slow site took the process down with it and
    // systemd restarted it into the same job - the restart loop that showed up as
    // status=9/KILL in the journal. Stays under DB_QUEUE_RETRY_AFTER (320s), or a
    // slow check would be released and run again while the first is still going.
    public int $timeout = 150;

    // One pending/running check per site: a scheduled run plus "Cek Sekarang" must
    // not count the same outage twice toward down_threshold_failures. Outlives a
    // backlog wait so a still-queued check is not duplicated.
    public int $uniqueFor = 900;

    // Attempts = 1 + no-response retries (release()); an exception still fails the job once.
    public int $tries = 1;

    public int $maxExceptions = 1;

    public function __construct(public Website $website)
    {
        $this->tries = $this->retries() + 1;

        // Set here so every dispatch site routes the same way.
        $this->onQueue($website->is_priority
            ? config('monitoring.queues.checks_high')
            : config('monitoring.queues.checks'));
    }

    public function uniqueId(): string
    {
        return (string) $this->website->id;
    }

    public function handle(WebsiteChecker $checker): void
    {
        // The sync driver (and a direct handle() call) cannot delay a retry: run as final.
        // A monitor already known offline gains nothing from retrying: the check records nothing.
        $final = ! $this->job
            || $this->job instanceof SyncJob
            || $this->attempts() > $this->retries()
            || app(MonitorConnectivity::class)->knownOffline();

        // Released, not slept: the worker keeps checking other sites meanwhile, and
        // the unique lock stays held so no duplicate check is queued in between.
        if ($checker->check($this->website, $final, retried: $this->job && $this->attempts() > 1) === null && ! $final) {
            $this->release($this->retryDelay());
        }
    }

    protected function retries(): int
    {
        return filter_var(config('monitoring.no_response.retries'), FILTER_VALIDATE_INT, [
            'options' => ['default' => 2, 'min_range' => 0, 'max_range' => 2],
        ]);
    }

    protected function retryDelay(): int
    {
        return filter_var(config('monitoring.no_response.retry_delay_seconds'), FILTER_VALIDATE_INT, [
            'options' => ['default' => 8, 'min_range' => 5, 'max_range' => 10],
        ]);
    }
}
