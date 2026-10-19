<?php

namespace App\Console\Commands;

use App\Jobs\ScanWebsiteForJudolJob;
use App\Models\Website;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Throwable;

class ScanJudol extends Command
{
    protected $signature = 'websites:scan-judol
        {--now : Scan every active website immediately, ignoring its interval}
        {--sync : Run each crawl in this process instead of queueing it}';

    protected $description = 'Crawl every active website that is due for a scan, for online-gambling (judol) spam content';

    public function handle(): int
    {
        // Priority sites first, then the longest-unscanned (never scanned sorts first).
        $websites = Website::active()->orderByDesc('is_priority')->orderBy('judol_scanned_at')->get();
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

        $budget = $this->dispatchBudget();

        if ($budget <= 0) {
            return self::SUCCESS;
        }

        $lock = new UniqueLock(app(Cache::class));
        $sent = 0;

        foreach ($due as $website) {
            if ($sent >= $budget) {
                break;
            }

            $job = new ScanWebsiteForJudolJob($website);

            // A crawl for this site is still queued/running: no duplicate, no new timestamp.
            if (! $lock->acquire($job)) {
                continue;
            }

            // Mark "just dispatched" *before* the crawl even starts, not after it
            // finishes: a full crawl can take minutes, so if the timestamp only
            // moved on completion, isDueForJudolScan() would still see this website
            // as due on every tick in between and queue a duplicate scan for it
            // while the first one is still in flight.
            try {
                $website->update(['judol_scanned_at' => now()]);
            } catch (Throwable $e) {
                // Otherwise the site stays "already queued" until uniqueFor expires.
                $lock->release($job);

                throw $e;
            }

            $sent++;

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

            // Lock already held above; Bus::dispatch does not try to take it again.
            try {
                Bus::dispatch($job);
            } catch (Throwable $e) {
                $lock->release($job);

                throw $e;
            }
        }

        if ($this->option('sync')) {
            $this->info("Selesai memindai {$sent} website.");

            return self::SUCCESS;
        }

        $this->info("Mengirim {$sent} pemindaian judol ke antrean.");

        return self::SUCCESS;
    }

    /**
     * How many crawls this tick may queue. --now and --sync are explicit operator
     * runs and are not braked; the scheduled tick stops at the per-tick cap and
     * never grows the scans queue past judol.max_queue.
     */
    protected function dispatchBudget(): int
    {
        if ($this->option('now') || $this->option('sync')) {
            return PHP_INT_MAX;
        }

        $queue = config('monitoring.queues.scans');
        $backlog = Queue::size($queue);
        $room = (int) config('judol.max_queue', 20) - $backlog;

        if ($room <= 0) {
            $this->info("Antrean {$queue} masih berisi {$backlog} pemindaian, menunggu tick berikutnya.");

            return 0;
        }

        return min((int) config('judol.max_dispatch_per_tick', 5), $room);
    }
}
