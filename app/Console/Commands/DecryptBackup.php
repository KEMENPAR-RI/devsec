<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseBackup;
use Illuminate\Console\Command;
use Throwable;

/**
 * Writes a decrypted copy of a stored dump, for restoring straight on the server
 * without going through the browser.
 *
 * The UI download already decrypts, but a restore usually happens over SSH on a
 * box that is having a bad day - and needing a working web session to get at the
 * backup is a poor thing to discover at that moment.
 */
class DecryptBackup extends Command
{
    protected $signature = 'backup:decrypt
        {filename : Nama berkas backup, mis. backup-db-2026-09-15_020000.sql.gz.enc}
        {--output= : Tujuan penulisan (default: nama berkas tanpa .enc di direktori kerja)}';

    protected $description = 'Write a decrypted copy of an encrypted database backup';

    public function handle(DatabaseBackup $backup): int
    {
        try {
            $contents = $backup->contents($this->argument('filename'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($contents === null) {
            $this->error("Backup {$this->argument('filename')} tidak ditemukan.");

            return self::FAILURE;
        }

        $output = $this->option('output') ?: $backup->downloadName($this->argument('filename'));

        if (file_put_contents($output, $contents) === false) {
            $this->error("Gagal menulis ke {$output}.");

            return self::FAILURE;
        }

        $this->info("Backup didekripsi ke {$output}.");
        $this->line('Restore: gunzip -c '.$output.' | mysql -u pengguna -p nama_database');

        return self::SUCCESS;
    }
}
