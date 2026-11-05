<?php

namespace Tests\Feature;

use App\Livewire\Websites\Index;
use App\Models\Role;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MassEditWebsitesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new RoleSeeder)->run();
    }

    public function test_a_ticked_field_is_applied_to_every_selected_site(): void
    {
        [$a, $b, $untouched] = [$this->site(), $this->site(), $this->site()];

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('selected', [$a->uuid, $b->uuid])
            ->call('openMassEdit')
            ->set('massApply.responseTimeThresholdMs', true)
            ->set('massResponseTimeThresholdMs', 20000)
            ->call('massSave')
            ->assertHasNoErrors();

        $this->assertSame(20000, $a->fresh()->response_time_threshold_ms);
        $this->assertSame(20000, $b->fresh()->response_time_threshold_ms);
        $this->assertSame(15000, $untouched->fresh()->response_time_threshold_ms, 'a site nobody selected must not move');
    }

    /**
     * The whole reason each field is opt-in: a site tuned deliberately must keep
     * its other settings when a bulk change targets one of them.
     */
    public function test_fields_that_were_not_ticked_are_left_alone(): void
    {
        $site = $this->site(['check_interval_minutes' => 7, 'down_threshold_failures' => 4]);

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('selected', [$site->uuid])
            ->call('openMassEdit')
            ->set('massApply.responseTimeThresholdMs', true)
            ->set('massResponseTimeThresholdMs', 12000)
            ->call('massSave')
            ->assertHasNoErrors();

        $site->refresh();

        $this->assertSame(12000, $site->response_time_threshold_ms);
        $this->assertSame(7, $site->check_interval_minutes, 'an untouched field must survive');
        $this->assertSame(4, $site->down_threshold_failures);
    }

    public function test_saving_with_nothing_ticked_is_refused(): void
    {
        $site = $this->site(['check_interval_minutes' => 7]);

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('selected', [$site->uuid])
            ->call('openMassEdit')
            ->call('massSave')
            ->assertHasErrors('massApply');

        $this->assertSame(7, $site->fresh()->check_interval_minutes);
    }

    /** A bulk change must not be able to write a value the single-site form rejects. */
    public function test_a_ticked_field_is_validated_with_the_same_rules_as_the_normal_form(): void
    {
        $site = $this->site();

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('selected', [$site->uuid])
            ->call('openMassEdit')
            ->set('massApply.responseTimeThresholdMs', true)
            // Below the min of 200 the single-site form enforces.
            ->set('massResponseTimeThresholdMs', 100)
            ->call('massSave')
            ->assertHasErrors('massResponseTimeThresholdMs');

        $this->assertSame(15000, $site->fresh()->response_time_threshold_ms);
    }

    /**
     * A value typed into a field whose toggle is off must not block the save - it
     * is not being applied, so it is none of validation's business.
     */
    public function test_an_invalid_value_in_an_unticked_field_does_not_block_the_save(): void
    {
        $site = $this->site();

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('selected', [$site->uuid])
            ->call('openMassEdit')
            ->set('massApply.checkIntervalMinutes', true)
            ->set('massCheckIntervalMinutes', 10)
            ->set('massResponseTimeThresholdMs', 100)
            ->call('massSave')
            ->assertHasNoErrors();

        $this->assertSame(10, $site->fresh()->check_interval_minutes);
    }

    public function test_select_all_covers_every_site_the_search_matches(): void
    {
        $this->site(['name' => 'Portal Satu']);
        $this->site(['name' => 'Portal Dua']);
        $this->site(['name' => 'Lain Sendiri']);

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('search', 'Portal')
            ->call('toggleSelectAll', true)
            ->assertCount('selected', 2);
    }

    /**
     * Otherwise a site ticked under an earlier search stays selected while
     * off-screen, and the next mass edit changes it without anyone seeing it.
     */
    public function test_changing_the_search_clears_the_selection(): void
    {
        $site = $this->site(['name' => 'Portal Satu']);

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('selected', [$site->uuid])
            ->set('search', 'sesuatu yang lain')
            ->assertCount('selected', 0);
    }

    public function test_a_user_without_update_permission_cannot_mass_edit(): void
    {
        $site = $this->site(['check_interval_minutes' => 7]);

        Livewire::actingAs($this->withoutPermissions())
            ->test(Index::class)
            ->set('selected', [$site->uuid])
            ->set('massApply.checkIntervalMinutes', true)
            ->set('massCheckIntervalMinutes', 30)
            ->call('massSave')
            ->assertForbidden();

        $this->assertSame(7, $site->fresh()->check_interval_minutes);
    }

    public function test_the_boolean_toggles_can_be_applied_in_bulk(): void
    {
        $a = $this->site(['is_active' => true]);
        $b = $this->site(['is_active' => true]);

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('selected', [$a->uuid, $b->uuid])
            ->call('openMassEdit')
            ->set('massApply.isActive', true)
            ->set('massIsActive', false)
            ->call('massSave')
            ->assertHasNoErrors();

        $this->assertFalse($a->fresh()->is_active);
        $this->assertFalse($b->fresh()->is_active);
    }

    private function site(array $attributes = []): Website
    {
        static $n = 0;
        $n++;

        return Website::create(array_merge([
            'name' => "Situs {$n}",
            'url' => "https://situs-{$n}.test",
            'check_interval_minutes' => 5,
            'down_threshold_failures' => 3,
            'response_time_threshold_ms' => 15000,
            'is_active' => true,
            'is_internal_only' => false,
        ], $attributes));
    }

    private function superadmin(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id')]);
    }

    /**
     * The seeded read-only role: it can open the list (situs.view) but must not be
     * able to write through the mass edit. A user with no permissions at all would
     * fail mount()'s own authorize() instead, which tests the wrong guard.
     */
    private function withoutPermissions(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);
    }
}
