<?php

namespace App\Services\Backup;

use App\Models\Setting;
use App\Services\AppSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Writes a restorable .sql dump of the application database, stored gzipped as a
 * .tar.gz - the dump is plain text and compresses by roughly 90%, which is the
 * difference between keeping five of them on a small VPS and not.
 *
 * Deliberately pure PHP over PDO rather than shelling out to mysqldump: this app
 * runs on Windows during development and on shared government hosting in
 * production, and on neither can mysqldump be assumed to exist on PATH. Slower,
 * but a backup that never runs is worth nothing.
 *
 * Dumps live on the "local" disk (storage/app/private/backups) - outside the
 * public root, because a database dump contains every user's password hash and
 * every stored credential. They are downloaded through an authorized route, never
 * served as a static file.
 *
 * Restore: `tar -xzf backup-....tar.gz` then `mysql -u user -p db < backup-....sql`.
 */
class DatabaseBackup
{
    public const DISK = 'local';

    public const DIRECTORY = 'backups';

    public const EXTENSION = '.tar.gz';

    /**
     * Extensions listed by all(): the current one, plus the plain .sql this app
     * wrote before compression existed. Old dumps stay downloadable and, just as
     * importantly, stay prunable - otherwise they would sit on disk forever.
     */
    protected const LISTED_EXTENSIONS = [self::EXTENSION, '.sql'];

    /**
     * Hard ceiling on stored dumps. Backups are big and grow with the data; keeping
     * an unbounded pile of them is how a monitoring box runs out of disk and stops
     * monitoring. Five is enough history to fall back through without that risk.
     */
    public const MAX_KEEP = 5;

    /** Rows read per round trip, so a big website_checks table can't exhaust memory. */
    protected const CHUNK = 500;

    /**
     * @return string the filename that was written
     */
    public function create(): string
    {
        $base = 'backup-'.config('database.connections.'.config('database.default').'.database', 'db')
            .'-'.Carbon::now()->format('Y-m-d_His');

        $filename = $base.self::EXTENSION;

        $sql = $this->dump();
        $archive = $this->archive($base.'.sql', $sql);
        // Released before gzipping: the dump, the tar and the gzip output would
        // otherwise all be held at once, and the dump is the big one.
        unset($sql);

        Storage::disk(self::DISK)->put(self::DIRECTORY.'/'.$filename, gzencode($archive, 6));

        $this->prune();

        Setting::set('backup_last_run_at', Carbon::now()->toIso8601String());

        return $filename;
    }

    /**
     * Every stored dump, newest first.
     *
     * @return Collection<int, array{name: string, size: int, created_at: CarbonInterface}>
     */
    public function all(): Collection
    {
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists(self::DIRECTORY)) {
            return collect();
        }

        return collect($disk->files(self::DIRECTORY))
            ->filter(fn (string $path) => Str::endsWith($path, self::LISTED_EXTENSIONS))
            ->map(fn (string $path) => [
                'name' => basename($path),
                'size' => $disk->size($path),
                'created_at' => Carbon::createFromTimestamp($disk->lastModified($path)),
            ])
            ->sortByDesc('created_at')
            ->values();
    }

    public function delete(string $filename): void
    {
        Storage::disk(self::DISK)->delete(self::DIRECTORY.'/'.$this->safeName($filename));
    }

    /** Absolute path for a download response; null when the file isn't there. */
    public function pathFor(string $filename): ?string
    {
        $path = self::DIRECTORY.'/'.$this->safeName($filename);
        $disk = Storage::disk(self::DISK);

        return $disk->exists($path) ? $disk->path($path) : null;
    }

    /** Keeps only the newest N dumps, so backups can't fill the disk unattended. */
    public function prune(): int
    {
        $stale = $this->all()->slice($this->keep());

        $stale->each(fn (array $backup) => $this->delete($backup['name']));

        return $stale->count();
    }

    public function lastRunAt(): ?CarbonInterface
    {
        $stored = Setting::get('backup_last_run_at');

        return $stored ? Carbon::parse($stored) : null;
    }

    public function scheduleEnabled(): bool
    {
        return Setting::get('backup_schedule_enabled') === '1';
    }

    /** 'daily' | 'weekly' | 'monthly' */
    public function frequency(): string
    {
        $stored = (string) Setting::get('backup_frequency');

        return in_array($stored, ['daily', 'weekly', 'monthly'], true) ? $stored : 'daily';
    }

    /** "HH:MM" in the app's timezone. */
    public function scheduleTime(): string
    {
        $stored = (string) Setting::get('backup_time');

        return preg_match('/^\d{2}:\d{2}$/', $stored) ? $stored : '02:00';
    }

    /**
     * Whether the scheduler should fire a backup on this tick.
     *
     * Evaluated once a minute rather than baked into a cron expression at boot:
     * the frequency and time live in the database, and reading them while routes
     * are being registered would hit the settings table before migrations have
     * created it (fresh install, and every test run). Any failure to read means
     * "not due" - a missed backup is recoverable, a boot that throws is not.
     */
    public function isDue(?CarbonInterface $now = null): bool
    {
        try {
            if (! $this->scheduleEnabled()) {
                return false;
            }

            $now ??= Carbon::now();
            $now = Carbon::parse($now)->timezone(app(AppSettings::class)->timezone());

            if ($now->format('H:i') !== $this->scheduleTime()) {
                return false;
            }

            return match ($this->frequency()) {
                'weekly' => $now->isMonday(),
                'monthly' => $now->day === 1,
                default => true,
            };
        } catch (Throwable) {
            return false;
        }
    }

    /** Clamped to MAX_KEEP, so a stale stored value can never lift the ceiling. */
    public function keep(): int
    {
        return min(self::MAX_KEEP, max(1, (int) (Setting::get('backup_keep') ?: self::MAX_KEEP)));
    }

    /**
     * The dump itself: schema then data, table by table.
     *
     * Foreign key checks are switched off around the whole file so the restore
     * doesn't depend on getting the table order right.
     */
    protected function dump(): string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver !== 'mysql') {
            throw new RuntimeException("Backup otomatis baru mendukung MySQL/MariaDB, bukan '{$driver}'.");
        }

        $sql = '-- Backup '.config('app.name').' - '.Carbon::now()->toDateTimeString()."\n"
            .'-- Database: '.$connection->getDatabaseName()."\n\n"
            ."SET FOREIGN_KEY_CHECKS=0;\n"
            ."SET NAMES utf8mb4;\n\n";

        foreach ($this->tables($connection) as $table) {
            $create = (array) $connection->selectOne("SHOW CREATE TABLE `{$table}`");
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n".end($create).";\n\n";
            $sql .= $this->rows($connection, $table);
        }

        return $sql."SET FOREIGN_KEY_CHECKS=1;\n";
    }

    /**
     * @return array<int, string>
     */
    protected function tables($connection): array
    {
        return collect($connection->select('SHOW TABLES'))
            ->map(fn ($row) => array_values((array) $row)[0])
            ->all();
    }

    protected function rows($connection, string $table): string
    {
        $sql = '';
        $columns = null;

        $connection->table($table)->orderByRaw('1')->chunk(self::CHUNK, function ($rows) use (&$sql, &$columns, $connection, $table) {
            $columns ??= '`'.implode('`, `', array_keys((array) $rows->first())).'`';

            $values = $rows->map(function ($row) use ($connection) {
                $cells = collect((array) $row)->map(fn ($value) => match (true) {
                    $value === null => 'NULL',
                    is_bool($value) => $value ? '1' : '0',
                    is_int($value), is_float($value) => (string) $value,
                    default => $connection->getPdo()->quote((string) $value),
                });

                return '('.$cells->implode(', ').')';
            })->implode(",\n");

            $sql .= "INSERT INTO `{$table}` ({$columns}) VALUES\n{$values};\n";
        });

        return $sql === '' ? '' : $sql."\n";
    }

    /**
     * Wraps the dump in a single-entry USTAR archive, ready to be gzipped.
     *
     * Written by hand rather than through PharData for the same reason the dump
     * itself avoids mysqldump: ext-phar is not guaranteed on shared hosting, and a
     * tar header is 512 bytes of a format that has not changed since 1988.
     */
    protected function archive(string $name, string $contents): string
    {
        $header = pack(
            'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
            $name,
            "0000644\0",                              // mode
            "0000000\0",                              // uid
            "0000000\0",                              // gid
            sprintf('%011o', strlen($contents))."\0",  // size, octal
            sprintf('%011o', Carbon::now()->timestamp)."\0",
            '        ',                                // checksum, blanked while summing
            '0',                                       // type: regular file
            '',                                        // linkname
            "ustar\0",
            '00',
            '', '', '', '', '', ''
        );

        $checksum = 0;

        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }

        $header = substr_replace($header, sprintf('%06o', $checksum)."\0 ", 148, 8);

        // Entries are padded to a 512-byte boundary, and the archive ends with two
        // empty blocks - tar treats anything else as truncated.
        $padding = str_repeat("\0", (512 - strlen($contents) % 512) % 512);

        return $header.$contents.$padding.str_repeat("\0", 1024);
    }

    /** Strips any path from a user-supplied filename - no traversal out of the folder. */
    protected function safeName(string $filename): string
    {
        return basename(str_replace('\\', '/', $filename));
    }
}
