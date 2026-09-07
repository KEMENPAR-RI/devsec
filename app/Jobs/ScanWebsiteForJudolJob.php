<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScanWebsiteForJudolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Full-site crawl (homepage + sitemap + followed links) can take a while.
    public int $timeout = 300;

    public function __construct(public Website $website) {}

    public function handle(JudolScanner $scanner): void
    {
        // Re-check on arrival rather than trusting the dispatch-time verdict: a
        // queued scan can sit behind a long crawl for minutes, and the site may
        // have gone down in between. SerializesModels reloads the record from the
        // database when the job is unserialized, so last_status here is current.
        if (! $this->website->isScannable()) {
            Log::info("ScanWebsiteForJudolJob: melewati {$this->website->url} karena statusnya '{$this->website->last_status}', bukan 'up'.");

            return;
        }

        $scanner->scan($this->website);
    }
}
