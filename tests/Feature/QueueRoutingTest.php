<?php

namespace Tests\Feature;

use App\Jobs\CheckWebsiteJob;
use App\Jobs\ScanWebsiteForJudolJob;
use App\Livewire\Websites\Index;
use App\Livewire\Websites\Show;
use App\Models\Incident;
use App\Models\Role;
use App\Models\User;
use App\Models\Website;
use App\Notifications\WebsiteDownNotification;
use App\Support\QueueHealth;
use Database\Seeders\RoleSeeder;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Checks and crawls run on separate queues (worker A: checks-high,checks,default;
 * worker B: scans), and the scheduler never grows a backlog of duplicates.
 */
class QueueRoutingTest extends TestCase
{
    use RefreshDatabase;

    // --- Queue routing ---------------------------------------------------------

    public function test_a_priority_site_is_checked_on_the_high_queue(): void
    {
        Queue::fake();
        $priority = Website::factory()->create(['is_priority' => true, 'last_checked_at' => null]);
        $normal = Website::factory()->create(['is_priority' => false, 'last_checked_at' => null]);

        $this->artisan('websites:check')->assertSuccessful();

        Queue::assertPushedOn('checks-high', CheckWebsiteJob::class, fn ($job) => $job->website->is($priority));
        Queue::assertPushedOn('checks', CheckWebsiteJob::class, fn ($job) => $job->website->is($normal));
    }

    public function test_the_queue_is_chosen_by_the_job_itself(): void
    {
        $this->assertSame('checks-high', (new CheckWebsiteJob(Website::factory()->create(['is_priority' => true])))->queue);
        $this->assertSame('checks', (new CheckWebsiteJob(Website::factory()->create(['is_priority' => false])))->queue);
        $this->assertSame('scans', (new ScanWebsiteForJudolJob(Website::factory()->create()))->queue);
    }

    public function test_queue_names_come_from_config(): void
    {
        config(['monitoring.queues.scans' => 'crawl']);

        $this->assertSame('crawl', (new ScanWebsiteForJudolJob(Website::factory()->create()))->queue);
    }

    public function test_the_judol_job_is_unique_per_site(): void
    {
        $website = Website::factory()->create();
        $job = new ScanWebsiteForJudolJob($website);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame((string) $website->id, $job->uniqueId());
        $this->assertSame(7200, $job->uniqueFor);
    }

    // --- Dispatch brake --------------------------------------------------------

    public function test_scan_judol_queues_at_most_the_per_tick_cap_priority_first(): void
    {
        Queue::fake();
        config(['judol.max_dispatch_per_tick' => 2]);

        $old = Website::factory()->create(['last_status' => 'up', 'judol_scanned_at' => now()->subDays(3)]);
        Website::factory()->create(['last_status' => 'up', 'judol_scanned_at' => now()->subDays(2)]);
        $priority = Website::factory()->create(['is_priority' => true, 'last_status' => 'up', 'judol_scanned_at' => now()->subDay()]);

        $this->artisan('websites:scan-judol')->assertSuccessful();

        Queue::assertPushed(ScanWebsiteForJudolJob::class, 2);
        Queue::assertPushedOn('scans', ScanWebsiteForJudolJob::class, fn ($job) => $job->website->is($priority));
        Queue::assertPushedOn('scans', ScanWebsiteForJudolJob::class, fn ($job) => $job->website->is($old));
    }

    public function test_scan_judol_waits_while_the_scans_queue_is_full(): void
    {
        Queue::fake();
        config(['judol.max_queue' => 2]);
        Queue::pushOn('scans', 'placeholder-a');
        Queue::pushOn('scans', 'placeholder-b');

        $website = Website::factory()->create(['last_status' => 'up', 'judol_scanned_at' => null]);

        $this->artisan('websites:scan-judol')->assertSuccessful();

        Queue::assertNotPushed(ScanWebsiteForJudolJob::class);
        $this->assertNull($website->fresh()->judol_scanned_at, 'not dispatched, so not stamped');
    }

    public function test_scan_judol_only_fills_the_room_left_in_the_queue(): void
    {
        Queue::fake();
        config(['judol.max_queue' => 2, 'judol.max_dispatch_per_tick' => 5]);
        Queue::pushOn('scans', 'placeholder');

        Website::factory()->count(3)->create(['last_status' => 'up', 'judol_scanned_at' => null]);

        $this->artisan('websites:scan-judol')->assertSuccessful();

        Queue::assertPushed(ScanWebsiteForJudolJob::class, 1);
    }

    public function test_scan_judol_does_not_stamp_a_site_whose_crawl_is_still_queued(): void
    {
        Queue::fake();
        $website = Website::factory()->create(['last_status' => 'up', 'judol_scanned_at' => null]);
        (new UniqueLock(app(Cache::class)))->acquire(new ScanWebsiteForJudolJob($website));

        $this->artisan('websites:scan-judol')->assertSuccessful();

        Queue::assertNotPushed(ScanWebsiteForJudolJob::class);
        $this->assertNull($website->fresh()->judol_scanned_at);
    }

    public function test_websites_check_does_not_stamp_a_site_whose_check_is_still_queued(): void
    {
        Queue::fake();
        $website = Website::factory()->create(['last_checked_at' => null]);
        (new UniqueLock(app(Cache::class)))->acquire(new CheckWebsiteJob($website));

        $this->artisan('websites:check')->assertSuccessful();

        Queue::assertNotPushed(CheckWebsiteJob::class);
        $this->assertNull($website->fresh()->last_checked_at);
    }

    public function test_websites_check_releases_the_lock_when_stamping_fails(): void
    {
        Queue::fake();
        $website = Website::factory()->create(['last_checked_at' => null]);
        Website::updating(fn () => throw new RuntimeException('db down'));

        $this->assertThrows(fn () => Artisan::call('websites:check'), RuntimeException::class);

        $this->assertLockIsFree(new CheckWebsiteJob($website));
    }

    public function test_scan_judol_releases_the_lock_when_dispatch_fails(): void
    {
        $website = Website::factory()->create(['last_status' => 'up', 'judol_scanned_at' => null]);
        Bus::partialMock()->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));

        $this->assertThrows(fn () => Artisan::call('websites:scan-judol', ['--now' => true]), RuntimeException::class);

        $this->assertLockIsFree(new ScanWebsiteForJudolJob($website));
    }

    public function test_check_now_buttons_release_the_lock_when_dispatch_fails(): void
    {
        (new RoleSeeder)->run();
        $user = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id')]);
        $website = Website::factory()->create();
        $index = Livewire::actingAs($user)->test(Index::class);
        $show = Livewire::actingAs($user)->test(Show::class, ['website' => $website]);
        Bus::partialMock()->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));

        foreach ([
            [fn () => $index->call('checkNow', $website), new CheckWebsiteJob($website)],
            [fn () => $show->call('checkNow'), new CheckWebsiteJob($website)],
            [fn () => $show->call('scanJudolNow'), new ScanWebsiteForJudolJob($website)],
        ] as [$click, $job]) {
            try {
                $click();
                $this->fail('Expected the dispatch failure to surface.');
            } catch (RuntimeException) {
            }

            $this->assertLockIsFree($job);
        }
    }

    protected function assertLockIsFree(object $job): void
    {
        $lock = new UniqueLock(app(Cache::class));

        $this->assertTrue($lock->acquire($job), 'Unique lock was left held after a failed dispatch.');
        $lock->release($job);
    }

    // --- Notifications ---------------------------------------------------------

    public function test_check_notifications_queue_mail_on_the_high_queue_and_keep_in_app_immediate(): void
    {
        $notification = new WebsiteDownNotification(Website::factory()->create(), new Incident);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame(['mail' => 'checks-high'], $notification->viaQueues());
        $this->assertSame(['database' => 'sync'], $notification->viaConnections());
    }

    // --- Scheduler -------------------------------------------------------------

    public function test_failed_jobs_are_pruned_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'queue:prune-failed --hours=168'));

        $this->assertNotNull($event);
        $this->assertSame('45 3 * * *', $event->expression);
    }

    public function test_per_minute_commands_hold_their_mutex_for_minutes_not_a_day(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'websites:scan-judol'));

        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    // --- QueueHealth -----------------------------------------------------------

    public function test_a_capped_backlog_behind_a_busy_worker_is_not_stalled(): void
    {
        config(['queue.default' => 'database']);
        $this->insertJob('scans', waitingMinutes: 40);
        $this->insertJob('scans', waitingMinutes: 41, reservedMinutesAgo: 1);

        $this->assertFalse(app(QueueHealth::class)->isStalled());
    }

    public function test_a_queue_nobody_consumes_is_stalled_even_if_another_is_busy(): void
    {
        config(['queue.default' => 'database']);
        $this->insertJob('checks', waitingMinutes: 40);
        $this->insertJob('scans', waitingMinutes: 41, reservedMinutesAgo: 1);

        $this->assertTrue(app(QueueHealth::class)->isStalled());
    }

    public function test_a_lower_queue_behind_a_busy_high_queue_on_the_same_worker_is_not_stalled(): void
    {
        config(['queue.default' => 'database']);
        $this->insertJob('checks', waitingMinutes: 40);
        $this->insertJob('default', waitingMinutes: 40);
        $this->insertJob('checks-high', waitingMinutes: 41, reservedMinutesAgo: 1);

        $this->assertFalse(app(QueueHealth::class)->isStalled());
    }

    public function test_a_busy_scans_worker_does_not_hide_a_missing_checks_worker(): void
    {
        config(['queue.default' => 'database']);
        $this->insertJob('checks-high', waitingMinutes: 40);
        $this->insertJob('scans', waitingMinutes: 41, reservedMinutesAgo: 1);

        $this->assertTrue(app(QueueHealth::class)->isStalled());
    }

    public function test_worker_groups_follow_the_configured_queue_names(): void
    {
        config(['queue.default' => 'database', 'monitoring.queues.checks_high' => 'urgent', 'monitoring.queues.checks' => 'probes']);
        $this->insertJob('probes', waitingMinutes: 40);
        $this->insertJob('urgent', waitingMinutes: 41, reservedMinutesAgo: 1);

        $this->assertFalse(app(QueueHealth::class)->isStalled());
    }

    protected function insertJob(string $queue, int $waitingMinutes, ?int $reservedMinutesAgo = null): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => $reservedMinutesAgo === null ? 0 : 1,
            'reserved_at' => $reservedMinutesAgo === null ? null : now()->subMinutes($reservedMinutesAgo)->timestamp,
            'available_at' => now()->subMinutes($waitingMinutes)->timestamp,
            'created_at' => now()->subMinutes($waitingMinutes)->timestamp,
        ]);
    }
}
