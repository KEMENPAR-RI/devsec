<?php

namespace Tests\Feature;

use App\Jobs\CheckWebsiteJob;
use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use App\Notifications\AttackAnomalyDetected;
use App\Notifications\WebsiteDownNotification;
use App\Services\Monitoring\WebsiteChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

class WebsiteMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_pages_render_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();

        $this->actingAs($user);

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('situs'))->assertOk();
        $this->get(route('situs.show', $website))->assertOk();
        $this->get(route('insiden'))->assertOk();
        $this->get(route('notifikasi'))->assertOk();
    }

    public function test_repeated_failures_open_a_downtime_incident_and_notify(): void
    {
        NotificationFacade::fake();

        Http::fake(fn () => Http::response('', 500));

        $user = User::factory()->create();
        $website = Website::factory()->create(['down_threshold_failures' => 3]);

        $checker = new WebsiteChecker;
        $checker->check($website);
        $checker->check($website);
        $checker->check($website);

        $website->refresh();

        $this->assertSame('down', $website->last_status);
        $this->assertSame(1, Incident::where('website_id', $website->id)->where('type', 'downtime')->count());
        NotificationFacade::assertSentTo($user, WebsiteDownNotification::class);
    }

    public function test_a_recovered_website_resolves_its_downtime_incident(): void
    {
        // Two 500s, then the recovery: the probe retries once before giving up
        // (see WebsiteChecker::httpClient), so a single failing response is not
        // enough to record an outage - by design, since one dropped handshake
        // would otherwise open an incident and email every admin.
        Http::fakeSequence()->push('', 500)->push('', 500)->push('ok', 200);

        $website = Website::factory()->create(['down_threshold_failures' => 1]);
        $checker = new WebsiteChecker;

        $checker->check($website);
        $website->refresh();
        $this->assertSame('down', $website->last_status);

        $checker->check($website);
        $website->refresh();
        $this->assertSame('up', $website->last_status);
        $this->assertSame('resolved', Incident::where('website_id', $website->id)->where('type', 'downtime')->first()->status);
    }

    public function test_response_time_over_threshold_is_treated_as_down(): void
    {
        NotificationFacade::fake();
        // A real delay before the fake response resolves, so response_time_ms is
        // reliably non-zero and clears the threshold with margin - a razor-thin gap
        // between the two (e.g. 5ms delay vs. a 1ms threshold) is flaky under system
        // load/scheduling jitter, which this suite has hit intermittently.
        Http::fake(function () {
            usleep(20000);

            return Http::response('ok', 200);
        });

        $website = Website::factory()->create(['down_threshold_failures' => 1, 'response_time_threshold_ms' => 5]);

        $check = (new WebsiteChecker)->check($website);

        $this->assertFalse($check->is_up);
        $this->assertStringContainsString('melebihi ambang batas', $check->error_message);

        $website->refresh();
        $this->assertSame('down', $website->last_status);
        $this->assertSame(1, Incident::where('website_id', $website->id)->where('type', 'downtime')->count());
    }

    /** Runs the protected spike detector against an already-stored check. */
    protected function detectSpike(Website $website, int $ms): void
    {
        $check = $website->checks()->create([
            'is_up' => true, 'status_code' => 200, 'response_time_ms' => $ms, 'checked_at' => now(),
        ]);

        $method = new ReflectionMethod(WebsiteChecker::class, 'detectResponseTimeAnomaly');
        $method->invoke(new WebsiteChecker, $website, $check);
    }

    public function test_the_spike_baseline_is_the_last_ten_successful_checks_not_all_history(): void
    {
        NotificationFacade::fake();
        Http::fake();
        $website = Website::factory()->create();
        $at = now()->subHours(3);
        // 15 old slow checks + 10 recent fast ones: an unbounded AVG() reads 640ms and misses the spike.
        foreach ([...array_fill(0, 15, 1000), ...array_fill(0, 10, 100)] as $ms) {
            $website->checks()->create([
                'is_up' => true, 'status_code' => 200, 'response_time_ms' => $ms,
                'checked_at' => $at = $at->copy()->addMinutes(5),
            ]);
            // Failed checks in between are not part of the baseline.
            $website->checks()->create([
                'is_up' => false, 'status_code' => null, 'response_time_ms' => 30000,
                'checked_at' => $at->copy()->addMinute(),
            ]);
        }

        $this->detectSpike($website, 400);

        $incident = Incident::where('website_id', $website->id)->where('type', 'attack')->sole();
        $this->assertSame('response_time_spike', $incident->meta['reason']);
        $this->assertSame(100, $incident->meta['baseline_ms']);
        $this->assertSame(400, $incident->meta['response_time_ms']);
        NotificationFacade::assertSentTimes(AttackAnomalyDetected::class, User::count());
    }

    public function test_a_check_under_three_times_the_recent_baseline_is_not_a_spike(): void
    {
        NotificationFacade::fake();
        Http::fake();
        $website = Website::factory()->create();
        $at = now()->subHours(3);
        foreach ([...array_fill(0, 15, 50), ...array_fill(0, 10, 100)] as $ms) {
            $website->checks()->create([
                'is_up' => true, 'status_code' => 200, 'response_time_ms' => $ms,
                'checked_at' => $at = $at->copy()->addMinutes(5),
            ]);
        }

        // 3x the last-10 baseline (100ms) is 300ms; an unbounded average (70ms) would flag 250ms.
        $this->detectSpike($website, 250);

        $this->assertSame(0, Incident::where('website_id', $website->id)->where('type', 'attack')->count());
        NotificationFacade::assertNothingSent();
    }

    /**
     * Regression: connectTimeout was briefly lowered to 5s and the retry removed,
     * as a "a host that has not connected by now is unreachable" optimisation. It
     * is not true here - the government/institutional sites this monitors sit on
     * shared hosting whose TLS handshake regularly runs past 5s - and every one of
     * them flipped red while still loading fine in a browser.
     *
     * The symptom was deceptive enough to be worth pinning: a cut-off handshake
     * produces no response at all, so the check recorded "down" with a
     * response_time_ms far below the threshold. A red site whose latency looked
     * healthy.
     *
     * This reads the source rather than the client object: Http::fake() never opens
     * a connection, so no behavioural test can observe a connect timeout, and the
     * alternative - reflecting into PendingRequest's private state - pins this
     * suite to framework internals that are free to change.
     */
    public function test_the_probe_allows_a_slow_tls_handshake_and_retries_once(): void
    {
        $source = file_get_contents(app_path('Services/Monitoring/WebsiteChecker.php'));

        preg_match('/->connectTimeout\((\d+)\)/', $source, $connect);
        $this->assertNotEmpty($connect, 'the probe must set an explicit connect timeout');
        $this->assertGreaterThanOrEqual(
            10,
            (int) $connect[1],
            'shared-hosting TLS handshakes need room; below 10s these sites read as down'
        );

        preg_match('/->retry\((\d+),/', $source, $retry);
        $this->assertNotEmpty($retry, 'the probe must set an explicit retry count');
        $this->assertGreaterThanOrEqual(
            2,
            (int) $retry[1],
            'a single dropped handshake must not be enough to open a downtime incident'
        );
    }

    public function test_a_site_down_less_than_a_day_is_not_offline(): void
    {
        $website = Website::factory()->create(['last_status' => 'down']);
        Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now()->subHours(2),
        ]);

        $this->assertFalse($website->isOffline());
    }

    public function test_a_site_down_for_over_a_day_is_offline(): void
    {
        $website = Website::factory()->create(['last_status' => 'down']);
        Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now()->subDays(2),
        ]);

        $this->assertTrue($website->isOffline());
    }

    public function test_a_site_that_is_up_is_never_offline_even_with_an_old_open_incident(): void
    {
        $website = Website::factory()->create(['last_status' => 'up']);
        Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now()->subDays(2),
        ]);

        $this->assertFalse($website->isOffline());
    }

    public function test_offline_ids_bulk_matches_isoffline_for_a_whole_list(): void
    {
        // One query for the whole list instead of one per row - must agree with the
        // per-record isOffline() it replaces in list views.
        $longDown = Website::factory()->create(['last_status' => 'down']);
        Incident::create([
            'website_id' => $longDown->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now()->subDays(2),
        ]);

        $recentDown = Website::factory()->create(['last_status' => 'down']);
        Incident::create([
            'website_id' => $recentDown->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now()->subHours(2),
        ]);

        $up = Website::factory()->create(['last_status' => 'up']);

        $websites = Website::all();
        $offlineIds = Website::offlineIds($websites);

        $this->assertSame([$longDown->id], array_keys($offlineIds));
        $this->assertTrue($longDown->isOffline());
        $this->assertFalse($recentDown->isOffline());
        $this->assertFalse($up->isOffline());
    }

    public function test_status_key_reflects_up_down_offline_internal_and_unknown(): void
    {
        $this->assertSame('up', (new Website(['last_status' => 'up']))->statusKey());
        $this->assertSame('down', (new Website(['last_status' => 'down']))->statusKey());
        $this->assertSame('offline', (new Website(['last_status' => 'down']))->statusKey(isOffline: true));
        $this->assertSame('unknown', (new Website(['last_status' => 'unknown']))->statusKey());

        // internal_only wins over "offline" even after a long streak - there's no
        // "it's been a while, calm down" distinction to make for a site that was
        // never expected to be reachable from here in the first place.
        $internalDown = new Website(['last_status' => 'down', 'is_internal_only' => true]);
        $this->assertSame('internal', $internalDown->statusKey());
        $this->assertSame('internal', $internalDown->statusKey(isOffline: true));

        // internal_only on an UP site is just... up. The flag only changes how a
        // failure reads, not a success.
        $internalUp = new Website(['last_status' => 'up', 'is_internal_only' => true]);
        $this->assertSame('up', $internalUp->statusKey());
    }

    public function test_check_websites_command_only_dispatches_due_websites(): void
    {
        Queue::fake();

        Website::factory()->create(['last_checked_at' => now(), 'check_interval_minutes' => 60]);
        $due = Website::factory()->create(['last_checked_at' => now()->subHours(2), 'check_interval_minutes' => 60]);

        $this->artisan('websites:check')->assertSuccessful();

        Queue::assertPushed(CheckWebsiteJob::class, 1);
        Queue::assertPushed(fn (CheckWebsiteJob $job) => $job->website->is($due));
    }
}
