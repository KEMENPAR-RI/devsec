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

    public int $timeout = 20;

    public function __construct(public Website $website) {}

    public function handle(WebsiteChecker $checker): void
    {
        $checker->check($this->website);
    }
}
