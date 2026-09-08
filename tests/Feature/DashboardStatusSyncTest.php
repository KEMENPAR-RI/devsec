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

    protected function statCard(array $stats, string $label): ?array
    {
        return collect($stats)->firstWhere('label', $label);
    }

    protected function stats(): array
    {
        $component = Livewire::actingAs(User::factory()->create())->test(Dashboard::class);

        return $component->viewData('stats');
    }

    public function test_a_spiking_site_counts_as_slow_not_online(): void
    {
        $website = Website::factory()->create(['last_status' => 'up']);
        Incident::create([
            'website_id' => $website->id, 'type' => 'attack', 'severity' => 'warning',
            'title' => 'Lonjakan', 'meta' => ['reason' => 'response_time_spike', 'baseline_ms' => 200, 'response_time_ms' => 900],
            'status' => 'open', 'detected_at' => now(),
        ]);

        $stats = $this->stats();

        $this->assertSame('1', $this->statCard($stats, 'Slow')['value']);
        $this->assertSame('0', $this->statCard($stats, 'Online')['value']);
    }

    public function test_offline_and_attacked_sites_are_still_counted_as_down(): void
    {
        // They are separate dots, but an operator reading "Down: 2" expects every
        // site that is not serving to be in that number.
        foreach ([now(), now()->subDays(3)] as $detectedAt) {
            $website = Website::factory()->create(['last_status' => 'down']);
            Incident::create([
                'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
                'title' => 'x', 'status' => 'open', 'detected_at' => $detectedAt,
            ]);
        }

        $this->assertSame('2', $this->statCard($this->stats(), 'Down')['value']);
    }

    public function test_the_cards_add_up_to_the_total(): void
    {
        Website::factory()->count(2)->create(['last_status' => 'up']);
        Website::factory()->create(['last_status' => 'down']);
        Website::factory()->create(['last_status' => 'unknown']);

        $stats = $this->stats();
        $total = (int) $this->statCard($stats, 'Total Situs')['value'];
        $counted = (int) $this->statCard($stats, 'Online')['value']
            + (int) $this->statCard($stats, 'Slow')['value']
            + (int) $this->statCard($stats, 'Down')['value'];

        // The remainder is the never-checked site, which belongs to no card.
        $this->assertSame(4, $total);
        $this->assertSame(3, $counted);
    }

    public function test_a_judol_incident_links_to_the_judol_page(): void
    {
        $website = Website::factory()->create();
        Incident::create([
            'website_id' => $website->id, 'type' => 'judol', 'severity' => 'critical',
            'title' => 'Konten judi terdeteksi', 'status' => 'open', 'detected_at' => now(),
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test(Dashboard::class)
            ->assertSee(route('deteksi-judol'), escape: false);
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
            ->assertSee('Response Time')
            ->assertDontSee('Trafik');
    }
}
