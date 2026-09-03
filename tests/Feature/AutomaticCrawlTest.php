<?php

namespace Tests\Feature;

use App\Jobs\ScanWebsiteForJudolJob;
use App\Models\Website;
use App\Support\QueueHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The automatic crawl is dispatched as a queued job, so a deployment with cron but
 * no queue worker fails in the most confusing way available: the job is written to
 * the jobs table and never picked up, nothing errors, nothing logs - while every
 * check that runs inside a web request ("Cek Judol", "Cek URL Tertentu") keeps
 * working. It reads as a broken crawler rather than a missing worker.
 *
 * These cover the two answers to that: a --sync mode that needs no worker at all,
 * and a health check the UI uses to say what is wrong out loud.
 */
class AutomaticCrawlTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_scheduled_command_queues_a_crawl_for_a_due_website(): void
    {
        Queue::fake();

        Website::factory()->create(['is_active' => true, 'judol_scanned_at' => null]);

        $this->artisan('websites:scan-judol')->assertSuccessful();

        Queue::assertPushed(ScanWebsiteForJudolJob::class, 1);
    }

    public function test_sync_mode_runs_the_crawl_here_and_now_leaving_nothing_queued(): void
    {
        // Not Queue::fake(): a fake intercepts dispatch_sync too, so it would prove
        // nothing about whether the work actually ran. The real database queue is
        // used, and the jobs table is then asserted to be empty - which is the
        // property that matters on a server with no worker.
        config(['queue.default' => 'database']);

        $website = Website::factory()->create(['is_active' => true, 'judol_scanned_at' => null]);

        // No HTTP fake, so every fetch fails and the crawl finds nothing - fine here,
        // since what is under test is where the work runs, not what it finds.
        Http::fake(['*' => Http::response('', 500)]);

        $this->artisan('websites:scan-judol --sync')->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count(), 'nothing is left waiting for a worker');
        $this->assertNotNull($website->fresh()->judol_scanned_at, 'the crawl really ran');
    }

    public function test_a_website_not_yet_due_is_left_alone(): void
    {
        Queue::fake();

        Website::factory()->create([
            'is_active' => true,
            'judol_scan_interval_minutes' => 60,
            'judol_scanned_at' => now()->subMinutes(5),
        ]);

        $this->artisan('websites:scan-judol')->assertSuccessful();

        Queue::assertNothingPushed();
        // --now is the override for "scan everything, never mind the interval".
        $this->artisan('websites:scan-judol --now')->assertSuccessful();
        Queue::assertPushed(ScanWebsiteForJudolJob::class, 1);
    }

    public function test_an_idle_queue_is_not_reported_as_stalled(): void
    {
        config(['queue.default' => 'database']);

        $this->assertFalse(app(QueueHealth::class)->isStalled());
        $this->assertSame(0, app(QueueHealth::class)->pending());
    }

    public function test_a_job_a_worker_is_merely_busy_with_is_not_reported_as_stalled(): void
    {
        config(['queue.default' => 'database']);

        // A full crawl may legitimately take minutes; that must not raise the alarm.
        $this->queueJobWaitingFor(minutes: 5);

        $this->assertFalse(app(QueueHealth::class)->isStalled());
    }

    public function test_a_job_nobody_ever_picked_up_is_reported_as_stalled(): void
    {
        config(['queue.default' => 'database']);

        $this->queueJobWaitingFor(minutes: 45);

        $this->assertTrue(app(QueueHealth::class)->isStalled(), 'nothing is consuming the queue');
        $this->assertSame(1, app(QueueHealth::class)->pending());
        $this->assertSame(45, app(QueueHealth::class)->longestWaitMinutes());
    }

    public function test_a_driver_that_cannot_be_inspected_never_raises_a_false_alarm(): void
    {
        config(['queue.default' => 'redis']);

        $this->assertNull(app(QueueHealth::class)->pending());
        $this->assertFalse(app(QueueHealth::class)->isStalled());
    }

    protected function queueJobWaitingFor(int $minutes): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes($minutes)->timestamp,
            'created_at' => now()->subMinutes($minutes)->timestamp,
        ]);
    }
}
