<?php

namespace App\Console\Commands;

use App\Jobs\ScanWebsiteForJudolJob;
use App\Models\Website;
use Illuminate\Console\Command;

class ScanJudol extends Command
{
    protected $signature = 'websites:scan-judol
        {--now : Scan every active website immediately, ignoring its interval}
        {--sync : Run each crawl in this process instead of queueing it}';

    protected $description = 'Crawl every active website that is due for a scan, for online-gambling (judol) spam content';

    public function handle(): int
    {
        $websites = Website::active()->get();
        $due = $this->option('now') ? $websites : $websites->filter->isDueForJudolScan();

        // Crawling a site that is currently down is pure waste - see
        // Website::isScannable(). --now overrides the interval, not this: an
        // unreachable site has nothing to crawl no matter who asked.
        [$due, $skipped] = $due->partition->isScannable();

        if ($skipped->isNotEmpty()) {
            $this->line("Melewati {$skipped->count()} website yang sedang tidak dapat diakses: ".$skipped->pluck('name')->implode(', ').'.');
        }

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

            $job = new ScanWebsiteForJudolJob($website);

            // --sync exists for a deployment with cron but no queue worker, which is
            // the normal shape of a shared-hosting or single-cron-line install. There,
            // a dispatched job is written to the jobs table and never picked up, so
            // the automatic crawl silently never happens while manual "Cek URL"
            // (which runs inside the web request) keeps working - a failure that
            // looks like the crawler being broken rather than absent.
            if ($this->option('sync')) {
                $this->line("Memindai {$website->name}...");
                dispatch_sync($job);

                continue;
            }

            dispatch($job);
        }

        if ($this->option('sync')) {
            $this->info("Selesai memindai {$due->count()} website.");

            return self::SUCCESS;
        }

        $this->info("Mengirim {$due->count()} pemindaian judol ke antrean.");

        return self::SUCCESS;
    }
}
