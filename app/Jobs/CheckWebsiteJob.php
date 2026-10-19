<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\Monitoring\WebsiteChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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

    public function __construct(public Website $website)
    {
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
        $checker->check($this->website);
    }
}
