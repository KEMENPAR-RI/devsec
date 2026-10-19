<?php

namespace Tests\Feature;

use App\Jobs\CheckWebsiteJob;
use App\Livewire\Websites\Show;
use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use App\Notifications\WebsiteDownNotification;
use App\Notifications\WebsiteRecoveredNotification;
use App\Services\Monitoring\WebsiteChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class OfflineCheckPauseTest extends TestCase
{
    use RefreshDatabase;

    /** A site down for over a day: still checked, but no longer news. */
    protected function offlineWebsite(array $attributes = []): Website
    {
        return Website::factory()->create([
            'last_status' => 'down',
            'check_interval_minutes' => 5,
            // A full day at its own 5-minute interval.
            'consecutive_failures' => 288,
            'last_checked_at' => now()->subHour(),
            ...$attributes,
        ]);
    }

    public function test_a_site_offline_for_a_day_is_still_rechecked_every_10_minutes(): void
    {
        $website = $this->offlineWebsite();

        $this->assertTrue($website->isPausedForOffline());
        $this->assertTrue($website->isDueForCheck(), 'no 24h backoff any more');

        $website->last_checked_at = now()->subMinutes(9);
        $this->assertFalse($website->isDueForCheck(), 'a down 5-minute site waits the offline 10 minutes');

        $website->last_checked_at = now()->subMinutes(10);
        $this->assertTrue($website->isDueForCheck());
    }

    public function test_a_one_minute_site_keeps_its_interval_for_the_first_day_down_then_slows_to_10(): void
    {
        Notification::fake();
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));
        // One failure short of a day at 1 minute.
        $website = $this->offlineWebsite(['check_interval_minutes' => 1, 'consecutive_failures' => 1439]);

        $this->assertFalse($website->isPausedForOffline());
        $this->assertSame(1, $website->effectiveCheckIntervalMinutes());
        $website->last_checked_at = now()->subMinute();
        $this->assertTrue($website->isDueForCheck());

        app(WebsiteChecker::class)->check($website->fresh());
        $website->refresh();

        $this->assertSame(1440, $website->consecutive_failures);
        $this->assertTrue($website->isPausedForOffline());
        $this->assertSame(10, $website->effectiveCheckIntervalMinutes());

        // Further 10-minute rechecks only add failures: it stays offline, and stays quiet.
        $this->travel(10)->minutes();
        $this->assertTrue($website->isDueForCheck());
        app(WebsiteChecker::class)->check($website->fresh());
        $website->refresh();

        $this->assertTrue($website->isPausedForOffline());
        $this->assertSame(10, $website->effectiveCheckIntervalMinutes());
        Notification::assertNothingSent();
    }

    public function test_a_one_minute_site_keeps_its_interval_while_up(): void
    {
        $website = Website::factory()->create(['last_status' => 'up', 'check_interval_minutes' => 1]);

        $this->assertSame(1, $website->effectiveCheckIntervalMinutes());
    }

    public function test_an_hourly_site_down_5_hours_keeps_its_60_minute_interval(): void
    {
        // Three confirmation failures plus five hourly ones.
        $website = $this->offlineWebsite(['check_interval_minutes' => 60, 'consecutive_failures' => 8]);

        $this->assertFalse($website->isPausedForOffline());
        $this->assertSame(60, $website->effectiveCheckIntervalMinutes());

        $website->last_checked_at = now()->subMinutes(10);
        $this->assertFalse($website->isDueForCheck());

        $website->last_checked_at = now()->subMinutes(60);
        $this->assertTrue($website->isDueForCheck());
    }

    public function test_an_hourly_site_down_a_day_is_rechecked_every_10_minutes(): void
    {
        $website = $this->offlineWebsite(['check_interval_minutes' => 60, 'consecutive_failures' => 24]);

        $this->assertTrue($website->isPausedForOffline());

        $website->last_checked_at = now()->subMinutes(9);
        $this->assertFalse($website->isDueForCheck());

        $website->last_checked_at = now()->subMinutes(10);
        $this->assertTrue($website->isDueForCheck());
    }

    public function test_an_hourly_site_returns_to_60_minutes_once_it_is_back_online(): void
    {
        $website = $this->offlineWebsite(['check_interval_minutes' => 60, 'consecutive_failures' => 24]);
        $this->assertSame(10, $website->effectiveCheckIntervalMinutes());

        // What WebsiteChecker::handleUpDown() does when a check succeeds.
        $website->update(['last_status' => 'up', 'consecutive_failures' => 0, 'last_checked_at' => now()->subMinutes(10)]);

        $this->assertSame(60, $website->effectiveCheckIntervalMinutes());
        $this->assertFalse($website->isDueForCheck());

        $website->last_checked_at = now()->subMinutes(60);
        $this->assertTrue($website->isDueForCheck());
    }

    public function test_the_offline_recheck_interval_comes_from_config(): void
    {
        config(['monitoring.offline_recheck_minutes' => 30]);
        $website = $this->offlineWebsite(['check_interval_minutes' => 60, 'last_checked_at' => now()->subMinutes(20)]);

        $this->assertSame(30, $website->effectiveCheckIntervalMinutes());
        $this->assertFalse($website->isDueForCheck());
    }

    public function test_the_offline_recheck_interval_is_clamped_to_5_to_60_minutes(): void
    {
        $website = $this->offlineWebsite(['check_interval_minutes' => 60]);

        foreach ([5 => 5, 60 => 60, '15' => 15, 4 => 10, 1 => 10, 0 => 10, -5 => 10, 61 => 10, 999 => 10] as $value => $expected) {
            config(['monitoring.offline_recheck_minutes' => $value]);
            $this->assertSame($expected, $website->effectiveCheckIntervalMinutes(), "config {$value}");
        }

        foreach (['abc', '', null, '7.5'] as $value) {
            config(['monitoring.offline_recheck_minutes' => $value]);
            $this->assertSame(10, $website->effectiveCheckIntervalMinutes(), 'non-numeric '.var_export($value, true));
        }
    }

    public function test_the_scheduled_command_rechecks_an_offline_hourly_site_after_10_minutes(): void
    {
        Queue::fake();

        $offline = $this->offlineWebsite([
            'check_interval_minutes' => 60,
            'consecutive_failures' => 24,
            'is_priority' => true,
            'last_checked_at' => now()->subMinutes(10),
        ]);
        $this->offlineWebsite(['check_interval_minutes' => 60, 'is_active' => false, 'last_checked_at' => now()->subMinutes(10)]);
        Website::factory()->create(['last_status' => 'up', 'check_interval_minutes' => 60, 'last_checked_at' => now()->subMinutes(10)]);
        // Down under a day: still on its own 60 minutes.
        $this->offlineWebsite(['check_interval_minutes' => 60, 'consecutive_failures' => 8, 'last_checked_at' => now()->subMinutes(10)]);

        $this->artisan('websites:check')->assertSuccessful();

        Queue::assertPushed(CheckWebsiteJob::class, 1);
        Queue::assertPushedOn(config('monitoring.queues.checks_high'), CheckWebsiteJob::class, fn ($job) => $job->website->is($offline));
    }

    public function test_a_site_that_has_only_just_gone_down_keeps_being_checked(): void
    {
        // The pause is for sites nobody expects back soon - not for a fresh outage,
        // which is exactly when checking matters most.
        $website = $this->offlineWebsite(['consecutive_failures' => 12]);

        $this->assertFalse($website->isPausedForOffline());
        $this->assertTrue($website->isDueForCheck());
    }

    public function test_the_offline_threshold_is_a_day_at_the_sites_own_interval(): void
    {
        // A site is checked at its own interval for the first day, so a day is 1440 / interval failures.
        foreach ([60 => 24, 5 => 288, 1 => 1440] as $interval => $perDay) {
            $offline = $this->offlineWebsite(['check_interval_minutes' => $interval, 'consecutive_failures' => $perDay]);
            $fresh = $this->offlineWebsite(['check_interval_minutes' => $interval, 'consecutive_failures' => $perDay - 1]);

            $this->assertTrue($offline->isPausedForOffline(), "{$interval}-minute site at {$perDay}");
            $this->assertFalse($fresh->isPausedForOffline(), "{$interval}-minute site at ".($perDay - 1));
            $this->assertSame($interval, $fresh->effectiveCheckIntervalMinutes());
        }
    }

    public function test_a_site_that_is_up_is_never_paused(): void
    {
        $website = Website::factory()->create([
            'last_status' => 'up',
            'consecutive_failures' => 999,
            'last_checked_at' => now()->subHour(),
        ]);

        $this->assertFalse($website->isPausedForOffline());
        $this->assertTrue($website->isDueForCheck());
    }

    public function test_the_scheduled_command_no_longer_skips_long_offline_sites(): void
    {
        Queue::fake();

        $offline = $this->offlineWebsite();
        $healthy = Website::factory()->create(['last_status' => 'up', 'last_checked_at' => now()->subHour()]);

        $this->artisan('websites:check')->assertSuccessful();

        Queue::assertPushed(CheckWebsiteJob::class, 2);
        Queue::assertPushed(fn (CheckWebsiteJob $job) => $job->website->is($offline));
        Queue::assertPushed(fn (CheckWebsiteJob $job) => $job->website->is($healthy));
    }

    public function test_the_force_flag_still_checks_a_paused_site(): void
    {
        // --now exists to override the schedule; a pause is part of the schedule.
        Queue::fake();

        $this->offlineWebsite();

        $this->artisan('websites:check', ['--now' => true])->assertSuccessful();

        Queue::assertPushed(CheckWebsiteJob::class, 1);
    }

    public function test_pressing_check_now_dispatches_a_check_for_a_paused_site(): void
    {
        Queue::fake();

        $website = $this->offlineWebsite();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Show::class, ['website' => $website])
            ->call('checkNow')
            ->assertHasNoErrors();

        Queue::assertPushed(fn (CheckWebsiteJob $job) => $job->website->is($website));
    }

    public function test_one_successful_check_resumes_automatic_monitoring(): void
    {
        $website = $this->offlineWebsite();

        // What WebsiteChecker::handleUpDown() does when a check finally succeeds.
        $website->update(['last_status' => 'up', 'consecutive_failures' => 0]);

        $this->assertFalse($website->isPausedForOffline());
        $this->assertTrue($website->isDueForCheck());
    }

    public function test_a_failing_recheck_keeps_the_site_on_the_short_offline_interval(): void
    {
        Notification::fake();
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));
        $website = $this->offlineWebsite(['last_checked_at' => now()->subHours(25)]);
        $this->assertTrue($website->isDueForCheck());

        app(WebsiteChecker::class)->check($website->fresh());
        $website->refresh();

        $this->assertSame('down', $website->last_status);
        $this->assertSame(289, $website->consecutive_failures);
        $this->assertTrue($website->isPausedForOffline());
        $this->assertFalse($website->isDueForCheck(), 'just checked');
        $this->travel(10)->minutes();
        $this->assertTrue($website->isDueForCheck(), 'next automatic try is one interval away, not 24h');
        Notification::assertNothingSent();
    }

    public function test_a_site_down_under_a_day_rechecked_at_its_interval_sends_one_down_email(): void
    {
        Notification::fake();
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));
        $admin = User::factory()->create();
        $website = Website::factory()->create([
            'last_status' => 'up',
            'check_interval_minutes' => 1,
            'down_threshold_failures' => 3,
        ]);

        // Confirmation streak, then half an hour of 1-minute rechecks: still under a day.
        foreach (range(1, 3 + 30) as $ignored) {
            app(WebsiteChecker::class)->check($website->fresh());
            $this->travel(1)->minutes();
        }

        $website->refresh();
        $this->assertSame('down', $website->last_status);
        $this->assertFalse($website->isPausedForOffline());
        Notification::assertSentToTimes($admin, WebsiteDownNotification::class, 1);
        $this->assertSame(1, $website->incidents()->where('type', 'downtime')->count());
    }

    public function test_a_successful_recheck_resumes_normal_cadence(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $admin = User::factory()->create();
        $website = $this->offlineWebsite(['last_checked_at' => now()->subMinutes(10)]);
        Incident::create([
            'website_id' => $website->id,
            'type' => 'downtime',
            'severity' => 'critical',
            'title' => 'down',
            'status' => 'open',
            'detected_at' => now()->subDays(2),
        ]);

        app(WebsiteChecker::class)->check($website->fresh());
        $website->refresh();

        $this->assertSame('up', $website->last_status);
        $this->assertSame(0, $website->consecutive_failures);
        $this->assertFalse($website->isPausedForOffline());
        $this->assertSame(5, $website->effectiveCheckIntervalMinutes());
        Notification::assertSentToTimes($admin, WebsiteRecoveredNotification::class, 1);
        $this->travel(6)->minutes();
        $this->assertTrue($website->isDueForCheck());
    }

    public function test_the_detail_page_says_that_checking_is_paused(): void
    {
        $user = User::factory()->create();
        $website = $this->offlineWebsite();

        Livewire::actingAs($user)
            ->test(Show::class, ['website' => $website])
            ->assertSee('Tetap dicek otomatis tiap 10 menit')
            ->assertSee('Cek Sekarang');
    }

    public function test_the_detail_page_stays_quiet_for_a_site_that_is_still_checked(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['last_status' => 'up']);

        Livewire::actingAs($user)
            ->test(Show::class, ['website' => $website])
            ->assertDontSee('Tetap dicek otomatis tiap');
    }
}
