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

    // Must clear the checker's own worst case, not match it: a probe is up to
    // 20s, and WebsiteChecker retries once and may then repeat the whole thing
    // without SSL verification. At 20s this job was killed mid-probe on any slow
    // site - taking the worker process down with it (queue:work enforces the
    // timeout with SIGKILL), which is what drove the restart loop.
    public int $timeout = 90;

    public function __construct(public Website $website) {}

    public function handle(WebsiteChecker $checker): void
    {
        $checker->check($this->website);
    }
}
