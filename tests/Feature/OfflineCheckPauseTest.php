<?php

namespace Tests\Feature;

use App\Jobs\CheckWebsiteJob;
use App\Livewire\Websites\Show;
use App\Models\User;
use App\Models\Website;
use App\Services\Monitoring\WebsiteChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class OfflineCheckPauseTest extends TestCase
{
    use RefreshDatabase;

    /** A site down long enough that automatic checking should have stopped. */
    protected function offlineWebsite(array $attributes = []): Website
    {
        return Website::factory()->create([
            'last_status' => 'down',
            'check_interval_minutes' => 5,
            // A full day at 5-minute intervals.
            'consecutive_failures' => 288,
            'last_checked_at' => now()->subHour(),
            ...$attributes,
        ]);
    }

    public function test_a_site_offline_for_a_day_is_no_longer_due_for_automatic_checks(): void
    {
        $website = $this->offlineWebsite();

        $this->assertTrue($website->isPausedForOffline());
        $this->assertFalse($website->isDueForCheck(), 'the interval has elapsed, but the site is paused');
    }

    public function test_a_paused_site_is_rechecked_once_every_24_hours(): void
    {
        $this->assertTrue($this->offlineWebsite(['last_checked_at' => now()->subHours(25)])->isDueForCheck());
        $this->assertFalse($this->offlineWebsite(['last_checked_at' => now()->subHours(23)])->isDueForCheck());
    }

    public function test_a_site_that_has_only_just_gone_down_keeps_being_checked(): void
    {
        // The pause is for sites nobody expects back soon - not for a fresh outage,
        // which is exactly when checking matters most.
        $website = $this->offlineWebsite(['consecutive_failures' => 12]);

        $this->assertFalse($website->isPausedForOffline());
        $this->assertTrue($website->isDueForCheck());
    }

    public function test_the_pause_threshold_follows_the_sites_own_check_interval(): void
    {
        // 24 failures is a full day for an hourly site, and two hours for a 5-minute one.
        $hourly = $this->offlineWebsite(['check_interval_minutes' => 60, 'consecutive_failures' => 24]);
        $frequent = $this->offlineWebsite(['check_interval_minutes' => 5, 'consecutive_failures' => 24]);

        $this->assertTrue($hourly->isPausedForOffline());
        $this->assertFalse($frequent->isPausedForOffline());
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

    public function test_the_scheduled_command_skips_paused_sites(): void
    {
        Queue::fake();

        $this->offlineWebsite();
        $healthy = Website::factory()->create(['last_status' => 'up', 'last_checked_at' => now()->subHour()]);

        $this->artisan('websites:check')->assertSuccessful();

        Queue::assertPushed(CheckWebsiteJob::class, 1);
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

    public function test_a_failing_daily_recheck_keeps_the_site_paused_for_another_day(): void
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
        $this->assertFalse($website->isDueForCheck(), 'next automatic try is 24h away');
    }

    public function test_a_successful_daily_recheck_resumes_normal_cadence(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $website = $this->offlineWebsite(['last_checked_at' => now()->subHours(25)]);

        app(WebsiteChecker::class)->check($website->fresh());
        $website->refresh();

        $this->assertSame('up', $website->last_status);
        $this->assertSame(0, $website->consecutive_failures);
        $this->assertFalse($website->isPausedForOffline());
        $this->travel(6)->minutes();
        $this->assertTrue($website->isDueForCheck());
    }

    public function test_the_detail_page_says_that_checking_is_paused(): void
    {
        $user = User::factory()->create();
        $website = $this->offlineWebsite();

        Livewire::actingAs($user)
            ->test(Show::class, ['website' => $website])
            ->assertSee('hanya dicoba ulang setiap 24 jam')
            ->assertSee('Cek Sekarang');
    }

    public function test_the_detail_page_stays_quiet_for_a_site_that_is_still_checked(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['last_status' => 'up']);

        Livewire::actingAs($user)
            ->test(Show::class, ['website' => $website])
            ->assertDontSee('hanya dicoba ulang setiap 24 jam');
    }
}
