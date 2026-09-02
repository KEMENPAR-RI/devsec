<?php

namespace App\Console\Commands;

use App\Jobs\CheckWebsiteJob;
use App\Models\Website;
use Illuminate\Console\Command;

class CheckWebsites extends Command
{
    protected $signature = 'websites:check {--now : Check every active website immediately, ignoring its interval}';

    protected $description = 'Probe every active website that is due for a check and record uptime/attack signals';

    public function handle(): int
    {
        $websites = Website::active()->get();
        $due = $this->option('now') ? $websites : $websites->filter->isDueForCheck();

        if ($due->isEmpty()) {
            $this->info('Tidak ada website yang perlu dicek saat ini.');

            return self::SUCCESS;
        }

        foreach ($due as $website) {
            // Mark "just dispatched" before the check even runs - see ScanJudol for
            // why (same class of bug: a slow/hanging probe could otherwise still be
            // "due" on the next tick and get queued a second time).
            $website->update(['last_checked_at' => now()]);
            CheckWebsiteJob::dispatch($website);
        }

        $this->info("Mengirim {$due->count()} pengecekan website ke antrean.");

        return self::SUCCESS;
    }
}
