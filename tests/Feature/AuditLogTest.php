<?php

namespace Tests\Feature;

use App\Livewire\Audit\Index as AuditIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Websites\Index as WebsitesIndex;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Monitoring\WebsiteChecker;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The trail has to answer "siapa mengubah apa" without being editable, without
 * leaking the secrets it records, and without drowning in the monitor's own writes.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new RoleSeeder)->run();
    }

    // --- What gets recorded ------------------------------------------------

    public function test_creating_a_website_is_recorded_with_the_actor_and_ip(): void
    {
        $user = $this->superadmin();

        Livewire::actingAs($user)
            ->test(WebsitesIndex::class)
            ->call('openCreate')
            ->set('name', 'Portal Contoh')
            ->set('url', 'https://judol-test.example')
            ->call('save')
            ->assertHasNoErrors();

        $log = AuditLog::where('event', 'situs.ditambahkan')->firstOrFail();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->name, $log->actor_name);
        $this->assertSame('Portal Contoh', $log->subject);
        $this->assertSame('https://judol-test.example', $log->changes['url']);
        $this->assertNotNull($log->ip_address);
    }

    public function test_an_update_records_only_what_moved(): void
    {
        $website = Website::factory()->create(['name' => 'Lama', 'check_interval_minutes' => 5]);

        $this->actingAs($this->superadmin());
        $website->update(['name' => 'Baru']);

        $log = AuditLog::where('event', 'situs.diubah')->firstOrFail();

        $this->assertSame(['dari' => 'Lama', 'ke' => 'Baru'], $log->changes['name']);
        $this->assertArrayNotHasKey('check_interval_minutes', $log->changes);
    }

    public function test_deleting_a_role_is_recorded(): void
    {
        $role = Role::factory()->create(['name' => 'Operator Lama']);

        $this->actingAs($this->superadmin());
        $role->delete();

        $this->assertDatabaseHas('audit_logs', ['event' => 'roles.dihapus', 'subject' => 'Operator Lama']);
    }

    public function test_a_failed_login_is_recorded_without_an_actor(): void
    {
        User::factory()->create(['email' => 'operator@contoh.id', 'password' => Hash::make('kata-sandi-benar')]);

        Livewire::test(Login::class)
            ->set('email', 'operator@contoh.id')
            ->set('password', 'salah')
            ->call('login')
            ->assertHasErrors('email');

        $log = AuditLog::where('event', 'login.gagal')->firstOrFail();

        // Attributed to nobody, because the credentials identified nobody - the
        // attempted address and the IP are what make a spray visible.
        $this->assertNull($log->user_id);
        $this->assertSame('operator@contoh.id', $log->subject);
    }

    public function test_a_successful_login_is_recorded(): void
    {
        $user = $this->superadmin();
        $user->update(['password' => Hash::make('kata-sandi-benar')]);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'kata-sandi-benar')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('audit_logs', ['event' => 'login.berhasil', 'user_id' => $user->id]);
    }

    // --- What must NOT get recorded ----------------------------------------

    public function test_a_password_change_records_the_event_but_never_the_hash(): void
    {
        $user = $this->superadmin();

        $this->actingAs($user);
        $user->update(['password' => Hash::make('kata-sandi-baru')]);

        $log = AuditLog::where('event', 'users.diubah')->latest('id')->firstOrFail();

        $this->assertSame(['dari' => '[disensor]', 'ke' => '[disensor]'], $log->changes['password']);
        $this->assertStringNotContainsString('$2y$', json_encode($log->changes));
    }

    public function test_a_secret_setting_records_that_it_changed_but_not_its_value(): void
    {
        $this->actingAs($this->superadmin());
        Setting::create(['key' => 'anthropic_api_key', 'value' => 'sk-ant-rahasia-sekali']);

        $log = AuditLog::where('event', 'pengaturan.ditambahkan')->firstOrFail();

        $this->assertSame('anthropic_api_key', $log->subject);
        $this->assertStringNotContainsString('rahasia', json_encode($log->changes));
    }

    public function test_the_monitor_does_not_write_audit_lines(): void
    {
        Http::fake(['*' => Http::response('<html>ok</html>', 200)]);

        $website = Website::factory()->create(['url' => 'https://judol-test.example']);

        // Signed in on purpose: even then, a probe's own bookkeeping columns must
        // not produce a line - otherwise the trail is nothing but check noise.
        $this->actingAs($this->superadmin());
        app(WebsiteChecker::class)->check($website);

        $this->assertDatabaseMissing('audit_logs', ['auditable_type' => Website::class, 'event' => 'situs.diubah']);
    }

    public function test_nothing_is_recorded_when_nobody_is_signed_in(): void
    {
        Website::factory()->create(['name' => 'Dari Seeder']);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    // --- The page ----------------------------------------------------------

    public function test_the_audit_page_is_closed_without_the_permission(): void
    {
        $user = User::factory()->create(['role_id' => Role::factory()->withPermissions(['dashboard.view'])->create()->id]);

        $this->actingAs($user)->get(route('audit'))->assertForbidden();
    }

    public function test_the_audit_page_lists_and_filters_entries(): void
    {
        $user = $this->superadmin();

        $this->actingAs($user);
        AuditLog::record('situs.ditambahkan', 'Portal Contoh');
        AuditLog::record('login.gagal', 'penyerang@contoh.id');

        Livewire::actingAs($user)
            ->test(AuditIndex::class)
            ->assertSee('Portal Contoh')
            ->assertSee('penyerang@contoh.id')
            ->set('group', 'login')
            ->assertSee('penyerang@contoh.id')
            ->assertDontSee('Portal Contoh');
    }

    public function test_the_trail_survives_the_actor_being_deleted(): void
    {
        $actor = User::factory()->create(['name' => 'Mantan Admin', 'role_id' => Role::where('slug', 'superadmin')->value('id')]);

        $this->actingAs($actor);
        AuditLog::record('pengaturan.diubah', 'mail_host');

        $actor->delete();

        $log = AuditLog::where('event', 'pengaturan.diubah')->firstOrFail();

        $this->assertNull($log->user_id);
        $this->assertSame('Mantan Admin', $log->actorLabel());
    }

    // --- Retention ---------------------------------------------------------

    public function test_prune_drops_only_entries_past_the_retention_window(): void
    {
        $this->actingAs($this->superadmin());

        $old = AuditLog::record('login.berhasil', 'lama@contoh.id');
        $old->forceFill(['created_at' => now()->subDays(400)])->save();

        AuditLog::record('login.berhasil', 'baru@contoh.id');

        $this->artisan('audit:prune', ['--days' => 365])->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['subject' => 'lama@contoh.id']);
        $this->assertDatabaseHas('audit_logs', ['subject' => 'baru@contoh.id']);
    }

    protected function superadmin(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id')]);
    }
}
