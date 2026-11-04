<?php

namespace Tests\Feature;

use App\Livewire\Websites\Index;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Role;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WebsitePriorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new RoleSeeder)->run();
    }

    public function test_creating_a_website_can_mark_it_priority(): void
    {
        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->call('openCreate')
            ->assertSet('isPriority', false)
            ->set('name', 'Portal Utama')
            ->set('url', 'https://utama.example')
            ->set('isPriority', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('websites', ['name' => 'Portal Utama', 'is_priority' => true]);
    }

    public function test_editing_preloads_and_persists_the_priority_flag(): void
    {
        $website = Website::factory()->create(['is_priority' => true]);

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->call('openEdit', $website->uuid)
            ->assertSet('isPriority', true)
            ->set('isPriority', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($website->fresh()->is_priority);
    }

    public function test_open_create_resets_priority_after_editing_a_priority_site(): void
    {
        $website = Website::factory()->create(['is_priority' => true]);

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->call('openEdit', $website->uuid)
            ->call('openCreate')
            ->assertSet('isPriority', false);
    }

    public function test_priority_can_be_applied_in_bulk(): void
    {
        $a = Website::factory()->create(['is_priority' => false]);
        $b = Website::factory()->create(['is_priority' => false]);
        $untouched = Website::factory()->create(['is_priority' => false]);

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('selected', [$a->uuid, $b->uuid])
            ->call('openMassEdit')
            ->set('massApply.isPriority', true)
            ->set('massIsPriority', true)
            ->call('massSave')
            ->assertHasNoErrors();

        $this->assertTrue($a->fresh()->is_priority);
        $this->assertTrue($b->fresh()->is_priority);
        $this->assertFalse($untouched->fresh()->is_priority);
    }

    public function test_an_unticked_priority_field_is_left_alone_by_mass_edit(): void
    {
        $site = Website::factory()->create(['is_priority' => true, 'check_interval_minutes' => 5]);

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('selected', [$site->uuid])
            ->call('openMassEdit')
            ->set('massApply.checkIntervalMinutes', true)
            ->set('massCheckIntervalMinutes', 10)
            ->call('massSave')
            ->assertHasNoErrors();

        $this->assertTrue($site->fresh()->is_priority);
    }

    public function test_unknown_mass_apply_keys_are_ignored(): void
    {
        $site = Website::factory()->create(['is_priority' => false]);

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('selected', [$site->uuid])
            ->call('openMassEdit')
            ->set('massApply.foo', true)
            ->set('massApply.isPriority', true)
            ->set('massIsPriority', true)
            ->call('massSave')
            ->assertHasNoErrors();

        $this->assertTrue($site->fresh()->is_priority);
    }

    public function test_only_unknown_mass_apply_keys_count_as_nothing_ticked(): void
    {
        $site = Website::factory()->create(['is_priority' => false]);

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('selected', [$site->uuid])
            ->call('openMassEdit')
            ->set('massApply.foo', true)
            ->call('massSave')
            ->assertHasErrors('massApply');
    }

    public function test_mass_edit_writes_one_audit_line(): void
    {
        $a = Website::factory()->create(['is_priority' => false]);
        $b = Website::factory()->create(['is_priority' => false]);
        $before = AuditLog::count();

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('selected', [$a->uuid, $b->uuid])
            ->call('openMassEdit')
            ->set('massApply.isPriority', true)
            ->set('massIsPriority', true)
            ->call('massSave');

        $this->assertSame($before + 1, AuditLog::count());
        $log = AuditLog::latest('id')->first();
        $this->assertSame('situs.diubah-massal', $log->event);
        $this->assertSame('2 situs', $log->subject);
        $this->assertTrue($log->changes['is_priority']);
        $this->assertEqualsCanonicalizing([$a->uuid, $b->uuid], $log->changes['situs']);
    }

    public function test_mass_edit_audit_names_only_sites_that_exist(): void
    {
        $site = Website::factory()->create(['is_priority' => false]);
        $fake = '00000000-0000-0000-0000-000000000000';

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('selected', [$site->uuid, $fake, '<script>x</script>'])
            ->call('openMassEdit')
            ->set('massApply.isPriority', true)
            ->set('massIsPriority', true)
            ->call('massSave')
            ->assertHasNoErrors();

        $log = AuditLog::where('event', 'situs.diubah-massal')->sole();
        $this->assertSame([$site->uuid], $log->changes['situs']);
        $this->assertSame('1 situs', $log->subject);
        $this->assertTrue($site->fresh()->is_priority);
    }

    public function test_mass_edit_rejects_more_than_the_site_cap(): void
    {
        $site = Website::factory()->create(['is_priority' => false]);
        $selected = array_merge([$site->uuid], array_map(fn ($i) => "fake-{$i}", range(1, 1000)));

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('selected', $selected)
            ->set('massApply.isPriority', true)
            ->set('massIsPriority', true)
            ->call('massSave')
            ->assertHasErrors('massApply');

        $this->assertFalse($site->fresh()->is_priority);
        $this->assertSame(0, AuditLog::where('event', 'situs.diubah-massal')->count());
    }

    public function test_a_user_without_update_permission_cannot_change_priority(): void
    {
        $site = Website::factory()->create(['is_priority' => false]);

        Livewire::actingAs($this->readOnly())
            ->test(Index::class)
            ->set('selected', [$site->uuid])
            ->set('massApply.isPriority', true)
            ->set('massIsPriority', true)
            ->call('massSave')
            ->assertForbidden();

        Livewire::actingAs($this->readOnly())
            ->test(Index::class)
            ->set('editing', $site)
            ->set('name', $site->name)
            ->set('url', $site->url)
            ->set('isPriority', true)
            ->call('save')
            ->assertForbidden();

        $this->assertFalse($site->fresh()->is_priority);
    }

    public function test_the_category_filter_separates_priority_from_secondary_sites(): void
    {
        Website::factory()->create(['name' => 'Situs Penting', 'is_priority' => true]);
        Website::factory()->create(['name' => 'Situs Biasa', 'is_priority' => false]);

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('category', 'priority')
            ->assertSee('Situs Penting')
            ->assertDontSee('Situs Biasa')
            ->set('category', 'secondary')
            ->assertSee('Situs Biasa')
            ->assertDontSee('Situs Penting');
    }

    public function test_admin_list_shows_priority_sites_before_newer_secondary_ones(): void
    {
        Website::factory()->create(['name' => 'Situs Penting Lama', 'is_priority' => true, 'created_at' => now()->subDay()]);
        Website::factory()->create(['name' => 'Situs Biasa Baru', 'is_priority' => false, 'created_at' => now()]);
        Website::factory()->create(['name' => 'Situs Biasa Lama', 'is_priority' => false, 'created_at' => now()->subDays(2)]);

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->assertSeeInOrder(['Situs Penting Lama', 'Situs Biasa Baru', 'Situs Biasa Lama']);
    }

    public function test_priority_sites_keep_newest_first_among_themselves(): void
    {
        Website::factory()->create(['name' => 'Penting Lama', 'is_priority' => true, 'created_at' => now()->subDays(3)]);
        Website::factory()->create(['name' => 'Penting Baru', 'is_priority' => true, 'created_at' => now()->subDay()]);
        Website::factory()->create(['name' => 'Biasa Terbaru', 'is_priority' => false, 'created_at' => now()]);

        $names = Livewire::actingAs($this->superadmin())->test(Index::class)
            ->viewData('websites')->pluck('name')->all();

        $this->assertSame(['Penting Baru', 'Penting Lama', 'Biasa Terbaru'], $names);
    }

    public function test_priority_order_is_consistent_across_admin_pages(): void
    {
        foreach (range(1, 12) as $i) {
            Website::factory()->create(['name' => "Biasa {$i}", 'created_at' => now()->subMinutes($i)]);
        }
        Website::factory()->create(['name' => 'Penting A', 'is_priority' => true, 'created_at' => now()->subDays(5)]);
        Website::factory()->create(['name' => 'Penting B', 'is_priority' => true, 'created_at' => now()->subDays(6)]);

        $component = Livewire::actingAs($this->superadmin())->test(Index::class);
        $page1 = $component->viewData('websites')->pluck('name')->all();
        $page2 = $component->call('gotoPage', 2)->viewData('websites')->pluck('name')->all();

        $this->assertSame(['Penting A', 'Penting B'], array_slice($page1, 0, 2));
        $this->assertCount(10, $page1);
        $this->assertSame(['Biasa 9', 'Biasa 10', 'Biasa 11', 'Biasa 12'], $page2);
        $this->assertSame([], array_intersect($page1, $page2));
    }

    public function test_down_secondary_site_is_listed_before_up_priority_site(): void
    {
        Website::factory()->create(['name' => 'Penting Up', 'is_priority' => true, 'last_status' => 'up', 'created_at' => now()]);
        Website::factory()->create(['name' => 'Biasa Down', 'is_priority' => false, 'last_status' => 'down', 'created_at' => now()->subDays(3)]);

        $names = Livewire::actingAs($this->superadmin())->test(Index::class)
            ->viewData('websites')->pluck('name')->all();

        $this->assertSame(['Biasa Down', 'Penting Up'], $names);
    }

    public function test_among_up_sites_priority_is_listed_before_secondary(): void
    {
        Website::factory()->create(['name' => 'Biasa Up', 'is_priority' => false, 'last_status' => 'up', 'created_at' => now()]);
        Website::factory()->create(['name' => 'Penting Up', 'is_priority' => true, 'last_status' => 'up', 'created_at' => now()->subDays(3)]);

        $names = Livewire::actingAs($this->superadmin())->test(Index::class)
            ->viewData('websites')->pluck('name')->all();

        $this->assertSame(['Penting Up', 'Biasa Up'], $names);
    }

    public function test_full_down_priority_newest_ordering(): void
    {
        Website::factory()->create(['name' => 'Biasa Up Baru', 'last_status' => 'up', 'created_at' => now()]);
        Website::factory()->create(['name' => 'Biasa Up Lama', 'last_status' => 'up', 'created_at' => now()->subDays(4)]);
        Website::factory()->create(['name' => 'Penting Up Lama', 'is_priority' => true, 'last_status' => 'up', 'created_at' => now()->subDays(5)]);
        Website::factory()->create(['name' => 'Penting Up Baru', 'is_priority' => true, 'last_status' => 'up', 'created_at' => now()->subDays(1)]);
        Website::factory()->create(['name' => 'Biasa Down Lama', 'last_status' => 'down', 'created_at' => now()->subDays(6)]);
        Website::factory()->create(['name' => 'Biasa Down Baru', 'last_status' => 'down', 'created_at' => now()->subDays(2)]);
        Website::factory()->create(['name' => 'Penting Down', 'is_priority' => true, 'last_status' => 'down', 'created_at' => now()->subDays(7)]);
        Website::factory()->create(['name' => 'Biasa Unknown', 'last_status' => 'unknown', 'created_at' => now()->addMinute()]);

        $names = Livewire::actingAs($this->superadmin())->test(Index::class)
            ->viewData('websites')->pluck('name')->all();

        // Down group is newest first only: priority does not reorder it.
        $this->assertSame([
            'Biasa Down Baru',
            'Biasa Down Lama',
            'Penting Down',
            'Penting Up Baru',
            'Penting Up Lama',
            'Biasa Unknown',
            'Biasa Up Baru',
            'Biasa Up Lama',
        ], $names);
    }

    public function test_down_group_lists_the_most_recently_down_site_first(): void
    {
        $older = Website::factory()->create(['name' => 'Down Lama', 'last_status' => 'down', 'created_at' => now()]);
        $recent = Website::factory()->create(['name' => 'Down Baru', 'last_status' => 'down', 'created_at' => now()->subDays(5)]);
        Website::factory()->create(['name' => 'Penting Up', 'is_priority' => true, 'last_status' => 'up', 'created_at' => now()->addMinute()]);
        foreach ([[$older, now()->subHours(3)], [$recent, now()->subMinutes(5)]] as [$site, $at]) {
            Incident::create([
                'website_id' => $site->id, 'type' => 'downtime', 'severity' => 'critical',
                'title' => 'x', 'status' => 'open', 'detected_at' => $at,
            ]);
        }

        $names = Livewire::actingAs($this->superadmin())->test(Index::class)
            ->viewData('websites')->pluck('name')->all();

        $this->assertSame(['Down Baru', 'Down Lama', 'Penting Up'], $names);
    }

    public function test_down_site_under_active_maintenance_is_not_in_the_down_group(): void
    {
        Website::factory()->create(['name' => 'Penting Up', 'is_priority' => true, 'last_status' => 'up', 'created_at' => now()->subDays(3)]);
        $maintained = Website::factory()->create(['name' => 'Biasa Down Pemeliharaan', 'last_status' => 'down', 'created_at' => now()->subDays(2)]);
        Website::factory()->create(['name' => 'Biasa Down', 'last_status' => 'down', 'created_at' => now()->subDays(5)]);
        Website::factory()->create(['name' => 'Biasa Up Baru', 'last_status' => 'up', 'created_at' => now()]);
        MaintenanceWindow::create([
            'website_id' => $maintained->id,
            'reason' => 'Migrasi server',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        // A finished window no longer exempts anything.
        MaintenanceWindow::create([
            'website_id' => null,
            'reason' => 'Selesai',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);

        $names = Livewire::actingAs($this->superadmin())->test(Index::class)
            ->viewData('websites')->pluck('name')->all();

        $this->assertSame(['Biasa Down', 'Penting Up', 'Biasa Up Baru', 'Biasa Down Pemeliharaan'], $names);
    }

    public function test_an_all_sites_maintenance_window_exempts_every_down_site(): void
    {
        Website::factory()->create(['name' => 'Biasa Down', 'last_status' => 'down', 'created_at' => now()->subDays(5)]);
        Website::factory()->create(['name' => 'Penting Up', 'is_priority' => true, 'last_status' => 'up', 'created_at' => now()->subDays(6)]);
        MaintenanceWindow::create([
            'website_id' => null,
            'reason' => 'Migrasi server',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $names = Livewire::actingAs($this->superadmin())->test(Index::class)
            ->viewData('websites')->pluck('name')->all();

        $this->assertSame(['Penting Up', 'Biasa Down'], $names);
    }

    public function test_down_sites_without_an_open_incident_fall_back_to_the_last_check_time(): void
    {
        Website::factory()->create(['name' => 'Down Dicek Lama', 'last_status' => 'down', 'last_checked_at' => now()->subHours(3), 'created_at' => now()]);
        Website::factory()->create(['name' => 'Down Dicek Baru', 'last_status' => 'down', 'last_checked_at' => now()->subMinute(), 'created_at' => now()->subDays(5)]);
        Website::factory()->create(['name' => 'Penting Up', 'is_priority' => true, 'last_status' => 'up', 'created_at' => now()->addMinute()]);

        $names = Livewire::actingAs($this->superadmin())->test(Index::class)
            ->viewData('websites')->pluck('name')->all();

        $this->assertSame(['Down Dicek Baru', 'Down Dicek Lama', 'Penting Up'], $names);
    }

    public function test_a_resolved_incident_does_not_count_as_the_down_time(): void
    {
        $open = Website::factory()->create(['name' => 'Down Insiden Terbuka', 'last_status' => 'down', 'last_checked_at' => now()->subHours(5), 'created_at' => now()->subDays(5)]);
        $resolved = Website::factory()->create(['name' => 'Down Insiden Selesai', 'last_status' => 'down', 'last_checked_at' => now()->subHours(4), 'created_at' => now()]);
        Incident::create([
            'website_id' => $open->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now()->subHours(2),
        ]);
        Incident::create([
            'website_id' => $resolved->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'resolved', 'detected_at' => now()->subMinute(), 'resolved_at' => now(),
        ]);

        $names = Livewire::actingAs($this->superadmin())->test(Index::class)
            ->viewData('websites')->pluck('name')->all();

        $this->assertSame(['Down Insiden Terbuka', 'Down Insiden Selesai'], $names);
    }

    public function test_down_sites_lead_every_admin_page_order(): void
    {
        foreach (range(1, 10) as $i) {
            Website::factory()->create(['name' => "Penting {$i}", 'is_priority' => true, 'last_status' => 'up', 'created_at' => now()->subMinutes($i)]);
        }
        Website::factory()->create(['name' => 'Biasa Down', 'last_status' => 'down', 'created_at' => now()->subDays(9)]);

        $component = Livewire::actingAs($this->superadmin())->test(Index::class);
        $page1 = $component->viewData('websites')->pluck('name')->all();
        $page2 = $component->call('gotoPage', 2)->viewData('websites')->pluck('name')->all();

        $this->assertSame('Biasa Down', $page1[0]);
        $this->assertSame(['Penting 10'], $page2);
    }

    public function test_pages_do_not_repeat_or_drop_sites_when_created_at_ties(): void
    {
        $this->freezeSecond();
        Website::factory()->count(23)->create();
        Website::factory()->count(2)->create(['is_priority' => true]);

        $component = Livewire::actingAs($this->superadmin())->test(Index::class);
        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $seen = [...$seen, ...$component->call('gotoPage', $page)->viewData('websites')->pluck('id')->all()];
        }

        $this->assertCount(25, $seen);
        $this->assertCount(25, array_unique($seen));
    }

    public function test_guest_cannot_open_the_admin_site_list(): void
    {
        Website::factory()->create(['name' => 'Situs Penting', 'is_priority' => true]);

        $this->get(route('situs'))->assertRedirect(route('login'));
    }

    public function test_an_unknown_category_value_is_ignored(): void
    {
        Website::factory()->create(['name' => 'Situs Penting', 'is_priority' => true]);
        Website::factory()->create(['name' => 'Situs Biasa', 'is_priority' => false]);

        $component = Livewire::actingAs($this->superadmin())
            ->withQueryParams(['category' => "1' OR 1=1"])
            ->test(Index::class)
            ->assertSee('Situs Penting')
            ->assertSee('Situs Biasa');

        $this->assertFalse($component->instance()->hasFilters());
    }

    private function superadmin(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id')]);
    }

    /** Seeded role with situs.view but not situs.update. */
    private function readOnly(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
    }
}
