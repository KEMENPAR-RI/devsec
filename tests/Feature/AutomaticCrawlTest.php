<?php

namespace Tests\Feature;

use App\Jobs\ScanWebsiteForJudolJob;
use App\Models\Website;
use App\Services\Monitoring\JudolScanner;
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

        Website::factory()->create(['is_active' => true, 'judol_scanned_at' => null, 'last_status' => 'up']);

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

        $website = Website::factory()->create(['is_active' => true, 'judol_scanned_at' => null, 'last_status' => 'up']);

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
            'last_status' => 'up',
        ]);

        $this->artisan('websites:scan-judol')->assertSuccessful();

        Queue::assertNothingPushed();
        // --now is the override for "scan everything, never mind the interval".
        $this->artisan('websites:scan-judol --now')->assertSuccessful();
        Queue::assertPushed(ScanWebsiteForJudolJob::class, 1);
    }

    public function test_a_website_that_is_down_is_not_crawled(): void
    {
        Queue::fake();

        Website::factory()->create([
            'is_active' => true,
            'judol_scanned_at' => null,
            'last_status' => 'down',
        ]);

        $this->artisan('websites:scan-judol')->assertSuccessful();

        // Every fetch a crawl of an unreachable host makes is going to fail exactly
        // the way the uptime probe already did, so the job would spend its whole
        // timeout budget - and take the worker down with it - to learn nothing.
        Queue::assertNothingPushed();

        // --now overrides the scan interval, not reachability.
        $this->artisan('websites:scan-judol --now')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_a_website_never_checked_yet_is_not_crawled(): void
    {
        Queue::fake();

        // 'unknown' means no uptime probe has landed yet - typically a site added
        // moments ago, possibly with a typo'd URL. websites:check runs every minute,
        // so waiting for that verdict costs almost nothing.
        Website::factory()->create([
            'is_active' => true,
            'judol_scanned_at' => null,
            'last_status' => 'unknown',
        ]);

        $this->artisan('websites:scan-judol')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_queued_crawl_is_abandoned_if_the_site_went_down_before_it_ran(): void
    {
        $website = Website::factory()->create(['is_active' => true, 'last_status' => 'down']);

        Http::fake();

        // The job re-reads status on arrival: a scan can sit behind a long crawl for
        // minutes, and dispatch-time reachability says nothing about now.
        (new ScanWebsiteForJudolJob($website))->handle(app(JudolScanner::class));

        Http::assertNothingSent();
    }

    public function test_a_crawl_stops_when_the_homepage_cannot_be_fetched(): void
    {
        $website = Website::factory()->create(['is_active' => true, 'last_status' => 'up']);

        Http::fake(['*' => Http::response('', 500)]);

        app(JudolScanner::class)->scan($website);

        // Homepage first, and nothing after it: no sitemap fetch, no link following.
        // This is the backstop for callers that bypass the last_status gate - the
        // manual "Cek Judol" button scans whatever it is pointed at, on demand.
        $this->assertLessThanOrEqual(
            2,
            count(Http::recorded()),
            'a dead homepage must not fan out into a full max_pages crawl'
        );
    }

    public function test_a_crawl_stops_at_its_time_budget_instead_of_being_killed(): void
    {
        // Regression: max_pages bounds the page count, not the duration, and the
        // queue enforces duration. On a slow host 60 pages ran past the job's 300s
        // timeout, queue:work SIGKILLed the worker mid-fetch, systemd restarted it
        // onto the same site, and it died the same way - the status=9/KILL loop.
        $website = Website::factory()->create(['is_active' => true, 'last_status' => 'up']);

        config(['judol.max_seconds' => 1, 'judol.max_pages' => 60]);

        // Every page links onward, so the queue never empties on its own: only the
        // deadline can end this crawl.
        Http::fake(function () {
            usleep(120000);

            return Http::response(
                '<html><body><a href="/'.uniqid().'">next</a></body></html>',
                200
            );
        });

        $started = microtime(true);
        app(JudolScanner::class)->scan($website);
        $elapsed = microtime(true) - $started;

        // Generous ceiling: what matters is that it returns on its own well before
        // the job timeout, not the exact moment it does.
        $this->assertLessThan(30, $elapsed, 'the crawl ignored its time budget');
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
