<?php

namespace App\Console\Commands;

use App\Jobs\CheckWebsiteJob;
use App\Models\Website;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Bus;
use Throwable;

class CheckWebsites extends Command
{
    protected $signature = 'websites:check
        {--now : Check every active website immediately, ignoring its interval}
        {--warning : Only websites in Warning (slow), for the sub-minute re-check}';

    protected $description = 'Probe every active website that is due for a check and record uptime/attack signals';

    public function handle(): int
    {
        $warningOnly = (bool) $this->option('warning');
        $websites = Website::active()
            // Warning tick: oldest-checked first, so a capped tick still rotates through every site.
            ->when($warningOnly, fn ($query) => $query->where('last_status', 'warning'), fn ($query) => $query
                // Normal tick skips Warning sites: only the capped --warning tick re-checks them (--now still checks all).
                ->when(! $this->option('now'), fn ($query) => $query->where('last_status', '!=', 'warning'))
                ->orderByDesc('is_priority'))
            ->orderBy('last_checked_at')
            ->get();
        // Each slow probe can hold the single worker ~35s: bound how many one tick adds.
        $cap = $warningOnly ? (int) config('monitoring.warning_recheck_max_per_tick', 5) : null;
        $due = $this->option('now') ? $websites : $websites->filter->isDueForCheck();

        if ($due->isEmpty()) {
            $this->info('Tidak ada website yang perlu dicek saat ini.');

            return self::SUCCESS;
        }

        $lock = new UniqueLock(app(Cache::class));
        $sent = 0;
        $skipped = 0;

        foreach ($due as $website) {
            if ($cap !== null && $sent >= $cap) {
                break;
            }

            $job = new CheckWebsiteJob($website);

            // Slow sites wait behind normal checks and no-response retries.
            if ($website->last_status === 'warning') {
                $job->onQueue(config('monitoring.queues.checks_low'));
            }

            // Still queued/running from an earlier tick or "Cek Sekarang": leave its timestamp alone.
            if (! $lock->acquire($job)) {
                $skipped++;

                continue;
            }

            // Mark "just dispatched" before the check even runs: a slow/hanging probe
            // could otherwise still be "due" on the next tick and get queued a second time.
            try {
                $website->update(['last_checked_at' => now()]);
                Bus::dispatch($job);
            } catch (Throwable $e) {
                // Otherwise the site stays "already queued" until uniqueFor expires.
                $lock->release($job);

                throw $e;
            }

            $sent++;
        }

        $this->info("Mengirim {$sent} pengecekan website ke antrean.");

        if ($skipped > 0) {
            $this->line("Melewati {$skipped} website yang pengecekannya masih dalam antrean.");
        }

        return self::SUCCESS;
    }
}
