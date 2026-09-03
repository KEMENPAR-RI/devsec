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
 * .sql.gz - the dump is plain text and compresses by roughly 90%, which is the
 * difference between keeping five of them on a small VPS and not.
 *
 * Plain gzip rather than a tar: a single-file archive gains nothing from a tar
 * container, and .sql.gz is the form both phpMyAdmin and the mysql client import
 * without unpacking it first.
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
 * Restore: `gunzip -c backup-....sql.gz | mysql -u user -p db`, or hand the file
 * to phpMyAdmin's import, which unpacks .sql.gz itself.
 */
class DatabaseBackup
{
    public const DISK = 'local';

    public const DIRECTORY = 'backups';

    public const EXTENSION = '.sql.gz';

    /**
     * Extensions listed by all(): the current one, plus the shapes earlier releases
     * wrote - uncompressed .sql, and briefly .tar.gz. Old dumps stay downloadable
     * and, just as importantly, stay prunable: a file this list forgets is a file
     * that sits on the disk forever.
     */
    protected const LISTED_EXTENSIONS = [self::EXTENSION, '.sql', '.tar.gz'];

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
        // The database name comes from config and lands in a filename, so anything
        // a path could choke on (a colon, a slash) is flattened first.
        $database = preg_replace('/[^A-Za-z0-9_.-]+/', '-',
            (string) config('database.connections.'.config('database.default').'.database', 'db'));

        $base = 'backup-'.trim($database, '-').'-'.Carbon::now()->format('Y-m-d_His');

        $filename = $base.self::EXTENSION;

        $sql = $this->dump();
        $gzipped = gzencode($sql, 6);
        // Released before the write: the plain dump is the big one, and nothing
        // downstream needs it any more.
        unset($sql);

        Storage::disk(self::DISK)->put(self::DIRECTORY.'/'.$filename, $gzipped);

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

    /** Strips any path from a user-supplied filename - no traversal out of the folder. */
    protected function safeName(string $filename): string
    {
        return basename(str_replace('\\', '/', $filename));
    }
}
