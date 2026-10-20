<?php

namespace App\Services\Monitoring;

use App\Models\PageSnapshot;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The last raw HTML body of each crawled page, gzipped on the private `local`
 * disk (ADR 0001). A file is trusted only when it hashes to the snapshot's
 * raw_sha256 - anything else reads as "not available", never as a guess.
 *
 * Paths come only from the snapshot's integer website_id and hex url_hash,
 * never from a URL. Never expose these through temporaryUrl().
 */
class PageRawStore
{
    /** Whether the body was stored; false when it is over the size limits. */
    public function put(PageSnapshot $snapshot, string $body): bool
    {
        if (strlen($body) > (int) config('monitoring.forensics_max_html_bytes', 2097152)) {
            return false;
        }

        $gz = gzencode($body, 6);

        if ($gz === false || strlen($gz) > (int) config('monitoring.forensics_max_raw_gz_bytes', 1048576)) {
            return false;
        }

        $path = $this->path($snapshot);
        $tmp = $path.'.'.Str::random(8).'.tmp';

        // Written aside then renamed, so a reader never sees half a file.
        if (! $this->disk()->put($tmp, $gz) || ! $this->disk()->move($tmp, $path)) {
            $this->disk()->delete($tmp);

            return false;
        }

        return true;
    }

    /**
     * The stored body when it matches raw_sha256, else null with $reason set to
     * no_baseline | too_large | missing | mismatch.
     */
    public function get(PageSnapshot $snapshot, ?string &$reason = null): ?string
    {
        $reason = null;

        if (blank($snapshot->raw_sha256)) {
            $reason = 'no_baseline';

            return null;
        }

        if (! $snapshot->raw_stored) {
            $reason = (int) $snapshot->raw_bytes > (int) config('monitoring.forensics_max_html_bytes', 2097152) ? 'too_large' : 'missing';

            return null;
        }

        $gz = $this->disk()->get($this->path($snapshot));

        if ($gz === null) {
            $reason = 'missing';

            return null;
        }

        // Bounded inflate: a tampered file cannot balloon past the HTML limit.
        $body = @gzdecode($gz, (int) config('monitoring.forensics_max_html_bytes', 2097152) + 1);

        if ($body === false || ! hash_equals((string) $snapshot->raw_sha256, hash('sha256', $body))) {
            $reason = 'mismatch';

            return null;
        }

        return $body;
    }

    public function exists(PageSnapshot $snapshot): bool
    {
        return $this->disk()->exists($this->path($snapshot));
    }

    public function delete(PageSnapshot $snapshot): void
    {
        $this->disk()->delete($this->path($snapshot));
    }

    /**
     * Removes files no snapshot vouches for: deleted sites/snapshots, snapshots
     * no longer storing a body, and temp files. Anything younger than an hour is
     * left alone - a crawl running now may have written it before its snapshot row.
     */
    public function sweepOrphans(): int
    {
        $root = $this->root();
        $deleted = 0;
        $cutoff = now()->subHour()->getTimestamp();

        foreach ($this->disk()->directories($root) as $directory) {
            $websiteId = basename($directory);

            if (! ctype_digit($websiteId)) {
                continue;
            }

            $stored = DB::table('page_snapshots')
                ->where('website_id', (int) $websiteId)
                ->where('raw_stored', true)
                ->pluck('url_hash')
                ->flip();

            foreach ($this->disk()->files($directory) as $file) {
                $name = basename($file);

                $orphan = preg_match('/^([0-9a-f]{40})\.html\.gz$/', $name, $m)
                    ? ! isset($stored[$m[1]])
                    : (bool) preg_match('/^[0-9a-f]{40}\.html\.gz\.[A-Za-z0-9]{8}\.tmp$/', $name);

                if ($orphan && $this->disk()->lastModified($file) < $cutoff && $this->disk()->delete($file)) {
                    $deleted++;
                }
            }

            if ($this->disk()->files($directory) === [] && $this->disk()->directories($directory) === []) {
                $this->disk()->deleteDirectory($directory);
            }
        }

        return $deleted;
    }

    public function path(PageSnapshot $snapshot): string
    {
        $websiteId = (int) $snapshot->website_id;
        $urlHash = (string) $snapshot->url_hash;

        if ($websiteId < 1 || ! preg_match('/^[0-9a-f]{40}$/', $urlHash)) {
            throw new InvalidArgumentException('Invalid snapshot identity for raw body path.');
        }

        return $this->root().'/'.$websiteId.'/'.$urlHash.'.html.gz';
    }

    protected function root(): string
    {
        $root = trim((string) config('monitoring.forensics_raw_path', 'page-bodies'), '/');

        return preg_match('/^[A-Za-z0-9_-]+$/', $root) ? $root : 'page-bodies';
    }

    protected function disk(): Filesystem
    {
        return Storage::disk('local');
    }
}
