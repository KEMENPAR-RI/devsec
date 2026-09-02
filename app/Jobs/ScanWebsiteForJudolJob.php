<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScanWebsiteForJudolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Full-site crawl (homepage + sitemap + followed links) can take a while.
    public int $timeout = 300;

    public function __construct(public Website $website) {}

    public function handle(JudolScanner $scanner): void
    {
        $scanner->scan($this->website);
    }
}
