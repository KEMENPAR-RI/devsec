<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentTimingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_outage_window_is_bounded_by_the_last_good_and_first_failed_check(): void
    {
        $website = Website::factory()->create();

        $website->checks()->createMany([
            ['is_up' => true, 'status_code' => 200, 'response_time_ms' => 120, 'checked_at' => now()->setTime(10, 0)],
            ['is_up' => false, 'status_code' => null, 'response_time_ms' => 0, 'checked_at' => now()->setTime(10, 5)],
            ['is_up' => false, 'status_code' => null, 'response_time_ms' => 0, 'checked_at' => now()->setTime(10, 10)],
        ]);

        $incident = Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now()->setTime(10, 10),
        ]);

        $window = $incident->outageWindow();

        $this->assertSame('10:00', $window['from']->format('H:i'), 'last check that still succeeded');
        $this->assertSame('10:05', $window['to']->format('H:i'), 'first check that failed after it');
        $this->assertArrayHasKey('Mulai Antara', $incident->detailLines());
    }

    public function test_the_outage_window_reports_only_the_first_failure_when_the_site_was_never_seen_up(): void
    {
        $website = Website::factory()->create();
        $website->checks()->create(['is_up' => false, 'status_code' => null, 'response_time_ms' => 0, 'checked_at' => now()->setTime(9, 30)]);

        $incident = Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now()->setTime(9, 40),
        ]);

        $this->assertNull($incident->outageWindow()['from']);
        $this->assertStringContainsString('Gagal pertama kali tercatat', $incident->detailLines()['Mulai Antara']);
    }

    public function test_non_downtime_incidents_have_no_outage_window(): void
    {
        $website = Website::factory()->create();
        $incident = Incident::create([
            'website_id' => $website->id, 'type' => 'ssl', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now(),
        ]);

        $this->assertNull($incident->outageWindow());
    }
}
