<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Whether anything is actually consuming the queue.
 *
 * Every automatic scan and website check runs as a queued job, so a deployment
 * with no queue worker fails in the most confusing way possible: nothing errors,
 * no log line appears, and the parts that run inside a web request ("Cek URL
 * Tertentu", "Cek Judol") keep working perfectly - so it reads as "the crawler is
 * broken" rather than "no worker is running". Jobs just accumulate in the jobs
 * table forever.
 *
 * This turns that silence into something the UI can say out loud. Only the
 * database queue driver can be inspected this way; on any other driver the
 * answers are simply "unknown", never a false alarm.
 */
class QueueHealth
{
    /**
     * A job still waiting after this long means nothing is picking it up. Well
     * above a full crawl's 300s timeout, so a worker that is merely busy with a
     * long scan is not mistaken for a worker that does not exist.
     */
    protected const STALE_MINUTES = 15;

    public function isInspectable(): bool
    {
        return config('queue.default') === 'database';
    }

    /** Jobs waiting to be picked up, or null when the driver cannot be inspected. */
    public function pending(): ?int
    {
        if (! $this->isInspectable()) {
            return null;
        }

        try {
            return DB::table('jobs')->count();
        } catch (Throwable) {
            // No jobs table (a fresh install mid-migration) - nothing to report.
            return null;
        }
    }

    /**
     * Minutes the longest-waiting job has been sitting there, or null when the
     * queue is empty or cannot be inspected.
     */
    public function longestWaitMinutes(): ?int
    {
        if (! $this->isInspectable()) {
            return null;
        }

        try {
            $oldest = DB::table('jobs')->min('available_at');
        } catch (Throwable) {
            return null;
        }

        if ($oldest === null) {
            return null;
        }

        return (int) floor(max(0, now()->timestamp - (int) $oldest) / 60);
    }

    /**
     * True when work is queued and demonstrably not being consumed - the signal
     * that `php artisan queue:work` is missing from the server.
     */
    public function isStalled(): bool
    {
        return ($this->longestWaitMinutes() ?? 0) >= self::STALE_MINUTES;
    }
}
