<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a database backup. Driven both by the scheduler (see routes/console.php,
 * which only fires it when the schedule is switched on in Pengaturan) and by hand
 * from the "Backup Sekarang" button.
 */
class BackupDatabaseCommand extends Command
{
    protected $signature = 'db:backup';

    protected $description = 'Menulis dump database terkompresi (.sql.gz) ke storage/app/private/backups';

    public function handle(DatabaseBackup $backup): int
    {
        try {
            $filename = $backup->create();
        } catch (Throwable $e) {
            // Logged rather than thrown: a failed backup must not take the whole
            // scheduler run down with it.
            Log::error('Backup database gagal: '.$e->getMessage());
            $this->error('Backup gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup tersimpan: {$filename}");

        return self::SUCCESS;
    }
}
