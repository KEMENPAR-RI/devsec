<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\JudolDetection;
use App\Models\JudolKeyword;
use App\Models\Website;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
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
            'website_id' => $website->id, 'type' => 'judol', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now(),
        ]);

        $this->assertNull($incident->outageWindow());
    }

    public function test_a_flagged_page_records_the_last_modified_header_the_server_sent(): void
    {
        // The page's own Last-Modified is the closest thing to "when was this spam
        // injected" - detected_at only says when the crawl happened to notice.
        NotificationFacade::fake();
        JudolKeyword::create(['keyword' => 'frasa waktu tes', 'is_active' => true]);

        Http::fake([
            'timing-test.example' => Http::response(
                '<title>Frasa Waktu Tes</title>',
                200,
                ['Last-Modified' => 'Wed, 12 Aug 2026 07:00:00 GMT'],
            ),
        ]);

        $website = Website::factory()->create(['url' => 'http://timing-test.example']);
        (new JudolScanner)->scanUrlNow($website, 'http://timing-test.example');

        $detection = JudolDetection::where('website_id', $website->id)->firstOrFail();

        $this->assertNotNull($detection->page_last_modified_at);
        // 07:00 GMT is the instant the server reported, whatever timezone it is displayed in.
        $this->assertSame('2026-08-12 07:00', $detection->page_last_modified_at->utc()->format('Y-m-d H:i'));
    }

    public function test_a_page_without_the_header_simply_records_no_change_date(): void
    {
        NotificationFacade::fake();
        JudolKeyword::create(['keyword' => 'frasa tanpa header', 'is_active' => true]);

        Http::fake(['no-header-test.example' => Http::response('<title>Frasa Tanpa Header</title>', 200)]);

        $website = Website::factory()->create(['url' => 'http://no-header-test.example']);
        (new JudolScanner)->scanUrlNow($website, 'http://no-header-test.example');

        $this->assertNull(JudolDetection::where('website_id', $website->id)->firstOrFail()->page_last_modified_at);
    }
}
