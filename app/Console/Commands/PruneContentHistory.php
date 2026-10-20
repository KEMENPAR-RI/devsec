<?php

namespace App\Console\Commands;

use App\Services\Monitoring\PageContentMonitor;
use Illuminate\Console\Command;

/**
 * Drops content-change events, stale page snapshots and closed defacement
 * findings older than the retention window.
 */
class PruneContentHistory extends Command
{
    protected $signature = 'websites:prune-content
        {--days= : Override the retention window from config/monitoring.php}';

    protected $description = 'Delete content-change history, stale page snapshots, and closed defacement findings older than the retention window';

    public function handle(PageContentMonitor $monitor): int
    {
        // Only an absent option falls back to config; "0" or garbage must be refused, not replaced.
        $option = $this->option('days');
        $days = $option === null
            ? (int) config('monitoring.content_retention_days', 90)
            : filter_var($option, FILTER_VALIDATE_INT);

        if ($days === false || $days < 1) {
            $this->error('Masa simpan harus minimal 1 hari.');

            return self::FAILURE;
        }

        if ($days > 3650) {
            $this->error('Masa simpan maksimal 3650 hari.');

            return self::FAILURE;
        }

        $deleted = $monitor->pruneExpired($days);

        $this->info("{$deleted['changes']} riwayat perubahan, {$deleted['snapshots']} snapshot halaman, dan {$deleted['findings']} temuan defacement tertutup lebih tua dari {$days} hari dihapus.");

        return self::SUCCESS;
    }
}
