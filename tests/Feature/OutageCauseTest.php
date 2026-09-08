<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Services\Monitoring\OutageCause;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutageCauseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, int>  $responseTimes  successful checks before the failure
     */
    protected function websiteWithRunup(array $responseTimes): Website
    {
        $website = Website::factory()->create();

        foreach ($responseTimes as $index => $ms) {
            $website->checks()->create([
                'is_up' => true,
                'status_code' => 200,
                'response_time_ms' => $ms,
                'checked_at' => now()->setTime(10, 0)->addMinutes($index * 2),
            ]);
        }

        return $website;
    }

    protected function failedCheck(Website $website, ?int $statusCode, int $ms, ?string $error = null): WebsiteCheck
    {
        return $website->checks()->create([
            'is_up' => false,
            'status_code' => $statusCode,
            'response_time_ms' => $ms,
            'error_message' => $error,
            'checked_at' => now()->setTime(10, 30),
        ]);
    }

    public function test_a_gradual_slowdown_before_the_failure_is_reported_as_possible_overload(): void
    {
        $website = $this->websiteWithRunup([320, 1500, 6800]);
        $check = $this->failedCheck($website, null, 20000, 'Connection timed out');

        $diagnosis = app(OutageCause::class)->diagnose($website, $check);

        $this->assertStringContainsString('DDoS', $diagnosis['cause']);
        $this->assertStringContainsString('320ms', $diagnosis['evidence'], 'the run-up itself is the evidence');
        $this->assertStringContainsString('6,800ms', $diagnosis['evidence']);
        $this->assertStringContainsString('access log', $diagnosis['caveat'], 'must say what would actually confirm it');
    }

    public function test_the_overload_diagnosis_names_a_preceding_response_time_spike_incident(): void
    {
        $website = $this->websiteWithRunup([400, 1200, 5000]);

        Incident::create([
            'website_id' => $website->id,
            'type' => 'attack',
            'severity' => 'warning',
            'title' => 'Lonjakan response time',
            'meta' => ['reason' => 'response_time_spike'],
            'status' => 'open',
            'detected_at' => now()->setTime(10, 18),
        ]);

        $check = $this->failedCheck($website, null, 20000, 'Connection timed out');

        $diagnosis = app(OutageCause::class)->diagnose($website, $check);

        $this->assertStringContainsString('12 menit sebelumnya', $diagnosis['evidence']);
    }

    public function test_a_prompt_server_error_is_not_blamed_on_overload(): void
    {
        // The whole point of the feature: a dead database answers fast and wrong,
        // and calling that a DDoS sends someone to the wrong provider.
        $website = $this->websiteWithRunup([300, 310, 295]);
        $check = $this->failedCheck($website, 500, 180, 'HTTP 500');

        $diagnosis = app(OutageCause::class)->diagnose($website, $check);

        $this->assertSame('Kesalahan aplikasi atau database', $diagnosis['cause']);
        $this->assertStringNotContainsString('DDoS', $diagnosis['cause'].$diagnosis['evidence']);
    }

    public function test_a_rate_limit_status_code_is_reported_as_overload_refusal(): void
    {
        $website = $this->websiteWithRunup([300, 320]);
        $check = $this->failedCheck($website, 429, 200, 'HTTP 429');

        $diagnosis = app(OutageCause::class)->diagnose($website, $check);

        $this->assertStringContainsString('kelebihan beban', $diagnosis['cause']);
        $this->assertStringContainsString('429', $diagnosis['evidence']);
    }

    public function test_a_fast_timeout_is_still_read_as_overload_not_as_a_refusal(): void
    {
        // A saturated server can drop a connection quickly, and a client that gives
        // up early records a short elapsed time either way. Going by duration alone
        // classified those as "the host refused" and never looked at the run-up -
        // which is exactly the outage this feature exists to recognise.
        $website = $this->websiteWithRunup([320, 1500, 6800]);
        $check = $this->failedCheck($website, null, 120, 'Connection timed out');

        $diagnosis = app(OutageCause::class)->diagnose($website, $check);

        $this->assertStringContainsString('DDoS', $diagnosis['cause']);
    }

    public function test_an_instant_refusal_is_reported_as_the_server_not_responding(): void
    {
        $website = $this->websiteWithRunup([300, 320]);
        $check = $this->failedCheck($website, null, 40, 'Could not resolve host');

        $diagnosis = app(OutageCause::class)->diagnose($website, $check);

        $this->assertSame('Server tidak merespons sama sekali', $diagnosis['cause']);
        $this->assertStringContainsString('Could not resolve host', $diagnosis['evidence']);
    }

    public function test_a_steady_site_that_simply_stops_answering_gets_no_guessed_cause(): void
    {
        // Silence is the correct answer here: no degradation, no status code to read.
        // A label invented from this would be a guess dressed as a finding.
        $website = $this->websiteWithRunup([300, 310, 305]);
        $check = $this->failedCheck($website, null, 20000, 'Connection timed out');

        $this->assertNull(app(OutageCause::class)->diagnose($website, $check));
    }

    public function test_a_slowdown_from_a_near_instant_baseline_is_not_enough_to_diagnose(): void
    {
        // 12ms to 30ms is a tripling, and means nothing at all.
        $website = $this->websiteWithRunup([12, 30]);
        $check = $this->failedCheck($website, null, 20000, 'Connection timed out');

        $this->assertNull(app(OutageCause::class)->diagnose($website, $check));
    }

    public function test_an_old_spike_incident_is_not_correlated_with_a_much_later_outage(): void
    {
        $website = $this->websiteWithRunup([400, 1200, 5000]);

        Incident::create([
            'website_id' => $website->id,
            'type' => 'attack',
            'severity' => 'warning',
            'title' => 'Lonjakan response time',
            'meta' => ['reason' => 'response_time_spike'],
            'status' => 'open',
            'detected_at' => now()->setTime(8, 0),
        ]);

        $check = $this->failedCheck($website, null, 20000, 'Connection timed out');

        $diagnosis = app(OutageCause::class)->diagnose($website, $check);

        $this->assertStringNotContainsString('sebelumnya', $diagnosis['evidence']);
    }

    public function test_the_diagnosis_is_stored_on_the_incident_and_shown_as_a_suspicion(): void
    {
        $website = $this->websiteWithRunup([320, 1500, 6800]);
        $check = $this->failedCheck($website, null, 20000, 'Connection timed out');

        $diagnosis = app(OutageCause::class)->diagnose($website, $check);

        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'downtime',
            'severity' => 'critical',
            'title' => 'x',
            'meta' => [
                'consecutive_failures' => 3,
                'likely_cause' => $diagnosis['cause'],
                'cause_evidence' => $diagnosis['evidence'],
                'cause_caveat' => $diagnosis['caveat'],
            ],
            'status' => 'open',
            'detected_at' => now()->setTime(10, 30),
        ]);

        $lines = $incident->detailLines();

        $this->assertArrayHasKey('Dugaan Penyebab', $lines, 'labelled a suspicion, not a verdict');
        $this->assertArrayHasKey('Dasar Dugaan', $lines);
        $this->assertArrayHasKey('Catatan', $lines);
    }

    public function test_incidents_without_a_diagnosis_show_no_cause_lines_at_all(): void
    {
        $website = Website::factory()->create();

        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'downtime',
            'severity' => 'critical',
            'title' => 'x',
            'meta' => ['consecutive_failures' => 3],
            'status' => 'open',
            'detected_at' => now(),
        ]);

        $lines = $incident->detailLines();

        $this->assertArrayNotHasKey('Dugaan Penyebab', $lines);
        $this->assertArrayNotHasKey('Dasar Dugaan', $lines);
    }
}
