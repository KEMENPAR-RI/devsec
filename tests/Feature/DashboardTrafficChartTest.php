<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTrafficChartTest extends TestCase
{
    use RefreshDatabase;

    /** Pin both zones to UTC so bucket edges in these tests are known. */
    protected function pinClock(string $at): void
    {
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        Setting::set('app_timezone', 'UTC');
        $this->travelTo(Carbon::parse($at, 'UTC'));
    }

    public function test_weekly_uptime_aggregates_correctly_across_all_websites(): void
    {
        // Regression: the 7-day view must come from one grouped aggregate, and the
        // grouping has to put every check in the right bucket across all sites.
        $this->pinClock('2026-10-18 13:00:00');
        $siteA = Website::factory()->create();
        $siteB = Website::factory()->create();

        // Current 6h bucket (12:00-18:00): 3 up, 1 down across both sites -> 75%.
        $siteA->checks()->createMany([
            ['is_up' => true, 'status_code' => 200, 'response_time_ms' => 100, 'checked_at' => now()->setTime(12, 10)],
            ['is_up' => true, 'status_code' => 200, 'response_time_ms' => 100, 'checked_at' => now()->setTime(12, 20)],
        ]);
        $siteB->checks()->createMany([
            ['is_up' => true, 'status_code' => 200, 'response_time_ms' => 100, 'checked_at' => now()->setTime(12, 30)],
            ['is_up' => false, 'status_code' => 500, 'response_time_ms' => 100, 'checked_at' => now()->setTime(12, 40)],
        ]);

        // Previous bucket (06:00-12:00): all up -> 100%.
        $siteA->checks()->create([
            'is_up' => true, 'status_code' => 200, 'response_time_ms' => 100, 'checked_at' => now()->setTime(7, 0),
        ]);

        $dashboard = Livewire::actingAs(User::factory()->create())
            ->test(Dashboard::class)
            ->set('range', '7d')
            ->instance();

        $series = $dashboard->series;

        $this->assertCount(28, $series);
        $this->assertSame(75.0, $series[27]['uptime']);
        $this->assertFalse($series[27]['up']);
        $this->assertSame(100.0, $series[26]['uptime']);
        $this->assertTrue($series[26]['up']);
        // A bucket with zero checks is "no data", not 100% or 0%.
        $this->assertNull($series[0]['uptime']);
        $this->assertNull($series[0]['up']);
        // Range-wide totals come from the same buckets: 4 of 5 up.
        $this->assertSame(80.0, $dashboard->metrics['uptime']);
        $this->assertSame(100, $dashboard->metrics['avg_ms']);
    }

    public function test_the_chart_opens_on_the_all_sites_average(): void
    {
        // No pick, but the panel still carries something: the dashboard's own
        // question is "how is everything doing", and the per-site view is the
        // drill-down. Waiting on a combobox left the largest panel on the page blank.
        $user = User::factory()->create();
        $website = Website::factory()->create();
        $website->checks()->create([
            'is_up' => true, 'status_code' => 200, 'response_time_ms' => 420, 'checked_at' => now()->subHour(),
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSee('Semua situs')
            ->assertSee('420ms')
            // Each site sets its own threshold, so the averaged view has no single
            // line to draw against.
            ->assertDontSee('Ambang batas');
    }

    public function test_the_all_sites_average_marks_an_hour_that_carried_a_failure(): void
    {
        // The mean alone would bury one site's outage under every other site that
        // answered fine, so the bucket is flagged from the failures, not the average.
        $this->pinClock('2026-10-18 13:30:00');
        $healthy = Website::factory()->create();
        $broken = Website::factory()->create();

        $healthy->checks()->createMany([
            ['is_up' => true, 'status_code' => 200, 'response_time_ms' => 200, 'checked_at' => now()->setTime(12, 15)],
            ['is_up' => true, 'status_code' => 200, 'response_time_ms' => 200, 'checked_at' => now()->setTime(13, 5)],
        ]);
        // A timeout: no status code, no latency - still a failed check.
        $broken->checks()->create([
            'is_up' => false, 'status_code' => null, 'response_time_ms' => null, 'checked_at' => now()->setTime(13, 10),
        ]);

        $series = Livewire::actingAs(User::factory()->create())->test(Dashboard::class)->instance()->series;

        $this->assertCount(24, $series);
        $this->assertFalse($series[23]['up']);
        $this->assertSame(50.0, $series[23]['uptime']);
        // The timeout has no latency, so it must not drag the average to zero.
        $this->assertSame(200, $series[23]['ms']);
        $this->assertTrue($series[22]['up']);
    }

    public function test_the_chart_shows_an_empty_state_when_nothing_has_been_checked(): void
    {
        $user = User::factory()->create();
        Website::factory()->create();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSee('Belum ada data pengecekan dalam 24 jam terakhir.')
            ->set('range', '30d')
            ->assertSee('Belum ada data pengecekan dalam 30 hari terakhir.');
    }

    public function test_the_chart_shows_an_empty_state_when_no_site_is_monitored(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Dashboard::class)
            ->assertSee('Belum ada situs yang dipantau.')
            ->assertDontSee('Belum ada data pengecekan');
    }

    public function test_selecting_a_website_renders_its_traffic_chart(): void
    {
        $this->pinClock('2026-10-18 13:30:00');
        $user = User::factory()->create();
        $website = Website::factory()->create(['response_time_threshold_ms' => 3000]);
        $other = Website::factory()->create();

        $website->checks()->createMany([
            ['is_up' => true, 'status_code' => 200, 'response_time_ms' => 120, 'checked_at' => now()->subMinutes(10)],
            ['is_up' => true, 'status_code' => 200, 'response_time_ms' => 4200, 'checked_at' => now()->subMinutes(5)],
            ['is_up' => false, 'status_code' => 500, 'response_time_ms' => 80, 'checked_at' => now()->subMinute()],
        ]);
        $other->checks()->create([
            'is_up' => true, 'status_code' => 200, 'response_time_ms' => 9000, 'checked_at' => now()->subMinutes(2),
        ]);

        $component = Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('selectWebsite', $website->uuid)
            ->assertSeeHtml(e($website->name).' &middot; rata-rata per jam, 24 jam terakhir')
            ->assertSee('Ambang batas 3,000ms')
            ->assertDontSee('Belum ada data pengecekan');

        // Only the picked site's checks feed the chart: (120 + 4200 + 80) / 3.
        $this->assertSame(1467, $component->instance()->series[23]['ms']);
        $this->assertSame(1467, $component->instance()->metrics['avg_ms']);
    }

    public function test_selecting_a_website_with_no_checks_shows_empty_state_without_crashing(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('selectWebsite', $website->uuid)
            ->assertSee("Belum ada data pengecekan untuk {$website->name}");
    }

    public function test_a_website_with_a_single_check_renders_without_a_division_by_zero(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();
        $website->checks()->create(['is_up' => true, 'status_code' => 200, 'response_time_ms' => 150, 'checked_at' => now()]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('selectWebsite', $website->uuid)
            ->assertOk()
            ->assertSee('150ms');
    }

    public function test_picking_the_placeholder_option_deselects_the_website(): void
    {
        // Driven by the <select>'s wire:change="selectWebsite($event.target.value)" -
        // the placeholder option's value is "", not a website id.
        $user = User::factory()->create();
        $website = Website::factory()->create();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('selectWebsite', $website->uuid)
            ->assertSet('selectedWebsiteUuid', $website->uuid)
            ->call('selectWebsite', '')
            ->assertSet('selectedWebsiteUuid', null)
            ->assertSee('Semua situs');
    }

    public function test_the_picker_is_disabled_when_there_are_no_websites(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSee('Belum ada situs')
            ->assertSeeHtml('disabled');
    }
}
