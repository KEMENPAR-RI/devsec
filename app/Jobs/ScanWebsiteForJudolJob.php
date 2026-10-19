<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScanWebsiteForJudolJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Full-site crawl (homepage + sitemap + followed links) can take a while.
    public int $timeout = 300;

    // One pending/running crawl per site, even when the backlog outlasts the interval.
    public int $uniqueFor = 7200;

    public function __construct(public Website $website)
    {
        $this->onQueue(config('monitoring.queues.scans'));
    }

    public function uniqueId(): string
    {
        return (string) $this->website->id;
    }

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
