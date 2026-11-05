<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\ComponentAttributeBag;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function dashboard(): Dashboard
    {
        return Livewire::actingAs(User::factory()->create())->test(Dashboard::class)->instance();
    }

    /** @return array{total: int, online: int, problem: int, offline: int, breakdown: array<string, int>} */
    protected function summary(): array
    {
        return $this->dashboard()->summary;
    }

    public function test_a_spiking_site_still_counts_as_online(): void
    {
        // A response-time spike is an incident, not a status: the site is answering,
        // and the card that says how many sites are up has to keep saying so.
        $website = Website::factory()->create(['last_status' => 'up']);
        Incident::create([
            'website_id' => $website->id, 'type' => 'attack', 'severity' => 'warning',
            'title' => 'Lonjakan', 'meta' => ['reason' => 'response_time_spike', 'baseline_ms' => 200, 'response_time_ms' => 900],
            'status' => 'open', 'detected_at' => now(),
        ]);

        $summary = $this->summary();

        $this->assertSame(1, $summary['online']);
        $this->assertSame(0, $summary['problem']);
        $this->assertSame(0, $summary['breakdown']['down']);
    }

    public function test_each_outage_flavour_gets_its_own_card(): void
    {
        // They are separate dots for a reason, so they land in separate cards too: a
        // site dark for three days and one that stopped answering a minute ago need
        // different responses, and a single "Down: 2" total hid that.
        foreach ([now(), now()->subDays(3)] as $detectedAt) {
            $website = Website::factory()->create(['last_status' => 'down']);
            Incident::create([
                'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
                'title' => 'x', 'status' => 'open', 'detected_at' => $detectedAt,
            ]);
        }

        $summary = $this->summary();

        $this->assertSame(1, $summary['breakdown']['down']);
        $this->assertSame(1, $summary['breakdown']['offline']);
        $this->assertSame(1, $summary['problem']);
        $this->assertSame(1, $summary['offline']);
    }

    public function test_the_status_cards_add_up_to_the_total(): void
    {
        Website::factory()->count(2)->create(['last_status' => 'up']);
        Website::factory()->create(['last_status' => 'down']);
        Website::factory()->create(['last_status' => 'unknown']);

        $summary = $this->summary();

        // Every site lands on exactly one of Online / Bermasalah / Offline, so the
        // three cards (and the chips inside them) have to account for all of them.
        $this->assertSame(4, $summary['total']);
        $this->assertSame(2, $summary['online']);
        $this->assertSame(1, $summary['problem']);
        $this->assertSame(1, $summary['offline']);
        $this->assertSame($summary['total'], $summary['online'] + $summary['problem'] + $summary['offline']);
        $this->assertSame($summary['total'], array_sum($summary['breakdown']));
    }

    public function test_the_load_level_cards_count_the_sites_that_answered(): void
    {
        // 3x its own baseline reads as heavy - the same grading the list view's
        // "Beban" column shows, so the distribution bar and the column cannot disagree.
        $website = Website::factory()->create(['last_status' => 'up']);

        foreach ([200, 200, 200, 200, 3000] as $i => $ms) {
            $website->checks()->create([
                'is_up' => true, 'status_code' => 200, 'response_time_ms' => $ms,
                'checked_at' => now()->subMinutes(10 - $i),
            ]);
        }

        $this->assertSame(['heavy' => 1], $this->dashboard()->loadCounts);
    }

    public function test_a_downtime_incident_still_links_to_the_incidents_page(): void
    {
        $website = Website::factory()->create();
        Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'Situs tidak dapat diakses', 'status' => 'open', 'detected_at' => now(),
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test(Dashboard::class)
            ->assertSee(route('insiden'), escape: false);
    }

    public function test_the_bar_chart_tooltip_measures_against_the_plot_and_the_bar(): void
    {
        // The two references the positioning depends on: without x-ref="plot" the
        // offsets are computed against the hovered column itself, and every tooltip
        // lands in the same spot regardless of which bar is under the pointer.
        $chart = (string) view('components.bar-chart', [
            'bars' => [['height' => 50, 'class' => 'bg-emerald-500', 'label' => 'Senin', 'value' => '120ms', 'tone' => 'up']],
            'attributes' => new ComponentAttributeBag,
        ]);

        $this->assertStringContainsString('x-ref="plot"', $chart);
        $this->assertStringContainsString('data-bar', $chart);
        $this->assertStringContainsString('$refs.plot', $chart);
        $this->assertStringNotContainsString('this.$el.getBoundingClientRect', $chart);
    }

    public function test_the_chart_is_labelled_response_time_not_traffic(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Dashboard::class)
            ->assertSee('Performa')
            ->assertSee('Rata-rata respons')
            ->assertDontSee('Trafik');
    }
}
