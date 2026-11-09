<?php

namespace Tests\Feature;

use App\Jobs\CheckWebsiteJob;
use App\Livewire\Dashboard;
use App\Livewire\Websites\Index as WebsitesIndex;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Models\WebsiteCheckDaily;
use App\Notifications\WebsiteDownNotification;
use App\Notifications\WebsiteRecoveredNotification;
use App\Notifications\WebsiteSlowNotification;
use App\Services\Monitoring\DashboardOverview;
use App\Services\Monitoring\MonitorConnectivity;
use App\Services\Monitoring\TvBoard;
use App\Services\Monitoring\UptimeHistory;
use App\Services\Monitoring\WebsiteChecker;
use App\Services\Reporting\MonthlyReport;
use App\Services\Security\OutboundUrlGuard;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Status "Gangguan" (warning) for slow responses, plus the no-response retry and
 * monitor self-check that keep a dropped connection from reading as an outage.
 */
class SlowWarningTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Sleep::fake();
        $this->admin = User::factory()->create();
    }

    // --- Fakes / helpers ---------------------------------------------------

    protected function site(array $attributes = []): Website
    {
        static $sequence = 0;

        // 5ms threshold so a 20ms fake reads as slow; SSL already checked: no real TLS probe.
        return Website::factory()->create([
            'url' => 'https://slow-'.(++$sequence).'.example',
            'response_time_threshold_ms' => 5,
            'down_threshold_failures' => 3,
            'last_status' => 'up',
            'ssl_checked_at' => now(),
            ...$attributes,
        ]);
    }

    protected function fakeSlow(): void
    {
        Http::fake(['*' => function () {
            usleep(20000);

            return Http::response('ok', 200);
        }]);
    }

    protected function fakeOk(Website $website): void
    {
        $website->update(['response_time_threshold_ms' => 15000]);
        Http::fake(['*' => Http::response('ok', 200)]);
    }

    protected function fakeStatus(int $status): void
    {
        Http::fake(['*' => Http::response('', $status)]);
    }

    /** errno 28; connected (appconnect_time > 0) makes it read_timeout, otherwise timeout. */
    protected function fakeTimeout(bool $connected = false): void
    {
        Http::fake(['*' => fn ($request) => Create::rejectionFor(new ConnectException(
            'cURL error 28: Operation timed out',
            $request->toPsrRequest(),
            null,
            ['errno' => 28, 'url' => (string) $request->url(), 'appconnect_time' => $connected ? 0.4 : 0.0, 'connect_time' => $connected ? 0.1 : 0.0],
        ))]);
    }

    protected function check(Website $website, bool $final = true, bool $retried = false): ?WebsiteCheck
    {
        $check = app(WebsiteChecker::class)->check($website, $final, $retried);
        $website->refresh();

        return $check;
    }

    /** Monitor self-check enabled, with a TCP probe that never touches the network. */
    protected function fakeConnectivity(bool $online): object
    {
        config([
            'monitoring.connectivity.enabled' => true,
            'monitoring.connectivity.targets' => ['192.0.2.1:443'],
        ]);

        $fake = new class($online) extends MonitorConnectivity
        {
            public int $probes = 0;

            public function __construct(public bool $online) {}

            protected function probe(): bool
            {
                $this->probes++;

                return $this->online;
            }
        };

        $this->app->instance(MonitorConnectivity::class, $fake);

        return $fake;
    }

    // --- State machine: slow -> warning -> down ----------------------------

    public function test_a_slow_check_on_an_online_site_turns_it_warning_and_notifies_once(): void
    {
        $website = $this->site();
        $this->fakeSlow();

        $check = $this->check($website);

        $this->assertSame('slow', $check->error_type);
        $this->assertFalse($check->is_up);
        $this->assertSame('warning', $website->last_status);
        $this->assertSame(1, $website->consecutive_failures);
        $this->assertSame('warning', $website->statusKey());
        $this->assertSame('Gangguan', Website::statusConfig('warning')['label']);
        $this->assertSame(0, Incident::where('website_id', $website->id)->count());
        Notification::assertSentToTimes($this->admin, WebsiteSlowNotification::class, 1);
        Notification::assertNotSentTo($this->admin, WebsiteDownNotification::class);
    }

    public function test_a_second_slow_check_stays_warning_without_a_second_notification(): void
    {
        $website = $this->site();
        $this->fakeSlow();

        $this->check($website);
        $this->check($website);

        $this->assertSame('warning', $website->last_status);
        $this->assertSame(2, $website->consecutive_failures);
        Notification::assertSentToTimes($this->admin, WebsiteSlowNotification::class, 1);
    }

    public function test_slow_checks_reaching_the_down_threshold_open_a_downtime_incident(): void
    {
        $website = $this->site();
        $this->fakeSlow();

        $first = $this->check($website);
        $this->travel(20)->seconds();
        $this->check($website);
        $this->travel(20)->seconds();
        $this->check($website);

        $this->assertSame('down', $website->last_status);
        $incident = Incident::where('website_id', $website->id)->where('type', 'downtime')->sole();
        $this->assertSame('critical', $incident->severity);
        $this->assertSame($first->checked_at->toIso8601String(), $incident->meta['first_failed_at']);
        Notification::assertSentTo($this->admin, WebsiteDownNotification::class);
    }

    public function test_a_per_site_threshold_of_one_skips_warning_and_goes_down(): void
    {
        $website = $this->site(['down_threshold_failures' => 1]);
        $this->fakeSlow();

        $this->check($website);

        $this->assertSame('down', $website->last_status);
        Notification::assertNotSentTo($this->admin, WebsiteSlowNotification::class);
        Notification::assertSentTo($this->admin, WebsiteDownNotification::class);
    }

    public function test_a_normal_check_returns_a_warning_site_to_up_silently(): void
    {
        $website = $this->site();
        $this->fakeSlow();
        $this->check($website);

        $this->fakeOk($website);
        $check = $this->check($website);

        $this->assertTrue($check->is_up);
        $this->assertSame('up', $website->last_status);
        $this->assertSame(0, $website->consecutive_failures);
        $this->assertSame(0, Incident::where('website_id', $website->id)->count());
        Notification::assertNotSentTo($this->admin, WebsiteDownNotification::class);
        Notification::assertNotSentTo($this->admin, WebsiteRecoveredNotification::class);
    }

    public function test_a_down_site_stays_down_on_a_slow_check_and_recovers_only_on_a_normal_one(): void
    {
        $website = $this->site(['last_status' => 'down', 'consecutive_failures' => 3]);
        Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now()->subMinutes(5),
        ]);
        $this->fakeSlow();

        $this->check($website);

        $this->assertSame('down', $website->last_status);
        $this->assertSame(1, Incident::where('website_id', $website->id)->count(), 'no second incident');
        Notification::assertNotSentTo($this->admin, WebsiteSlowNotification::class);

        $this->fakeOk($website);
        $this->check($website);

        $this->assertSame('up', $website->last_status);
        Notification::assertSentTo($this->admin, WebsiteRecoveredNotification::class);
    }

    public function test_an_http_error_while_warning_keeps_warning_and_extends_the_same_streak(): void
    {
        $website = $this->site();
        $this->fakeSlow();
        $this->check($website);

        $this->fakeStatus(503);
        $this->check($website);

        $this->assertSame('warning', $website->last_status);
        $this->assertSame(2, $website->consecutive_failures);

        $this->check($website);

        $this->assertSame('down', $website->last_status, 'slow + 5xx share one streak to down_threshold_failures');
    }

    public function test_an_http_error_on_an_online_site_keeps_the_hidden_verification_flow(): void
    {
        $website = $this->site();
        $this->fakeStatus(500);

        $this->check($website);

        $this->assertSame('up', $website->last_status);
        $this->assertSame(1, $website->consecutive_failures);
        $this->assertSame('up', $website->statusKey());
        Notification::assertNotSentTo($this->admin, WebsiteSlowNotification::class);

        $this->check($website);
        $this->check($website);

        $this->assertSame('down', $website->last_status);
    }

    public function test_maintenance_outranks_warning_and_sends_no_notification(): void
    {
        $website = $this->site();
        MaintenanceWindow::create([
            'website_id' => $website->id, 'reason' => 'Upgrade',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
        ]);
        $this->fakeSlow();

        $this->check($website);

        $this->assertSame('warning', $website->last_status);
        $this->assertSame('maintenance', $website->statusKey(underMaintenance: true));
        Notification::assertNothingSent();
    }

    public function test_an_internal_only_site_never_turns_warning(): void
    {
        $website = $this->site(['is_internal_only' => true]);
        $this->fakeSlow();

        $this->check($website);

        $this->assertNotSame('warning', $website->last_status);
        Notification::assertNotSentTo($this->admin, WebsiteSlowNotification::class);
    }

    public function test_a_flapping_site_is_notified_once_per_thirty_minute_window(): void
    {
        $website = $this->site();

        $this->fakeSlow();
        $this->check($website);
        $this->fakeOk($website);
        $this->check($website);
        $website->update(['response_time_threshold_ms' => 5]);
        $this->fakeSlow();
        $this->check($website);

        Notification::assertSentToTimes($this->admin, WebsiteSlowNotification::class, 1);

        $this->travel(31)->minutes();
        $this->fakeOk($website);
        $this->check($website);
        $website->update(['response_time_threshold_ms' => 5]);
        $this->fakeSlow();
        $this->check($website);

        Notification::assertSentToTimes($this->admin, WebsiteSlowNotification::class, 2);
    }

    // --- Timeout classification --------------------------------------------

    public function test_a_timeout_after_connecting_is_read_timeout_and_turns_warning(): void
    {
        $website = $this->site(['response_time_threshold_ms' => 5000]);
        $this->fakeTimeout(connected: true);

        $check = $this->check($website);

        $this->assertSame('read_timeout', $check->error_type);
        $this->assertSame('warning', $website->last_status);
        Notification::assertSentTo($this->admin, WebsiteSlowNotification::class);
    }

    public function test_a_timeout_before_connecting_without_retries_follows_the_normal_streak(): void
    {
        $website = $this->site(['response_time_threshold_ms' => 5000]);
        $this->fakeTimeout(connected: false);

        $check = $this->check($website);

        $this->assertSame('timeout', $check->error_type);
        $this->assertSame('up', $website->last_status);
        $this->assertSame(1, $website->consecutive_failures);
        Notification::assertNotSentTo($this->admin, WebsiteSlowNotification::class);
    }

    public function test_a_retried_no_response_is_down_after_one_confirmed_failure(): void
    {
        $website = $this->site(['response_time_threshold_ms' => 5000]);
        $this->fakeTimeout(connected: false);

        $this->check($website, final: true, retried: true);

        $this->assertSame('down', $website->last_status);
        Notification::assertSentTo($this->admin, WebsiteDownNotification::class);
    }

    public function test_a_non_final_no_response_attempt_records_nothing(): void
    {
        $website = $this->site(['response_time_threshold_ms' => 5000]);
        $this->fakeTimeout();

        $this->assertNull($this->check($website, final: false));
        $this->assertSame(0, $website->checks()->count());
        $this->assertSame(0, $website->consecutive_failures);
    }

    // --- CheckWebsiteJob retries -------------------------------------------

    public function test_the_job_releases_a_no_response_attempt_for_a_retry(): void
    {
        $website = $this->site(['response_time_threshold_ms' => 5000]);
        $this->fakeTimeout();

        $job = (new CheckWebsiteJob($website))->withFakeQueueInteractions();
        $job->handle(app(WebsiteChecker::class));

        $job->assertReleased(delay: 8);
        $this->assertSame(0, $website->checks()->count());
        $this->assertSame(0, $website->refresh()->consecutive_failures);
        $this->assertSame(3, $job->tries, '1 + monitoring.no_response.retries');
    }

    public function test_the_final_job_attempt_marks_a_no_response_site_down(): void
    {
        $website = $this->site(['response_time_threshold_ms' => 5000]);
        $this->fakeTimeout();

        $job = (new CheckWebsiteJob($website))->withFakeQueueInteractions();
        $job->job->attempts = 3;
        $job->handle(app(WebsiteChecker::class));

        $job->assertNotReleased();
        $this->assertSame(1, $website->checks()->count());
        $this->assertSame('down', $website->refresh()->last_status);
        Notification::assertSentTo($this->admin, WebsiteDownNotification::class);
    }

    public function test_a_retry_that_gets_a_response_stores_only_that_result(): void
    {
        $website = $this->site();
        $this->fakeOk($website);

        $job = (new CheckWebsiteJob($website))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        $job->handle(app(WebsiteChecker::class));

        $job->assertNotReleased();
        $this->assertSame(1, $website->checks()->count());
        $this->assertSame('up', $website->refresh()->last_status);
    }

    public function test_the_sync_driver_runs_final_with_the_normal_threshold(): void
    {
        $website = $this->site(['response_time_threshold_ms' => 5000]);
        $this->fakeTimeout();

        CheckWebsiteJob::dispatch($website);

        $website->refresh();
        $this->assertSame(1, $website->checks()->count());
        $this->assertSame('up', $website->last_status, 'no retries happened, so one failure is not down');
        $this->assertSame(1, $website->consecutive_failures);
    }

    public function test_zero_retries_runs_final_with_the_normal_threshold(): void
    {
        config(['monitoring.no_response.retries' => 0]);
        $website = $this->site(['response_time_threshold_ms' => 5000]);
        $this->fakeTimeout();

        $job = (new CheckWebsiteJob($website))->withFakeQueueInteractions();
        $job->handle(app(WebsiteChecker::class));

        $job->assertNotReleased();
        $this->assertSame('up', $website->refresh()->last_status);
        $this->assertSame(1, $website->consecutive_failures);
    }

    // --- Monitor offline ----------------------------------------------------

    public function test_an_offline_monitor_records_nothing_and_flags_the_dashboard(): void
    {
        $this->fakeConnectivity(online: false);
        $website = $this->site(['response_time_threshold_ms' => 5000, 'consecutive_failures' => 1]);
        $this->fakeTimeout();

        $this->assertNull($this->check($website, final: true, retried: true));

        $this->assertSame(0, $website->checks()->count());
        $this->assertSame(1, $website->consecutive_failures);
        $this->assertSame('up', $website->last_status);
        Notification::assertNothingSent();

        $status = app(MonitorConnectivity::class)->offlineStatus();
        $this->assertNotNull($status['since'] ?? null);

        $offline = Livewire::actingAs($this->admin)->test(Dashboard::class)
            ->assertSee('Server pemantau kehilangan koneksi internet')
            ->instance()->monitorOffline();
        $this->assertInstanceOf(Carbon::class, $offline['since']);
        $this->assertInstanceOf(Carbon::class, $offline['last_seen']);
    }

    public function test_the_probe_is_cached_across_sites_in_one_run(): void
    {
        $fake = $this->fakeConnectivity(online: false);
        $this->fakeTimeout();

        $this->check($this->site(['response_time_threshold_ms' => 5000]));
        $this->check($this->site(['response_time_threshold_ms' => 5000]));

        $this->assertSame(1, $fake->probes);
    }

    public function test_an_online_monitor_lets_a_no_response_failure_through(): void
    {
        $this->fakeConnectivity(online: true);
        $website = $this->site(['response_time_threshold_ms' => 5000]);
        $this->fakeTimeout();

        $this->check($website, final: true, retried: true);

        $this->assertSame(1, $website->checks()->count());
        $this->assertSame('down', $website->last_status);
    }

    public function test_a_job_skips_retries_when_the_monitor_is_known_offline(): void
    {
        $fake = $this->fakeConnectivity(online: false);
        Cache::put(MonitorConnectivity::CACHE_KEY, false, 30);
        $website = $this->site(['response_time_threshold_ms' => 5000]);
        $this->fakeTimeout();

        $job = (new CheckWebsiteJob($website))->withFakeQueueInteractions();
        $job->handle(app(WebsiteChecker::class));

        $job->assertNotReleased();
        $this->assertSame(0, $website->checks()->count());
        $this->assertSame(0, $fake->probes, 'reads the cached result, never re-probes');
    }

    public function test_blocked_probe_targets_do_not_hide_outages_while_sites_still_answer(): void
    {
        $this->fakeConnectivity(online: false);
        OutboundUrlGuard::fakeDns(['public.example' => ['93.184.216.34']]);
        $answering = $this->site(['url' => 'https://public.example']);
        $website = $this->site(['response_time_threshold_ms' => 5000]);
        $this->fakeTimeout();

        // First failure opens the offline episode and records nothing.
        $this->assertNull($this->check($website, final: true, retried: true));

        // Another site still answers after the probes started failing: the targets are blocked, not the uplink.
        $this->travel(5)->seconds();
        $answering->checks()->create(['is_up' => true, 'response_time_ms' => 100, 'checked_at' => now()]);
        $this->travel(5)->seconds();

        $this->assertNotNull($this->check($website, final: true, retried: true));
        $this->assertSame('down', $website->last_status);
        $this->assertNull(app(MonitorConnectivity::class)->offlineStatus(), 'no false "monitor offline" banner');
        $this->assertTrue(Cache::has(MonitorConnectivity::BLOCKED_KEY));
        $this->assertFalse(app(MonitorConnectivity::class)->knownOffline(), 'retries are not skipped');
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>, array<string, list<string>>}> */
    public static function answersThatDoNotProveTheUplink(): array
    {
        return [
            'internal-only site' => [['url' => 'https://public.example', 'is_internal_only' => true], ['is_up' => true], ['public.example' => ['93.184.216.34']]],
            'LAN-resolving site' => [['url' => 'https://intranet.example'], ['is_up' => true], ['intranet.example' => ['10.10.100.5']]],
            'proxy 502 reply' => [['url' => 'https://public.example'], ['is_up' => false, 'status_code' => 502, 'error_type' => 'http'], ['public.example' => ['93.184.216.34']]],
            'bare connection' => [['url' => 'https://public.example'], ['is_up' => false, 'error_type' => 'read_timeout'], ['public.example' => ['93.184.216.34']]],
        ];
    }

    #[DataProvider('answersThatDoNotProveTheUplink')]
    public function test_only_genuine_public_answers_keep_the_monitor_online(array $site, array $check, array $dns): void
    {
        $this->fakeConnectivity(online: false);
        OutboundUrlGuard::fakeDns($dns);
        $answering = $this->site($site);
        $website = $this->site(['response_time_threshold_ms' => 5000]);
        $this->fakeTimeout();

        $this->assertNull($this->check($website, final: true, retried: true));
        $this->travel(5)->seconds();
        $answering->checks()->create(['response_time_ms' => 100, 'checked_at' => now(), ...$check]);
        $this->travel(5)->seconds();

        $this->assertNull($this->check($website, final: true, retried: true));
        $this->assertSame(0, $website->checks()->count());
        $this->assertNotNull(app(MonitorConnectivity::class)->offlineStatus());
    }

    public function test_a_real_uplink_loss_stays_offline_when_no_site_answers_after_it(): void
    {
        $this->fakeConnectivity(online: false);
        // Answered before the uplink dropped: no evidence the uplink is still up.
        $this->site()->checks()->create(['is_up' => true, 'response_time_ms' => 100, 'checked_at' => now()->subSeconds(30)]);
        $website = $this->site(['response_time_threshold_ms' => 5000]);
        $this->fakeTimeout();

        $this->assertNull($this->check($website, final: true, retried: true));
        $this->travel(10)->seconds();
        $this->assertNull($this->check($website, final: true, retried: true));

        $this->assertSame(0, $website->checks()->count());
        $this->assertNotNull(app(MonitorConnectivity::class)->offlineStatus());
    }

    public function test_the_dashboard_has_no_offline_banner_while_the_monitor_is_online(): void
    {
        $component = Livewire::actingAs($this->admin)->test(Dashboard::class)
            ->assertDontSee('Server pemantau kehilangan koneksi internet');

        $this->assertNull($component->instance()->monitorOffline());
    }

    // --- Uptime --------------------------------------------------------------

    /** 1 ok + 1 slow + 1 read_timeout: slow counts up, read_timeout down => 66.7%. */
    protected function recordMixedChecks(Website $website, Carbon $at): void
    {
        foreach ([[true, null], [false, 'slow'], [false, 'read_timeout']] as $i => [$up, $type]) {
            $website->checks()->create([
                'is_up' => $up, 'status_code' => $type === 'read_timeout' ? null : 200,
                'response_time_ms' => 100, 'error_type' => $type, 'checked_at' => $at->copy()->addMinutes($i),
            ]);
        }
    }

    public function test_uptime_counts_slow_as_up_and_read_timeout_as_down(): void
    {
        $website = $this->site();
        $this->recordMixedChecks($website, now()->subHours(2));

        $this->assertSame(66.7, round($website->uptimePercentage(), 1));
        $this->assertSame(66.7, round(app(UptimeHistory::class)->summary($website->id)['24h'], 1));
    }

    public function test_the_monthly_report_counts_slow_as_up(): void
    {
        $website = $this->site();
        $month = now()->subMonthNoOverflow()->startOfMonth();
        $this->recordMixedChecks($website, $month->copy()->addDays(5));

        $row = app(MonthlyReport::class)->build($month, $month->copy()->endOfMonth())['rows']
            ->firstWhere('nama', $website->name);

        $this->assertSame(66.7, round($row['uptime'], 1));
    }

    public function test_the_prune_rollup_counts_slow_as_up(): void
    {
        $website = $this->site();
        $day = now()->subDays(120)->startOfDay()->addHours(10);
        $this->recordMixedChecks($website, $day);

        $this->artisan('websites:prune-checks')->assertSuccessful();

        $daily = WebsiteCheckDaily::where('website_id', $website->id)->whereDate('day', $day->toDateString())->sole();
        $this->assertSame(3, $daily->total_checks);
        $this->assertSame(2, $daily->up_checks);
    }

    // --- Warning re-check scheduling ---------------------------------------

    public function test_a_warning_site_is_due_after_the_short_recheck_gap_only(): void
    {
        $website = $this->site(['last_status' => 'warning', 'consecutive_failures' => 1, 'last_checked_at' => now()->subSeconds(10)]);
        $this->assertFalse($website->isDueForCheck());

        $website->last_checked_at = now()->subSeconds(20);
        $this->assertTrue($website->isDueForCheck());
    }

    public function test_the_warning_gap_counts_from_check_completion(): void
    {
        $website = $this->site();
        $this->freezeTime();
        $started = now()->copy();
        Http::fake(['*' => function () {
            usleep(20000);
            $this->travel(30)->seconds();

            return Http::response('ok', 200);
        }]);

        $this->check($website);

        $this->assertSame('warning', $website->last_status);
        $this->assertTrue($website->last_checked_at->greaterThan($started->copy()->addSeconds(25)));
        $this->assertFalse($website->isDueForCheck(), 'a slow probe does not make the site immediately due again');
    }

    public function test_the_warning_option_dispatches_only_due_warning_sites(): void
    {
        Queue::fake();
        $warning = $this->site(['last_status' => 'warning', 'consecutive_failures' => 1, 'last_checked_at' => now()->subSeconds(20)]);
        $this->site(['last_status' => 'up', 'last_checked_at' => null]);
        $this->site(['last_status' => 'down', 'last_checked_at' => null]);

        $this->artisan('websites:check', ['--warning' => true])->assertSuccessful();

        Queue::assertPushed(CheckWebsiteJob::class, 1);
        Queue::assertPushed(CheckWebsiteJob::class, fn ($job) => $job->website->is($warning));
    }

    public function test_the_warning_tick_is_capped_oldest_first_on_the_low_queue(): void
    {
        Queue::fake();
        config(['monitoring.warning_recheck_max_per_tick' => 2]);
        $sites = collect(range(1, 4))->map(fn (int $i) => $this->site([
            'last_status' => 'warning',
            'consecutive_failures' => 1,
            'last_checked_at' => now()->subSeconds(60 - $i),
        ]));

        $this->artisan('websites:check', ['--warning' => true])->assertSuccessful();

        Queue::assertPushed(CheckWebsiteJob::class, 2);
        Queue::assertPushedOn(config('monitoring.queues.checks_low'), CheckWebsiteJob::class);
        // Oldest-checked first.
        Queue::assertPushed(CheckWebsiteJob::class, fn ($job) => $job->website->is($sites[0]));
        Queue::assertPushed(CheckWebsiteJob::class, fn ($job) => $job->website->is($sites[1]));
    }

    public function test_a_warning_site_still_queued_is_not_dispatched_again(): void
    {
        Queue::fake();
        $website = $this->site(['last_status' => 'warning', 'consecutive_failures' => 1, 'last_checked_at' => now()->subMinute()]);

        $this->artisan('websites:check', ['--warning' => true])->assertSuccessful();
        // Due again, but its job still holds the unique lock.
        $website->update(['last_checked_at' => now()->subMinute()]);
        $this->artisan('websites:check', ['--warning' => true])->assertSuccessful();
        $this->artisan('websites:check')->assertSuccessful();

        Queue::assertPushed(CheckWebsiteJob::class, 1);
    }

    public function test_the_normal_tick_leaves_warning_sites_to_the_capped_tick(): void
    {
        Queue::fake();
        $this->site(['last_status' => 'warning', 'consecutive_failures' => 1, 'last_checked_at' => now()->subMinutes(10)]);
        $up = $this->site(['last_status' => 'up', 'last_checked_at' => null]);

        $this->artisan('websites:check')->assertSuccessful();

        Queue::assertPushed(CheckWebsiteJob::class, 1);
        Queue::assertPushed(CheckWebsiteJob::class, fn ($job) => $job->website->is($up));
    }

    // --- Ordering, counts, views -------------------------------------------

    /** Down, warning, priority up and plain up, created so that creation order alone would invert them. */
    protected function seedOrderingSites(): void
    {
        Website::factory()->create(['name' => 'Delta Down', 'last_status' => 'down', 'created_at' => now()->subDays(4)]);
        Website::factory()->create(['name' => 'Golf Gangguan', 'last_status' => 'warning', 'created_at' => now()->subDays(3)]);
        Website::factory()->create(['name' => 'Papa Priority', 'last_status' => 'up', 'is_priority' => true, 'created_at' => now()->subDays(2)]);
        Website::factory()->create(['name' => 'Uniform Up', 'last_status' => 'up', 'created_at' => now()]);
    }

    public function test_the_shared_list_order_is_down_then_warning_then_priority_then_rest(): void
    {
        $this->seedOrderingSites();
        $expected = ['Delta Down', 'Golf Gangguan', 'Papa Priority', 'Uniform Up'];

        $this->assertSame($expected, Website::orderForList()->pluck('name')->all());

        $listed = Livewire::actingAs($this->admin)->test(WebsitesIndex::class)->viewData('websites');
        $this->assertSame($expected, collect($listed->items())->pluck('name')->all());

        $overview = app(DashboardOverview::class);
        $sites = $overview->sites();
        $rows = $overview->liveSites($sites['websites'], $sites['statuses'], Website::loadLevels($sites['websites']));
        $this->assertSame($expected, array_column($rows, 'name'));
    }

    public function test_a_warning_site_under_maintenance_drops_out_of_the_warning_group(): void
    {
        Website::factory()->create(['name' => 'Uniform Up', 'last_status' => 'up', 'created_at' => now()]);
        $site = Website::factory()->create(['name' => 'Golf Gangguan', 'last_status' => 'warning', 'created_at' => now()->subDays(3)]);
        MaintenanceWindow::create([
            'website_id' => $site->id, 'reason' => 'x', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
        ]);

        $this->assertSame(['Uniform Up', 'Golf Gangguan'], Website::orderForList()->pluck('name')->all());
    }

    public function test_the_dashboard_summary_counts_warning_once_as_online(): void
    {
        $summary = app(DashboardOverview::class)->summary(['up', 'warning', 'down']);

        $this->assertSame(1, $summary['breakdown']['warning']);
        $this->assertSame(2, $summary['online']);
        $this->assertSame(1, $summary['problem']);
        $this->assertSame($summary['total'], array_sum($summary['breakdown']));
    }

    public function test_the_dashboard_renders_the_gangguan_chip(): void
    {
        Website::factory()->create(['name' => 'Golf Gangguan', 'last_status' => 'warning']);

        Livewire::actingAs($this->admin)->test(Dashboard::class)
            ->assertOk()
            ->assertSee('1 Gangguan');
    }

    public function test_the_tv_board_lists_gangguan_after_down_and_before_priority_without_a_toast(): void
    {
        $this->seedOrderingSites();

        $board = app(TvBoard::class)->build();

        $this->assertSame(
            ['Delta Down', 'Golf Gangguan', 'Papa Priority', 'Uniform Up'],
            array_column($board['kartu'], 'name'),
        );
        $this->get(route('tv'))->assertOk()->assertSeeInOrder(['Delta Down', 'Golf Gangguan', 'Papa Priority']);
    }

    public function test_a_warning_site_alone_never_fires_the_tv_toast(): void
    {
        Website::factory()->create(['name' => 'Golf Gangguan', 'last_status' => 'warning']);

        $board = app(TvBoard::class)->build();

        $this->assertNull($board['latest_event']);
        $this->assertSame('warning', $board['kartu'][0]['status']);
    }

    public function test_the_warning_migration_rolls_back_warning_sites_to_up(): void
    {
        $website = $this->site(['last_status' => 'warning']);
        $migration = require database_path('migrations/2026_11_09_000000_add_warning_to_websites_last_status.php');

        $migration->down();
        $this->assertSame('up', $website->refresh()->last_status);

        $migration->up();
        $website->update(['last_status' => 'warning']);
        $this->assertSame('warning', $website->refresh()->last_status);
    }

    public function test_a_guest_cannot_see_the_dashboard_or_site_list(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('situs'))->assertRedirect(route('login'));
    }
}
