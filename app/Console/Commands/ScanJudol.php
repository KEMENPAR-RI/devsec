<?php

namespace App\Console\Commands;

use App\Jobs\ScanWebsiteForJudolJob;
use App\Models\Website;
use Illuminate\Console\Command;

class ScanJudol extends Command
{
    protected $signature = 'websites:scan-judol {--now : Scan every active website immediately, ignoring its interval}';

    protected $description = 'Crawl every active website that is due for a scan, for online-gambling (judol) spam content';

    public function handle(): int
    {
        $websites = Website::active()->get();
        $due = $this->option('now') ? $websites : $websites->filter->isDueForJudolScan();

        if ($due->isEmpty()) {
            $this->info('Tidak ada website yang perlu dipindai saat ini.');

            return self::SUCCESS;
        }

        foreach ($due as $website) {
            // Mark "just dispatched" *before* the crawl even starts, not after it
            // finishes: a full crawl can take minutes, so if the timestamp only
            // moved on completion, isDueForJudolScan() would still see this website
            // as due on every tick in between and queue a duplicate scan for it
            // while the first one is still in flight.
            $website->update(['judol_scanned_at' => now()]);
            ScanWebsiteForJudolJob::dispatch($website);
        }

        $this->info("Mengirim {$due->count()} pemindaian judol ke antrean.");

        return self::SUCCESS;
    }
}
