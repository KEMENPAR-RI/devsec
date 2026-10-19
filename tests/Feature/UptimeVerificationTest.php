<?php

namespace Tests\Feature;

use App\Jobs\CheckWebsiteJob;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Websites\Index as WebsitesIndex;
use App\Livewire\Websites\Show;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Role;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Notifications\WebsiteDownNotification;
use App\Services\Monitoring\WebsiteChecker;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Uptime monitoring phases 1-3: verification cadence, incident bookkeeping
 * around maintenance, failure classification and job de-duplication.
 */
class UptimeVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // The in-request retry backoff is real sleep otherwise.
        Sleep::fake();
        $this->admin = User::factory()->create();
    }

    // --- Maintenance / incident bookkeeping ---------------------------------

    public function test_a_site_still_down_after_maintenance_gets_an_incident(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $website = $this->site(['down_threshold_failures' => 2]);
        $window = MaintenanceWindow::create([
            'website_id' => $website->id,
            'reason' => 'Migrasi',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->check($website, 2);
        $this->assertSame('down', $website->fresh()->last_status);
        $this->assertSame(0, Incident::count());

        $window->update(['ends_at' => now()->subMinute()]);
        $this->check($website);

        $incident = Incident::where('website_id', $website->id)->where('type', 'downtime')->sole();
        $this->assertSame('open', $incident->status);
        Notification::assertSentToTimes($this->admin, WebsiteDownNotification::class, 1);

        // And only one: the next failure finds the open incident.
        $this->check($website);
        $this->assertSame(1, Incident::where('type', 'downtime')->count());
        Notification::assertSentToTimes($this->admin, WebsiteDownNotification::class, 1);
    }

    public function test_a_site_paused_for_offline_does_not_get_a_late_incident(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        // Already a full day of failures at 60-minute interval (24), no incident.
        $website = $this->site([
            'last_status' => 'down',
            'check_interval_minutes' => 60,
            'consecutive_failures' => 24,
        ]);
        $this->assertTrue($website->isPausedForOffline());

        $this->check($website);

        $this->assertSame(0, Incident::count());
        Notification::assertNothingSent();
    }

    public function test_repeated_failures_never_open_a_second_incident(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $website = $this->site(['down_threshold_failures' => 1]);

        $this->check($website, 4);

        $this->assertSame(1, Incident::where('type', 'downtime')->count());
        Notification::assertSentToTimes($this->admin, WebsiteDownNotification::class, 1);
    }

    public function test_a_hand_resolved_incident_of_the_same_streak_is_not_reopened(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $website = $this->site(['down_threshold_failures' => 1]);

        $this->check($website);
        Incident::sole()->resolve();

        $this->travel(5)->minutes();
        $this->check($website);

        $this->assertSame(1, Incident::count());
        Notification::assertSentToTimes($this->admin, WebsiteDownNotification::class, 1);
    }

    public function test_the_incident_records_when_the_streak_began_and_why(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        $website = $this->site(['down_threshold_failures' => 3]);

        $this->travelTo(Carbon::parse('2026-10-18 08:00:00'));
        $this->check($website);
        $this->travelTo(Carbon::parse('2026-10-18 08:01:00'));
        $this->check($website);
        $this->travelTo(Carbon::parse('2026-10-18 08:02:00'));
        $this->check($website);

        $meta = Incident::sole()->meta;
        $this->assertTrue(Carbon::parse($meta['first_failed_at'])->equalTo(Carbon::parse('2026-10-18 08:00:00')));
        $this->assertSame('http', $meta['error_type']);
        $this->assertSame(503, $meta['status_code']);
        $this->assertSame(3, $meta['consecutive_failures']);
    }

    // --- Verification cadence ------------------------------------------------

    public function test_an_unconfirmed_failure_is_rechecked_after_one_minute(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 10:00:00'));
        $website = $this->site([
            'check_interval_minutes' => 10,
            'down_threshold_failures' => 3,
            'consecutive_failures' => 1,
            'last_status' => 'up',
            'last_checked_at' => now()->subSeconds(29),
        ]);

        $this->assertFalse($website->isDueForCheck(), 'not before the 30s tolerance');

        $website->last_checked_at = now()->subSeconds(31);
        $this->assertTrue($website->isDueForCheck(), '1 minute minus 30s tolerance');
    }

    public function test_a_healthy_site_waits_the_full_interval_minus_the_tolerance(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 10:00:00'));
        $website = $this->site(['check_interval_minutes' => 5, 'consecutive_failures' => 0, 'last_status' => 'up']);

        $website->last_checked_at = now()->subMinutes(2);
        $this->assertFalse($website->isDueForCheck());

        $website->last_checked_at = now()->subSeconds(4 * 60 + 29);
        $this->assertFalse($website->isDueForCheck());

        $website->last_checked_at = now()->subSeconds(4 * 60 + 31);
        $this->assertTrue($website->isDueForCheck());
    }

    public function test_a_confirmed_outage_returns_to_the_full_interval(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 10:00:00'));
        $website = $this->site([
            'check_interval_minutes' => 5,
            'down_threshold_failures' => 3,
            'consecutive_failures' => 3,
            'last_status' => 'down',
            'last_checked_at' => now()->subMinutes(2),
        ]);

        $this->assertFalse($website->isDueForCheck());
    }

    public function test_a_threshold_of_one_never_enters_verification(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 10:00:00'));
        $website = $this->site([
            'check_interval_minutes' => 5,
            'down_threshold_failures' => 1,
            'consecutive_failures' => 0,
            'last_status' => 'up',
            'last_checked_at' => now()->subMinutes(2),
        ]);

        $this->assertFalse($website->isDueForCheck());
    }

    public function test_the_long_down_backoff_still_wins_over_verification(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 10:00:00'));
        $website = $this->site([
            'check_interval_minutes' => 5,
            'down_threshold_failures' => 3,
            'consecutive_failures' => 288,
            'last_status' => 'down',
            'last_checked_at' => now()->subHours(23),
        ]);

        $this->assertFalse($website->isDueForCheck());

        $website->last_checked_at = now()->subHours(25);
        $this->assertTrue($website->isDueForCheck());
    }

    public function test_last_checked_at_is_the_start_of_the_check(): void
    {
        Http::fake(['*' => function () {
            $this->travel(20)->seconds();

            return Http::response('ok', 200);
        }]);
        $this->travelTo(Carbon::parse('2026-10-18 10:00:00'));
        $website = $this->site();

        $this->check($website);

        $this->assertSame('2026-10-18 10:00:00', $website->fresh()->last_checked_at->format('Y-m-d H:i:s'));
    }

    // --- Timeout ------------------------------------------------------------

    public function test_the_timeout_follows_the_threshold_within_ten_to_thirty_seconds(): void
    {
        $method = new ReflectionMethod(WebsiteChecker::class, 'timeoutFor');
        $timeout = fn (int $ms) => $method->invoke(new WebsiteChecker, new Website(['response_time_threshold_ms' => $ms]));

        $this->assertSame(10, $timeout(200));
        $this->assertSame(10, $timeout(5000));
        $this->assertSame(11, $timeout(5001));
        $this->assertSame(20, $timeout(15000));
        $this->assertSame(30, $timeout(25000));
        $this->assertSame(30, $timeout(60000));
    }

    public function test_a_timeout_is_not_retried_nor_repeated_without_ssl(): void
    {
        $attempts = 0;
        Http::fake(['*' => function ($request) use (&$attempts) {
            $attempts++;

            return Http::failedConnection('cURL error 28: SSL connection timeout after 20001 milliseconds')($request);
        }]);
        $website = $this->site(['url' => 'https://slowhost.example']);

        $check = $this->check($website);

        $this->assertSame(1, $attempts);
        $this->assertSame('timeout', $check->error_type);
    }

    public function test_a_dropped_connection_is_still_retried_once(): void
    {
        $attempts = 0;
        Http::fake(['*' => function ($request) use (&$attempts) {
            $attempts++;

            return Http::failedConnection('cURL error 7: Failed to connect to flaky.example port 80')($request);
        }]);
        $website = $this->site(['url' => 'http://flaky.example']);

        $this->check($website);

        $this->assertSame(2, $attempts);
    }

    // --- error_type classification -------------------------------------------

    public function test_an_http_error_is_classified_as_http(): void
    {
        Http::fake(['*' => Http::response('', 502)]);

        $check = $this->check($this->site());

        $this->assertFalse($check->is_up);
        $this->assertSame('http', $check->error_type);
        $this->assertSame(502, $check->status_code);
    }

    public function test_a_response_over_the_threshold_is_classified_as_slow(): void
    {
        Http::fake(['*' => function () {
            usleep(120_000);

            return Http::response('ok', 200);
        }]);
        $website = $this->site();
        // Below the form minimum on purpose, so the test stays fast.
        $website->forceFill(['response_time_threshold_ms' => 50])->save();

        $check = $this->check($website);

        $this->assertFalse($check->is_up);
        $this->assertSame('slow', $check->error_type);
    }

    public function test_a_successful_check_has_no_error_type(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $check = $this->check($this->site());

        $this->assertTrue($check->is_up);
        $this->assertNull($check->error_type);
    }

    public function test_a_guard_refusal_is_classified_as_blocked(): void
    {
        Http::fake();
        $website = $this->site(['url' => 'http://127.0.0.1']);

        $check = $this->check($website);

        $this->assertSame('blocked', $check->error_type);
        Http::assertNothingSent();
    }

    public function test_transport_failures_are_classified(): void
    {
        $cases = [
            'cURL error 28: Operation timed out after 20000 milliseconds' => 'timeout',
            'cURL error 6: Could not resolve host: nxdomain.example' => 'dns',
            'cURL error 7: Failed to connect to refused.example port 80: Connection refused' => 'connect',
            'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL' => 'ssl',
        ];

        // One fake for all hosts: later Http::fake() calls append, the first match wins.
        Http::fake(collect($cases)->mapWithKeys(fn ($type, $message) => [$type.'-host.example' => Http::failedConnection($message)])->all());

        foreach ($cases as $message => $expected) {
            $check = $this->check($this->site(['url' => 'http://'.$expected.'-host.example']));

            $this->assertSame($expected, $check->error_type, $message);
            $this->assertFalse($check->is_up);
        }
    }

    // --- Job de-duplication ---------------------------------------------------

    public function test_pressing_check_now_twice_queues_one_job(): void
    {
        Queue::fake();
        $website = $this->site();

        $component = Livewire::actingAs($this->admin)->test(Show::class, ['website' => $website]);

        $component->call('checkNow')
            ->assertDispatched('toast', fn ($e, $p) => $p['type'] === 'success');
        $component->call('checkNow')
            ->assertDispatched('toast', fn ($e, $p) => $p['type'] === 'info' && str_contains($p['message'], 'sudah dalam antrean'));

        Queue::assertPushed(CheckWebsiteJob::class, 1);
    }

    public function test_the_list_page_check_now_is_deduplicated_too(): void
    {
        Queue::fake();
        $website = $this->site();

        $component = Livewire::actingAs($this->admin)->test(WebsitesIndex::class);
        $component->call('checkNow', $website->uuid);
        $component->call('checkNow', $website->uuid)
            ->assertDispatched('toast', fn ($e, $p) => $p['type'] === 'info' && str_contains($p['message'], 'sudah dalam antrean'));

        Queue::assertPushed(CheckWebsiteJob::class, 1);
    }

    public function test_the_scheduler_does_not_queue_a_second_check_behind_check_now(): void
    {
        Queue::fake();
        $website = $this->site(['last_checked_at' => null]);

        Livewire::actingAs($this->admin)->test(Show::class, ['website' => $website])->call('checkNow');
        $this->artisan('websites:check')->assertSuccessful();

        Queue::assertPushed(CheckWebsiteJob::class, 1);
    }

    public function test_the_lock_is_per_site(): void
    {
        Queue::fake();
        [$a, $b] = [$this->site(), $this->site()];

        Livewire::actingAs($this->admin)->test(Show::class, ['website' => $a])->call('checkNow');
        Livewire::actingAs($this->admin)->test(Show::class, ['website' => $b])->call('checkNow');

        Queue::assertPushed(CheckWebsiteJob::class, 2);
    }

    public function test_the_lock_is_released_once_the_job_has_run(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $website = $this->site();

        // Sync queue: each dispatch runs and releases before the next press.
        $component = Livewire::actingAs($this->admin)->test(Show::class, ['website' => $website]);
        $component->call('checkNow');
        $component->call('checkNow')->assertDispatched('toast', fn ($e, $p) => $p['type'] === 'success');

        $this->assertSame(2, WebsiteCheck::where('website_id', $website->id)->count());
    }

    public function test_check_now_is_forbidden_without_update_permission(): void
    {
        Queue::fake();
        $viewer = User::factory()->create(['role_id' => Role::factory()->withPermissions(['situs.view'])->create()->id]);

        Livewire::actingAs($viewer)->test(Show::class, ['website' => $this->site()])
            ->call('checkNow')
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_the_job_declares_its_uniqueness(): void
    {
        $website = $this->site();
        $job = new CheckWebsiteJob($website);

        $this->assertSame((string) $website->id, $job->uniqueId());
        $this->assertSame(900, $job->uniqueFor);
        $this->assertSame(150, $job->timeout);
    }

    // --- Threshold validation ------------------------------------------------

    public function test_the_site_form_caps_the_threshold_at_25_seconds(): void
    {
        Livewire::actingAs($this->admin)->test(WebsitesIndex::class)
            ->call('openCreate')
            ->set('name', 'Dinas')
            ->set('url', 'https://dinas.example')
            ->set('responseTimeThresholdMs', 25001)
            ->call('save')
            ->assertHasErrors(['responseTimeThresholdMs' => 'max']);
    }

    public function test_the_default_threshold_setting_is_capped_at_25_seconds(): void
    {
        Livewire::actingAs($this->admin)->test(SettingsIndex::class)
            ->set('defaultResponseTimeThresholdMs', 25001)
            ->call('save')
            ->assertHasErrors(['defaultResponseTimeThresholdMs' => 'max']);

        Livewire::actingAs($this->admin)->test(SettingsIndex::class)
            ->set('defaultResponseTimeThresholdMs', 25000)
            ->call('save')
            ->assertHasNoErrors('defaultResponseTimeThresholdMs');
    }

    // --- Body cap -------------------------------------------------------------

    public function test_the_response_body_is_capped_at_two_megabytes(): void
    {
        // A real local HTTP server: Http::fake() short-circuits before the capped handler runs.
        $dir = sys_get_temp_dir().'/body-cap-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/big.html', str_repeat('a', WebsiteChecker::MAX_BODY_BYTES + 500_000));
        $port = random_int(20000, 40000);
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $dir], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);

        try {
            // Unpinned inner handler: the guard (rightly) refuses loopback.
            $handler = (new ReflectionMethod(WebsiteChecker::class, 'cappedBodyHandler'))->invoke(new WebsiteChecker, Utils::chooseHandler());
            $response = null;

            foreach (range(1, 20) as $ignored) {
                try {
                    $response = $handler(new Request('GET', "http://127.0.0.1:{$port}/big.html"), [])->wait();
                    break;
                } catch (\Throwable) {
                    usleep(100_000);
                }
            }

            if ($response === null) {
                $this->markTestSkipped('Local PHP server did not start.');
            }

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(WebsiteChecker::MAX_BODY_BYTES, strlen((string) $response->getBody()));
        } finally {
            proc_terminate($server);
            @unlink($dir.'/big.html');
            @rmdir($dir);
        }
    }

    // --- Helpers --------------------------------------------------------------

    protected function site(array $attributes = []): Website
    {
        static $sequence = 0;

        return Website::factory()->create([
            'url' => 'https://verify-'.(++$sequence).'.example',
            ...$attributes,
        ]);
    }

    protected function check(Website $website, int $times = 1): WebsiteCheck
    {
        $checker = app(WebsiteChecker::class);

        foreach (range(1, $times) as $ignored) {
            $check = $checker->check($website->fresh());
        }

        return $check;
    }
}
