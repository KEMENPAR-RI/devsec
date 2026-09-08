<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use App\Services\Monitoring\WebsiteChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The DDoS path end to end: a site that degrades over several checks and then stops
 * answering must come out the other side as a red "attack" dot with the run-up
 * quoted as evidence - through the real WebsiteChecker, not fabricated rows.
 */
class DdosDetectionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /**
     * The successful history leading up to an outage.
     *
     * Written directly rather than probed through WebsiteChecker, because the
     * latency it records comes from microtime() - real wall-clock, which neither
     * travel() nor a faked response can bend. Making the checker actually observe
     * 6800ms would mean sleeping 6.8 real seconds per check.
     *
     * The failing check that follows IS driven through the checker, so everything
     * this test is about - the diagnosis, the incident, the status dot - runs the
     * production path over this history.
     *
     * @param  array<int, int>  $latencies  ms per successful check, oldest first
     */
    protected function givenRecentHistory(Website $website, array $latencies): void
    {
        $at = now()->subMinutes(count($latencies) * 2 + 2);

        foreach ($latencies as $ms) {
            $website->checks()->create([
                'is_up' => true,
                'status_code' => 200,
                'response_time_ms' => $ms,
                'content_length' => 50000,
                'checked_at' => $at,
            ]);
            $at = $at->copy()->addMinutes(2);
        }
    }

    /** Runs one real check against a server that has stopped answering. */
    protected function whenTheSiteStopsAnswering(Website $website): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        app(WebsiteChecker::class)->check($website->fresh());
    }

    public function test_a_site_that_degrades_then_dies_is_diagnosed_as_possible_ddos(): void
    {
        $website = Website::factory()->create([
            'last_status' => 'up',
            'down_threshold_failures' => 1,
            'response_time_threshold_ms' => 15000,
        ]);

        // Healthy baseline, then a climb, then nothing at all.
        $this->givenRecentHistory($website, [300, 320, 310, 1500, 6800]);
        $this->whenTheSiteStopsAnswering($website);

        $incident = Incident::where('website_id', $website->id)->where('type', 'downtime')->first();

        $this->assertNotNull($incident, 'the outage itself must be recorded');
        $this->assertStringContainsString('DDoS', $incident->meta['likely_cause']);
        $this->assertTrue($incident->meta['cause_is_overload']);
        $this->assertStringContainsString('ms', $incident->meta['cause_evidence'], 'the run-up is quoted back');
        $this->assertStringContainsString('access log', $incident->meta['cause_caveat']);
    }

    public function test_the_dashboard_shows_that_outage_as_a_red_attack_dot(): void
    {
        $website = Website::factory()->create([
            'last_status' => 'up',
            'down_threshold_failures' => 1,
            'response_time_threshold_ms' => 15000,
        ]);

        $this->givenRecentHistory($website, [300, 320, 310, 1500, 6800]);
        $this->whenTheSiteStopsAnswering($website);

        $website = $website->fresh();

        $this->assertTrue($website->wasAttacked());
        $this->assertSame('attack', $website->statusKey(false, false, $website->wasAttacked()));
    }

    public function test_the_incident_page_shows_the_diagnosis_to_an_operator(): void
    {
        $website = Website::factory()->create([
            'last_status' => 'up',
            'down_threshold_failures' => 1,
            'response_time_threshold_ms' => 15000,
        ]);

        $this->givenRecentHistory($website, [300, 320, 310, 1500, 6800]);
        $this->whenTheSiteStopsAnswering($website);

        $lines = Incident::where('website_id', $website->id)->where('type', 'downtime')->first()->detailLines();

        $this->assertStringContainsString('DDoS', $lines['Dugaan Penyebab']);
        $this->assertArrayHasKey('Dasar Dugaan', $lines);
        $this->assertStringContainsString('access log', $lines['Catatan'], 'the caveat travels with the claim');
    }

    public function test_a_site_that_dies_without_warning_is_not_called_an_attack(): void
    {
        // The control case. Same outage, no degradation before it - and the app must
        // not reach for the DDoS label just because a site is down.
        $website = Website::factory()->create([
            'last_status' => 'up',
            'down_threshold_failures' => 1,
            'response_time_threshold_ms' => 15000,
        ]);

        $this->givenRecentHistory($website, [300, 310, 305]);
        $this->whenTheSiteStopsAnswering($website);

        $incident = Incident::where('website_id', $website->id)->where('type', 'downtime')->first();
        $website = $website->fresh();

        $this->assertArrayNotHasKey('likely_cause', $incident->meta ?? []);
        $this->assertFalse($website->wasAttacked());
        $this->assertSame('down', $website->statusKey(false, false, $website->wasAttacked()));
    }

    public function test_a_prompt_server_error_is_diagnosed_as_an_application_fault(): void
    {
        $website = Website::factory()->create([
            'last_status' => 'up',
            'down_threshold_failures' => 1,
            'response_time_threshold_ms' => 15000,
        ]);

        $this->givenRecentHistory($website, [300, 310, 305]);

        // A 500 that comes back immediately - the shape of a dead database.
        Http::fake(fn () => Http::response('Server Error', 500));
        app(WebsiteChecker::class)->check($website->fresh());

        $incident = Incident::where('website_id', $website->id)->where('type', 'downtime')->first();
        $website = $website->fresh();

        $this->assertSame('Kesalahan aplikasi atau database', $incident->meta['likely_cause']);
        $this->assertFalse($incident->meta['cause_is_overload']);
        $this->assertFalse($website->wasAttacked(), 'a database fault must never colour the dot as an attack');
    }

    public function test_the_simulate_command_produces_a_visible_attack_state(): void
    {
        // The command an operator would run to see this on their own dashboard.
        $website = Website::factory()->create(['name' => 'Situs Uji', 'last_status' => 'up']);
        User::factory()->create();

        $this->artisan('websites:simulate-outage', ['website' => 'Situs Uji', '--pattern' => 'ddos'])
            ->assertSuccessful();

        $website = $website->fresh();

        $this->assertSame('down', $website->last_status);
        $this->assertTrue($website->wasAttacked());
        $this->assertSame('attack', $website->statusKey(false, false, $website->wasAttacked()));
    }

    public function test_the_simulate_command_can_put_the_site_back(): void
    {
        $website = Website::factory()->create(['name' => 'Situs Uji', 'last_status' => 'up']);

        $this->artisan('websites:simulate-outage', ['website' => 'Situs Uji', '--pattern' => 'ddos'])->assertSuccessful();
        $this->artisan('websites:simulate-outage', ['website' => 'Situs Uji', '--clear' => true])->assertSuccessful();

        $website = $website->fresh();

        $this->assertSame('up', $website->last_status);
        $this->assertFalse($website->wasAttacked());
        $this->assertSame('up', $website->statusKey());
    }

    public function test_the_simulate_command_refuses_to_run_outside_local(): void
    {
        // Inventing check history on a production dashboard would send someone
        // investigating an outage that never happened.
        app()->detectEnvironment(fn () => 'production');

        Website::factory()->create(['name' => 'Situs Uji']);

        $this->artisan('websites:simulate-outage', ['website' => 'Situs Uji'])->assertFailed();
    }
}
