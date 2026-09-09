<?php

namespace Tests\Feature;

use App\Livewire\Websites\Index;
use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteListFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        return User::factory()->create();
    }

    /** A site the filter can single out by status - down, but not yet long enough to read as offline. */
    protected function down(string $name): Website
    {
        $website = Website::factory()->create(['name' => $name, 'last_status' => 'down']);

        Incident::create([
            'website_id' => $website->id,
            'type' => 'downtime',
            'severity' => 'critical',
            'title' => 'Tidak merespons',
            'status' => 'open',
            'detected_at' => now(),
        ]);

        return $website;
    }

    public function test_the_status_filter_keeps_only_matching_sites(): void
    {
        Website::factory()->create(['name' => 'Situs Sehat', 'last_status' => 'up']);
        $this->down('Situs Mati');

        Livewire::actingAs($this->actingUser())
            ->test(Index::class)
            ->set('status', 'down')
            ->assertSee('Situs Mati')
            ->assertDontSee('Situs Sehat');
    }

    public function test_the_status_filter_matches_the_displayed_status_not_the_raw_column(): void
    {
        // Both are last_status=down; only one is offline, and the filter must tell
        // them apart the same way the dot does.
        $fresh = Website::factory()->create(['name' => 'Baru Down', 'last_status' => 'down']);
        Incident::create([
            'website_id' => $fresh->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now(),
        ]);

        $old = Website::factory()->create(['name' => 'Lama Offline', 'last_status' => 'down']);
        Incident::create([
            'website_id' => $old->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now()->subDays(3),
        ]);

        Livewire::actingAs($this->actingUser())
            ->test(Index::class)
            ->set('status', 'offline')
            ->assertSee('Lama Offline')
            ->assertDontSee('Baru Down');
    }

    public function test_the_activity_filter_separates_monitored_from_paused_sites(): void
    {
        Website::factory()->create(['name' => 'Portal Aktif', 'is_active' => true]);
        Website::factory()->create(['name' => 'Portal Istirahat', 'is_active' => false]);

        Livewire::actingAs($this->actingUser())
            ->test(Index::class)
            ->set('activity', 'inactive')
            ->assertSee('Portal Istirahat')
            ->assertDontSee('Portal Aktif');
    }

    public function test_filters_combine_with_the_search_box(): void
    {
        $this->down('Dinas Satu');
        $this->down('Dinas Dua');
        Website::factory()->create(['name' => 'Dinas Tiga', 'last_status' => 'up']);

        Livewire::actingAs($this->actingUser())
            ->test(Index::class)
            ->set('search', 'Dinas')
            ->set('status', 'down')
            ->assertSee('Dinas Satu')
            ->assertSee('Dinas Dua')
            ->assertDontSee('Dinas Tiga');
    }

    public function test_the_select_all_count_respects_the_filter(): void
    {
        // Otherwise "pilih semua (12)" ticks sites the filter is hiding, and a mass
        // edit silently changes them.
        $this->down('Mati');
        Website::factory()->count(3)->create(['last_status' => 'up']);

        Livewire::actingAs($this->actingUser())
            ->test(Index::class)
            ->set('status', 'down')
            ->assertViewHas('filteredCount', 1);
    }

    public function test_clearing_the_filters_brings_everything_back(): void
    {
        Website::factory()->create(['name' => 'Situs Sehat', 'last_status' => 'up']);

        Livewire::actingAs($this->actingUser())
            ->test(Index::class)
            ->set('status', 'down')
            ->assertDontSee('Situs Sehat')
            ->call('clearFilters')
            ->assertSee('Situs Sehat')
            ->assertSet('status', '')
            ->assertSet('search', '');
    }

    public function test_changing_a_filter_drops_selections_that_are_no_longer_visible(): void
    {
        $healthy = Website::factory()->create(['last_status' => 'up']);
        $this->down('Mati');

        Livewire::actingAs($this->actingUser())
            ->test(Index::class)
            ->set('selected', [$healthy->uuid])
            ->set('status', 'down')
            ->assertSet('selected', []);
    }
}
