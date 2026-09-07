<?php

namespace App\Console\Commands;

use App\Support\QueueHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clears monitoring jobs that piled up while nothing was consuming the queue.
 *
 * Every check and crawl is dispatched per tick, so a server that ran without a
 * worker accumulates thousands of them. Starting a worker against that backlog is
 * worse than useless: it would run days-old checks against every site at once,
 * hammering the very hosts this app is supposed to be gently watching, and report
 * an outage for whatever was down last week.
 *
 * Dropping them loses nothing. Both jobs re-dispatch on the next scheduler tick
 * with fresh state, so the correct backlog is always "none".
 */
class FlushStaleQueue extends Command
{
    protected $signature = 'queue:flush-stale
        {--failed : Also clear the failed_jobs table}';

    protected $description = 'Drop queued monitoring jobs that built up while no queue worker was running';

    public function handle(QueueHealth $health): int
    {
        if (! $health->isInspectable()) {
            $this->warn('Antrean tidak memakai driver database, tidak ada yang bisa dibersihkan dari sini.');

            return self::SUCCESS;
        }

        $pending = DB::table('jobs')->count();

        if ($pending === 0 && ! $this->option('failed')) {
            $this->info('Antrean sudah bersih.');

            return self::SUCCESS;
        }

        if ($pending > 0) {
            DB::table('jobs')->delete();
            $this->info("Menghapus {$pending} pekerjaan yang menumpuk di antrean.");
        }

        if ($this->option('failed')) {
            $failed = DB::table('failed_jobs')->count();
            DB::table('failed_jobs')->delete();
            $this->info("Menghapus {$failed} pekerjaan gagal.");
        }

        $this->line('Pemindaian berikutnya akan dijadwalkan ulang otomatis pada tick berikutnya.');

        return self::SUCCESS;
    }
}
