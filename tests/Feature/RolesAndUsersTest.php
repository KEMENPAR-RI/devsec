<?php

namespace Tests\Feature;

use App\Livewire\Profile\Index as ProfileIndex;
use App\Livewire\Roles\Index as RolesIndex;
use App\Livewire\Users\Index as UsersIndex;
use App\Livewire\Websites\Index as WebsitesIndex;
use App\Models\Role;
use App\Models\User;
use App\Models\Website;
use App\Support\Permissions;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class RolesAndUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new RoleSeeder)->run();
    }

    // --- Seeded roles ------------------------------------------------------

    public function test_the_seeder_creates_a_superadmin_that_holds_every_permission(): void
    {
        $superadmin = Role::where('slug', 'superadmin')->firstOrFail();

        $this->assertTrue($superadmin->is_superadmin);
        $this->assertSame(Permissions::all(), $superadmin->grantedPermissions());
        $this->assertNotEmpty($superadmin->uuid);

        // Even a permission invented after the role was stored answers yes.
        $this->assertTrue($superadmin->hasPermission('halaman-yang-belum-ada.create'));
    }

    public function test_the_seeded_admin_can_only_look(): void
    {
        $admin = Role::where('slug', 'admin')->firstOrFail();

        $this->assertTrue($admin->hasPermission('situs.view'));
        $this->assertFalse($admin->hasPermission('situs.create'));
        $this->assertFalse($admin->hasPermission('situs.update'));
        $this->assertFalse($admin->hasPermission('situs.delete'));

        foreach ($admin->grantedPermissions() as $permission) {
            $this->assertStringEndsWith('.view', $permission);
        }
    }

    public function test_the_incident_and_judol_pages_have_no_create_ability_at_all(): void
    {
        // Nothing on either page is created by hand - the monitor raises incidents,
        // the scanner raises findings - so offering a "Tambah" checkbox would be a
        // permission nobody could ever exercise.
        foreach (['insiden', 'deteksi-judol'] as $page) {
            $this->assertNotContains('create', Permissions::pages()[$page]['abilities']);
        }

        $this->assertNotContains('insiden.create', Permissions::all());
        $this->assertNotContains('deteksi-judol.create', Permissions::all());
    }

    public function test_running_the_seeder_twice_updates_in_place_instead_of_duplicating(): void
    {
        (new RoleSeeder)->run();

        $this->assertSame(1, Role::where('slug', 'superadmin')->count());
        $this->assertSame(2, Role::count());
    }

    // --- Menu visibility ---------------------------------------------------

    public function test_the_sidebar_only_lists_pages_the_role_can_open(): void
    {
        $role = Role::factory()->withPermissions(['dashboard.view', 'situs.view'])->create();

        $this->actingAs(User::factory()->create(['role_id' => $role->id]))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee(route('situs'))
            ->assertDontSee(route('insiden'))
            ->assertDontSee(route('roles'))
            ->assertDontSee(route('users'))
            ->assertDontSee(route('pengaturan'));
    }

    public function test_a_superadmin_sees_the_access_management_menu(): void
    {
        $this->actingAs($this->userWith('superadmin'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Manajemen Akses')
            ->assertSee(route('roles'))
            ->assertSee(route('users'));
    }

    // --- Route protection --------------------------------------------------

    public function test_a_page_without_permission_is_forbidden_even_when_the_url_is_typed_directly(): void
    {
        $user = $this->userWith('admin');
        $user->role->update(['permissions' => ['dashboard.view']]);

        $this->actingAs($user)->get(route('roles'))->assertForbidden();
        $this->actingAs($user)->get(route('users'))->assertForbidden();
        $this->actingAs($user)->get(route('situs'))->assertForbidden();
    }

    public function test_a_user_with_no_role_at_all_can_still_reach_their_own_profile(): void
    {
        $user = User::factory()->create(['role_id' => null]);

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($user)->get(route('profil'))->assertOk();
        // The landing route must not bounce them into that 403.
        $this->actingAs($user)->get('/')->assertRedirect(route('profil'));
    }

    // --- Livewire actions are gated server-side ---------------------------

    public function test_an_admin_cannot_create_a_website_even_by_calling_the_action_directly(): void
    {
        $admin = $this->userWith('admin');

        // Livewire turns a failed authorize() into a 403 response rather than
        // letting the exception escape, so this is what a tampered request sees.
        Livewire::actingAs($admin)
            ->test(WebsitesIndex::class)
            ->call('openCreate')
            ->assertForbidden();
    }

    public function test_an_admin_cannot_delete_a_website_either(): void
    {
        $website = Website::factory()->create();

        Livewire::actingAs($this->userWith('admin'))
            ->test(WebsitesIndex::class)
            ->call('delete', $website->uuid)
            ->assertForbidden();

        $this->assertDatabaseHas('websites', ['id' => $website->id]);
    }

    // --- Roles CRUD --------------------------------------------------------

    public function test_a_role_is_created_with_a_uuid_and_the_checked_permissions(): void
    {
        Livewire::actingAs($this->userWith('superadmin'))
            ->test(RolesIndex::class)
            ->call('openCreate')
            ->set('name', 'Operator Situs')
            ->set('slug', 'operator-situs')
            ->set('description', 'Hanya mengurus daftar situs.')
            ->set('permissions', ['situs.view', 'situs.update'])
            ->call('save')
            ->assertHasNoErrors();

        $role = Role::where('slug', 'operator-situs')->firstOrFail();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $role->uuid);
        $this->assertSame(['situs.view', 'situs.update'], $role->permissions);
        $this->assertFalse($role->is_superadmin);
    }

    public function test_a_permission_name_that_does_not_exist_is_rejected(): void
    {
        // The checkboxes can only ever submit real names, so anything else means the
        // payload was tampered with - better to refuse than to store a dead string.
        Livewire::actingAs($this->userWith('superadmin'))
            ->test(RolesIndex::class)
            ->call('openCreate')
            ->set('name', 'Role Palsu')
            ->set('permissions', ['situs.view', 'halaman-karangan.delete'])
            ->call('save')
            ->assertHasErrors('permissions.1');

        $this->assertDatabaseMissing('roles', ['slug' => 'role-palsu']);
    }

    public function test_toggling_a_page_ticks_and_clears_all_of_its_abilities_at_once(): void
    {
        $component = Livewire::actingAs($this->userWith('superadmin'))
            ->test(RolesIndex::class)
            ->call('openCreate')
            ->call('togglePage', 'situs');

        $this->assertSame(
            ['situs.view', 'situs.create', 'situs.update', 'situs.delete'],
            $component->get('permissions')
        );

        $this->assertSame([], $component->call('togglePage', 'situs')->get('permissions'));
    }

    public function test_a_seeded_role_keeps_its_name_and_slug_when_edited(): void
    {
        $admin = Role::where('slug', 'admin')->firstOrFail();

        Livewire::actingAs($this->userWith('superadmin'))
            ->test(RolesIndex::class)
            ->call('openEdit', $admin->uuid)
            ->set('name', 'Nama Baru')
            ->set('slug', 'slug-baru')
            ->set('permissions', ['situs.view'])
            ->call('save')
            ->assertHasNoErrors();

        $admin->refresh();

        $this->assertSame('Admin', $admin->name);
        $this->assertSame('admin', $admin->slug);
        $this->assertSame(['situs.view'], $admin->permissions, 'permissions are still editable');
    }

    public function test_a_seeded_role_cannot_be_deleted(): void
    {
        $admin = Role::where('slug', 'admin')->firstOrFail();

        Livewire::actingAs($this->userWith('superadmin'))
            ->test(RolesIndex::class)
            ->call('delete', $admin->uuid);

        $this->assertDatabaseHas('roles', ['id' => $admin->id]);
    }

    public function test_deleting_a_role_leaves_its_users_in_place_without_permissions(): void
    {
        $role = Role::factory()->withPermissions(['situs.view'])->create();
        $user = User::factory()->create(['role_id' => $role->id]);

        Livewire::actingAs($this->userWith('superadmin'))
            ->test(RolesIndex::class)
            ->call('delete', $role->uuid);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'role_id' => null]);
        $this->assertFalse($user->fresh()->hasPermission('situs.view'));
    }

    // --- Users CRUD --------------------------------------------------------

    public function test_a_user_is_created_with_a_hashed_password_and_the_chosen_role(): void
    {
        $role = Role::where('slug', 'admin')->firstOrFail();

        Livewire::actingAs($this->userWith('superadmin'))
            ->test(UsersIndex::class)
            ->call('openCreate')
            ->set('name', 'Budi Operator')
            ->set('email', 'budi@instansi.go.id')
            ->set('roleUuid', $role->uuid)
            ->set('password', 'rahasia-sekali')
            ->set('passwordConfirmation', 'rahasia-sekali')
            ->call('save')
            ->assertHasNoErrors();

        $user = User::where('email', 'budi@instansi.go.id')->firstOrFail();

        $this->assertSame($role->id, $user->role_id);
        $this->assertNotSame('rahasia-sekali', $user->password);
        $this->assertTrue(Hash::check('rahasia-sekali', $user->password));
    }

    public function test_a_mismatched_confirmation_is_rejected(): void
    {
        Livewire::actingAs($this->userWith('superadmin'))
            ->test(UsersIndex::class)
            ->call('openCreate')
            ->set('name', 'Budi')
            ->set('email', 'budi2@instansi.go.id')
            ->set('password', 'rahasia-sekali')
            ->set('passwordConfirmation', 'salah-ketik')
            ->call('save')
            ->assertHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'budi2@instansi.go.id']);
    }

    public function test_editing_a_user_without_touching_the_password_leaves_it_alone(): void
    {
        $target = User::factory()->create(['password' => 'kata-sandi-lama']);
        $before = $target->password;

        Livewire::actingAs($this->userWith('superadmin'))
            ->test(UsersIndex::class)
            ->call('openEdit', $target->uuid)
            ->set('name', 'Nama Diperbarui')
            ->call('save')
            ->assertHasNoErrors();

        $target->refresh();

        $this->assertSame('Nama Diperbarui', $target->name);
        $this->assertSame($before, $target->password);
    }

    public function test_you_cannot_delete_your_own_account(): void
    {
        $me = $this->userWith('superadmin');

        Livewire::actingAs($me)->test(UsersIndex::class)->call('delete', $me->uuid);

        $this->assertDatabaseHas('users', ['id' => $me->id]);
    }

    public function test_the_last_superadmin_cannot_be_deleted(): void
    {
        $lastSuperadmin = $this->userWith('superadmin');
        $deleter = $this->userWith('superadmin');
        // Now there are two; demote one so only $lastSuperadmin holds the role.
        $deleter->update(['role_id' => Role::where('slug', 'admin')->value('id')]);
        $deleter->role->update(['permissions' => Permissions::all()]);

        Livewire::actingAs($deleter->fresh())->test(UsersIndex::class)->call('delete', $lastSuperadmin->uuid);

        $this->assertDatabaseHas('users', ['id' => $lastSuperadmin->id]);
    }

    // --- Profile -----------------------------------------------------------

    public function test_anyone_signed_in_can_update_their_own_profile(): void
    {
        $user = User::factory()->create(['role_id' => null, 'name' => 'Nama Lama']);

        Livewire::actingAs($user)
            ->test(ProfileIndex::class)
            ->set('name', 'Nama Baru')
            ->set('email', 'baru@instansi.go.id')
            ->call('updateProfile')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('Nama Baru', $user->name);
        $this->assertSame('baru@instansi.go.id', $user->email);
    }

    public function test_changing_the_password_requires_the_current_one(): void
    {
        $user = User::factory()->create(['password' => 'kata-sandi-lama']);

        Livewire::actingAs($user)
            ->test(ProfileIndex::class)
            ->set('currentPassword', 'tebakan-salah')
            ->set('password', 'kata-sandi-baru')
            ->set('passwordConfirmation', 'kata-sandi-baru')
            ->call('updatePassword')
            ->assertHasErrors('currentPassword');

        $this->assertTrue(Hash::check('kata-sandi-lama', $user->fresh()->password));
    }

    public function test_the_password_changes_when_the_current_one_is_right(): void
    {
        $user = User::factory()->create(['password' => 'kata-sandi-lama']);

        Livewire::actingAs($user)
            ->test(ProfileIndex::class)
            ->set('currentPassword', 'kata-sandi-lama')
            ->set('password', 'kata-sandi-baru')
            ->set('passwordConfirmation', 'kata-sandi-baru')
            ->call('updatePassword')
            ->assertHasNoErrors()
            // Cleared so the new password isn't left sitting in the component state.
            ->assertSet('password', '');

        $this->assertTrue(Hash::check('kata-sandi-baru', $user->fresh()->password));
    }

    protected function userWith(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id')]);
    }
}
