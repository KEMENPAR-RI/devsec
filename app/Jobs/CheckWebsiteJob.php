<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\Monitoring\WebsiteChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckWebsiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Must clear the checker's own worst case, not match it. WebsiteChecker makes
    // two 20s attempts with backoff, and on a certificate error repeats that whole
    // sequence without SSL verification: ~80s before it gives up on its own.
    //
    // This was 20s, i.e. below its own probe. queue:work enforces a job timeout by
    // SIGKILLing the worker, so every slow site took the process down with it and
    // systemd restarted it into the same job - the restart loop that showed up as
    // status=9/KILL in the journal. Stays under DB_QUEUE_RETRY_AFTER (320s), or a
    // slow check would be released and run again while the first is still going.
    public int $timeout = 120;

    public function __construct(public Website $website) {}

    public function handle(WebsiteChecker $checker): void
    {
        $checker->check($this->website);
    }
}
