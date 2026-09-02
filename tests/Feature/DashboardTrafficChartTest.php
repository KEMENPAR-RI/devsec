<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTrafficChartTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_uptime_aggregates_correctly_across_all_websites(): void
    {
        // Regression: weeklyUptime() used to run 7 separate range-scan queries (one
        // per day); refactored to a single grouped aggregate query - this checks the
        // grouping/date-matching logic still produces the right percentage per day.
        $siteA = Website::factory()->create();
        $siteB = Website::factory()->create();

        $today = now()->startOfDay();
        // Today: 3 up, 1 down across both sites -> 75%.
        $siteA->checks()->createMany([
            ['is_up' => true, 'status_code' => 200, 'response_time_ms' => 100, 'checked_at' => $today->copy()->addHours(1)],
            ['is_up' => true, 'status_code' => 200, 'response_time_ms' => 100, 'checked_at' => $today->copy()->addHours(2)],
        ]);
        $siteB->checks()->createMany([
            ['is_up' => true, 'status_code' => 200, 'response_time_ms' => 100, 'checked_at' => $today->copy()->addHours(3)],
            ['is_up' => false, 'status_code' => 500, 'response_time_ms' => 100, 'checked_at' => $today->copy()->addHours(4)],
        ]);

        // Yesterday: all up -> 100%.
        $siteA->checks()->create([
            'is_up' => true, 'status_code' => 200, 'response_time_ms' => 100,
            'checked_at' => $today->copy()->subDay()->addHours(1),
        ]);

        $user = User::factory()->create();
        $uptime = Livewire::actingAs($user)->test(Dashboard::class)->instance()->weeklyUptime();

        $todayEntry = $uptime[count($uptime) - 1];
        $yesterdayEntry = $uptime[count($uptime) - 2];
        $emptyDayEntry = $uptime[0];

        $this->assertSame(75, $todayEntry['value']);
        $this->assertSame(100, $yesterdayEntry['value']);
        // A day with zero checks reads as 100% (nothing observed down), not 0%.
        $this->assertSame(100, $emptyDayEntry['value']);
    }

    public function test_no_website_selected_shows_the_picker_prompt(): void
    {
        $user = User::factory()->create();
        Website::factory()->create();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertSee('Pilih situs di atas')
            ->assertDontSee('Ambang batas');
    }

    public function test_selecting_a_website_renders_its_traffic_chart(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['response_time_threshold_ms' => 3000]);

        $website->checks()->createMany([
            ['is_up' => true, 'status_code' => 200, 'response_time_ms' => 120, 'checked_at' => now()->subMinutes(10)],
            ['is_up' => true, 'status_code' => 200, 'response_time_ms' => 4200, 'checked_at' => now()->subMinutes(5)],
            ['is_up' => false, 'status_code' => 500, 'response_time_ms' => 80, 'checked_at' => now()],
        ]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->call('selectWebsite', $website->uuid)
            ->assertSee($website->url)
            ->assertSee('3 pengecekan terakhir')
            ->assertSee('Ambang batas 3,000ms')
            ->assertDontSee('Belum ada data pengecekan');
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
            ->assertSee('Pilih situs di atas');
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
