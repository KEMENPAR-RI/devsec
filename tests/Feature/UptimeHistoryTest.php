<?php

namespace Tests\Feature;

use App\Livewire\Websites\Index as WebsitesIndex;
use App\Livewire\Websites\Show;
use App\Models\Incident;
use App\Models\Role;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Services\Monitoring\UptimeHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Site detail uptime history (UptimeHistory + Show computed props) and the
 * list page's batched uptime column.
 */
class UptimeHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-18 12:00:00'));
        $this->admin = User::factory()->create();
    }

    // --- Uptime summary -------------------------------------------------------

    public function test_the_summary_is_null_for_every_period_without_checks(): void
    {
        $website = Website::factory()->create();

        $summary = $this->show($website)->instance()->uptimeSummary;

        $this->assertSame(['24h' => null, '7d' => null, '30d' => null], $summary);
    }

    public function test_each_period_only_counts_its_own_window(): void
    {
        $website = Website::factory()->create();
        $this->checkAt($website, now()->subHours(2), true);
        $this->checkAt($website, now()->subHours(3), false);
        $this->checkAt($website, now()->subDays(3), true);
        $this->checkAt($website, now()->subDays(3), true);
        $this->checkAt($website, now()->subDays(20), false);
        $this->checkAt($website, now()->subDays(40), false);
        // Another site's failures must not leak in.
        $this->checkAt(Website::factory()->create(), now()->subHour(), false);

        $summary = app(UptimeHistory::class)->summary($website->id);

        $this->assertSame(50.0, $summary['24h']);
        $this->assertSame(75.0, $summary['7d']);
        $this->assertSame(60.0, $summary['30d']);
    }

    public function test_a_period_with_only_older_checks_is_null_not_zero(): void
    {
        $website = Website::factory()->create();
        $this->checkAt($website, now()->subDays(5), true);

        $summary = app(UptimeHistory::class)->summary($website->id);

        $this->assertNull($summary['24h']);
        $this->assertSame(100.0, $summary['7d']);
    }

    // --- Downtimes -----------------------------------------------------------

    public function test_downtimes_are_newest_first_with_duration_and_cause(): void
    {
        $website = Website::factory()->create();
        $old = $this->downtime($website, now()->subDays(10), now()->subDays(10)->addMinutes(30), [
            'error_type' => 'timeout',
            'error_message' => 'cURL error 28: Operation timed out',
            'status_code' => null,
        ]);
        $recent = $this->downtime($website, now()->subDays(2), now()->subDays(2)->addHours(2), [
            'error_type' => 'http',
            'error_message' => 'HTTP 503',
            'status_code' => 503,
        ]);

        $rows = $this->show($website)->instance()->downtimes;

        $this->assertSame([$recent->uuid, $old->uuid], array_column($rows, 'uuid'));
        $this->assertSame(7200, $rows[0]['duration_seconds']);
        $this->assertFalse($rows[0]['ongoing']);
        $this->assertSame('http', $rows[0]['cause']);
        $this->assertSame('HTTP 503', $rows[0]['cause_detail']);
        $this->assertSame(503, $rows[0]['status_code']);
        $this->assertSame(1800, $rows[1]['duration_seconds']);
        $this->assertSame('timeout', $rows[1]['cause']);
        $this->assertNull($rows[1]['status_code']);
    }

    public function test_an_open_downtime_is_ongoing_and_measured_to_now(): void
    {
        $website = Website::factory()->create();
        $this->downtime($website, now()->subMinutes(45), null, ['error_type' => 'dns']);

        $row = app(UptimeHistory::class)->downtimes($website->id)[0];

        $this->assertTrue($row['ongoing']);
        $this->assertNull($row['ended_at']);
        $this->assertSame(2700, $row['duration_seconds']);
    }

    public function test_the_duration_starts_at_the_first_failed_check_not_at_detection(): void
    {
        $website = Website::factory()->create();
        // Detected 10 minutes after the streak began (threshold x interval).
        $this->downtime($website, now()->subMinutes(70), now(), [], detectedAt: now()->subMinutes(60));

        $row = app(UptimeHistory::class)->downtimes($website->id)[0];

        $this->assertSame(4200, $row['duration_seconds']);
        $this->assertTrue($row['started_at']->equalTo(now()->subMinutes(70)));
    }

    public function test_a_legacy_incident_without_first_failed_at_falls_back_to_creation_time(): void
    {
        $website = Website::factory()->create();
        $incident = Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical', 'title' => 'Down',
            'meta' => ['error_message' => 'HTTP 500'], 'status' => 'resolved',
            'detected_at' => now()->subHour(), 'resolved_at' => now()->subMinutes(30),
        ]);
        $incident->forceFill(['created_at' => now()->subHour()])->save();

        $row = app(UptimeHistory::class)->downtimes($website->id)[0];

        $this->assertSame(1800, $row['duration_seconds']);
        $this->assertNull($row['cause'], 'no error_type on incidents opened before it existed');
        $this->assertSame('HTTP 500', $row['cause_detail']);
    }

    public function test_the_likely_cause_is_the_detail_when_no_error_message(): void
    {
        $website = Website::factory()->create();
        $this->downtime($website, now()->subHour(), now(), ['likely_cause' => 'Server kewalahan']);

        $this->assertSame('Server kewalahan', app(UptimeHistory::class)->downtimes($website->id)[0]['cause_detail']);
    }

    public function test_downtimes_skip_other_types_other_sites_and_old_resolved_ones(): void
    {
        $website = Website::factory()->create();
        $this->downtime($website, now()->subDays(100), now()->subDays(99), [], detectedAt: now()->subDays(100));
        $stillOpen = $this->downtime($website, now()->subDays(120), null, [], detectedAt: now()->subDays(120));
        $this->downtime(Website::factory()->create(), now()->subDay(), now(), []);
        Incident::create([
            'website_id' => $website->id, 'type' => 'attack', 'severity' => 'warning', 'title' => 'Spike',
            'status' => 'open', 'detected_at' => now()->subHour(),
        ]);

        $rows = app(UptimeHistory::class)->downtimes($website->id);

        $this->assertSame([$stillOpen->uuid], array_column($rows, 'uuid'));
    }

    // --- 30-day downtime total -------------------------------------------------

    public function test_the_30_day_total_clips_incidents_to_the_window(): void
    {
        $website = Website::factory()->create();
        // Began 31 days ago, ended 29 days ago: only the last 1 day of it counts.
        $this->downtime($website, now()->subDays(31), now()->subDays(29), [], detectedAt: now()->subDays(31));
        // Fully inside: 30 minutes.
        $this->downtime($website, now()->subDays(5), now()->subDays(5)->addMinutes(30), []);
        // Ongoing for 2 hours.
        $this->downtime($website, now()->subHours(2), null, []);
        // Ended before the window: ignored.
        $this->downtime($website, now()->subDays(40), now()->subDays(35), [], detectedAt: now()->subDays(40));

        $total = $this->show($website)->instance()->downtimeTotal30d;

        $this->assertSame(86400 + 1800 + 7200, $total);
    }

    public function test_the_30_day_total_is_zero_without_downtime(): void
    {
        $this->assertSame(0, app(UptimeHistory::class)->downtimeSeconds(Website::factory()->create()->id));
    }

    // --- Verification badge ------------------------------------------------------

    public function test_the_verification_badge_shows_while_a_failure_is_unconfirmed(): void
    {
        $website = Website::factory()->create(['last_status' => 'up', 'consecutive_failures' => 1, 'down_threshold_failures' => 3]);

        $component = $this->show($website);

        $this->assertSame(['failures' => 1, 'threshold' => 3], $component->instance()->verification);
        $component->assertSee('Memverifikasi 1/3');
    }

    public function test_the_verification_badge_is_hidden_when_healthy_or_confirmed_down(): void
    {
        $healthy = Website::factory()->create(['last_status' => 'up', 'consecutive_failures' => 0]);
        $down = Website::factory()->create(['last_status' => 'down', 'consecutive_failures' => 3, 'down_threshold_failures' => 3]);

        foreach ([$healthy, $down] as $website) {
            $component = $this->show($website);
            $this->assertNull($component->instance()->verification);
            $component->assertDontSee('Memverifikasi');
        }
    }

    // --- Page rendering / range ---------------------------------------------------

    public function test_the_page_renders_dashes_without_data(): void
    {
        $website = Website::factory()->create();

        $this->show($website)
            ->assertOk()
            ->assertSee('Uptime 24 jam')
            ->assertSee('Tidak ada downtime dalam 90 hari terakhir.')
            ->assertSee('Belum ada data pengecekan.')
            ->assertDontSee('0%');
    }

    public function test_the_page_lists_downtimes_and_recent_checks(): void
    {
        $website = Website::factory()->create();
        $this->downtime($website, now()->subMinutes(10), null, ['error_type' => 'timeout', 'error_message' => 'cURL error 28']);
        WebsiteCheck::create(['website_id' => $website->id, 'is_up' => false, 'response_time_ms' => 20000, 'error_type' => 'timeout', 'checked_at' => now()->subMinute()]);

        $this->show($website)
            ->assertSee('Masih down')
            ->assertSee('Penyebab: Timeout')
            ->assertSee('20,000ms');
    }

    public function test_the_range_is_whitelisted(): void
    {
        $component = $this->show(Website::factory()->create());

        $component->set('range', '7d')->assertSet('range', '7d');
        $component->set('range', 'bogus')->assertSet('range', '24h');
        $component->set('range', '365d')->assertSet('range', '24h');
    }

    public function test_a_non_string_range_is_rejected(): void
    {
        $this->show(Website::factory()->create())
            ->set('range', ['x'])
            ->assertStatus(422);
    }

    public function test_the_range_chart_metrics_follow_the_range(): void
    {
        $website = Website::factory()->create();
        $this->checkAt($website, now()->subMinutes(10), true, 100);
        $this->checkAt($website, now()->subDays(3), false, 900);

        $component = $this->show($website);
        $this->assertSame(100.0, (float) $component->instance()->rangeMetrics['uptime']);

        $component->set('range', '7d');
        $this->assertSame(50.0, (float) $component->instance()->rangeMetrics['uptime']);
    }

    public function test_the_page_is_forbidden_without_view_permission(): void
    {
        $user = User::factory()->create(['role_id' => Role::factory()->withPermissions(['dashboard.view'])->create()->id]);

        $this->actingAs($user)->get(route('situs.show', Website::factory()->create()))->assertForbidden();
    }

    // --- List page uptime column -----------------------------------------------

    public function test_the_list_shows_a_dash_for_sites_without_checks(): void
    {
        $measured = Website::factory()->create(['name' => 'Measured']);
        Website::factory()->create(['name' => 'Fresh']);
        $this->checkAt($measured, now()->subHour(), true);
        $this->checkAt($measured, now()->subHours(2), true);
        $this->checkAt($measured, now()->subHours(3), true);
        $this->checkAt($measured, now()->subHours(4), false);

        Livewire::actingAs($this->admin)->test(WebsitesIndex::class)
            ->assertSee('75% uptime')
            ->assertSee('—')
            ->assertDontSee('0% uptime');
    }

    public function test_the_list_uptime_costs_one_query_however_many_rows(): void
    {
        $counts = [];

        foreach ([1, 6] as $sites) {
            Website::query()->delete();
            Website::factory()->count($sites)->create()->each(fn (Website $w) => $this->checkAt($w, now()->subHour(), true));

            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($this->admin)->test(WebsitesIndex::class);
            // The role lookup is memoised on the user after the first pass.
            $log = collect(DB::getQueryLog())->pluck('query')->reject(fn ($q) => str_contains($q, '"roles"'));
            DB::disableQueryLog();

            $counts[$sites] = [
                'uptime' => $log->filter(fn ($q) => str_contains($q, 'up_count'))->count(),
                'total' => $log->count(),
            ];
        }

        $this->assertSame(1, $counts[1]['uptime']);
        $this->assertSame(1, $counts[6]['uptime']);
        $this->assertSame($counts[1]['total'], $counts[6]['total'], 'no per-row queries on the list page');
    }

    // --- Helpers -----------------------------------------------------------------

    protected function show(Website $website)
    {
        return Livewire::actingAs($this->admin)->test(Show::class, ['website' => $website]);
    }

    protected function checkAt(Website $website, Carbon $at, bool $up, int $ms = 200): void
    {
        WebsiteCheck::create([
            'website_id' => $website->id,
            'is_up' => $up,
            'status_code' => $up ? 200 : 500,
            'response_time_ms' => $ms,
            'checked_at' => $at,
        ]);
    }

    protected function downtime(Website $website, Carbon $firstFailedAt, ?Carbon $resolvedAt, array $meta, ?Carbon $detectedAt = null): Incident
    {
        return Incident::create([
            'website_id' => $website->id,
            'type' => 'downtime',
            'severity' => 'critical',
            'title' => 'Down',
            'meta' => ['first_failed_at' => $firstFailedAt->toIso8601String(), ...$meta],
            'status' => $resolvedAt ? 'resolved' : 'open',
            'detected_at' => $detectedAt ?? $firstFailedAt,
            'resolved_at' => $resolvedAt,
        ]);
    }
}
