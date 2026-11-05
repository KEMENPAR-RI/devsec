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
    protected $signature = 'websites:check {--now : Check every active website immediately, ignoring its interval}';

    protected $description = 'Probe every active website that is due for a check and record uptime/attack signals';

    public function handle(): int
    {
        $websites = Website::active()->orderByDesc('is_priority')->orderBy('last_checked_at')->get();
        $due = $this->option('now') ? $websites : $websites->filter->isDueForCheck();

        if ($due->isEmpty()) {
            $this->info('Tidak ada website yang perlu dicek saat ini.');

            return self::SUCCESS;
        }

        $lock = new UniqueLock(app(Cache::class));
        $sent = 0;

        foreach ($due as $website) {
            $job = new CheckWebsiteJob($website);

            // Still queued/running from an earlier tick or "Cek Sekarang": leave its timestamp alone.
            if (! $lock->acquire($job)) {
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

        if ($sent < $due->count()) {
            $this->line('Melewati '.($due->count() - $sent).' website yang pengecekannya masih dalam antrean.');
        }

        return self::SUCCESS;
    }
}
