<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

/**
 * Drops audit lines older than the retention window.
 *
 * The only thing in the app allowed to remove an audit row, and it runs from the
 * scheduler - there is no button for it. Anyone who could clear the trail on
 * demand could clear the record of having done so.
 */
class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune
        {--days= : Override the retention window from config/audit.php}';

    protected $description = 'Delete audit log entries older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('audit.retention_days', 365));

        if ($days < 1) {
            $this->error('Masa simpan harus minimal 1 hari.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        // Chunked: a year of logs on a busy install is a large single DELETE, and
        // this runs alongside the per-minute monitoring schedule.
        $deleted = 0;

        do {
            $batch = AuditLog::query()->where('created_at', '<', $cutoff)->limit(1000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("{$deleted} catatan audit lebih tua dari {$days} hari dihapus.");

        return self::SUCCESS;
    }
}
