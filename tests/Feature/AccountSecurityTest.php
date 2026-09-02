<?php

namespace Tests\Feature;

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Notifications\ResetPasswordNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new RoleSeeder)->run();
        RateLimiter::clear('reset|');

        // The reset page refuses to pretend it sent anything when no mailer is
        // configured, so these tests need a working one to exercise the flow.
        Setting::set('mail_enabled', '1');
        Setting::set('mail_host', 'smtp.example.com');
        Setting::set('mail_from_address', 'noreply@example.com');
    }

    // --- Banning -----------------------------------------------------------

    public function test_a_banned_account_cannot_sign_in_and_is_told_why(): void
    {
        User::factory()->create([
            'email' => 'diblokir@example.com',
            'password' => 'kata-sandi-benar',
            'banned_at' => now(),
            'banned_reason' => 'Akun tidak lagi digunakan',
        ]);

        Livewire::test(Login::class)
            ->set('email', 'diblokir@example.com')
            ->set('password', 'kata-sandi-benar')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_banning_someone_mid_session_ends_that_session_on_their_next_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->update(['banned_at' => now(), 'banned_reason' => 'Disalahgunakan']);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_banned_user_holds_no_permissions_even_with_a_superadmin_role(): void
    {
        $user = User::factory()->create(['banned_at' => now()]);

        $this->assertTrue($user->isSuperadmin(), 'the role itself is untouched');
        $this->assertFalse($user->hasPermission('situs.view'));
    }

    public function test_banning_from_the_users_page_records_the_reason_without_deleting_anything(): void
    {
        $target = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);

        Livewire::actingAs($this->superadmin())
            ->test(UsersIndex::class)
            ->call('openBan', $target->uuid)
            ->set('banReason', 'Pindah instansi')
            ->call('ban')
            ->assertHasNoErrors();

        $target->refresh();

        $this->assertTrue($target->isBanned());
        $this->assertSame('Pindah instansi', $target->banned_reason);
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_unbanning_restores_access(): void
    {
        $target = User::factory()->create(['banned_at' => now(), 'banned_reason' => 'x']);

        Livewire::actingAs($this->superadmin())
            ->test(UsersIndex::class)
            ->call('unban', $target->uuid);

        $target->refresh();

        $this->assertFalse($target->isBanned());
        $this->assertNull($target->banned_reason);
    }

    public function test_you_cannot_ban_yourself(): void
    {
        $me = $this->superadmin();

        Livewire::actingAs($me)->test(UsersIndex::class)->call('openBan', $me->uuid)->call('ban');

        $this->assertFalse($me->fresh()->isBanned());
    }

    public function test_the_last_active_superadmin_cannot_be_banned(): void
    {
        $only = $this->superadmin();
        $other = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
        $other->role->update(['permissions' => ['users.view', 'users.update']]);

        Livewire::actingAs($other)->test(UsersIndex::class)->call('openBan', $only->uuid)->call('ban');

        $this->assertFalse($only->fresh()->isBanned());
    }

    public function test_the_list_can_be_filtered_by_ban_status(): void
    {
        $active = $this->superadmin();
        $banned = User::factory()->create(['banned_at' => now()]);

        $emails = fn (string $filter) => Livewire::actingAs($active)
            ->test(UsersIndex::class)
            ->set('statusFilter', $filter)
            ->viewData('users')
            ->pluck('email')
            ->all();

        $this->assertSame([$banned->email], $emails('banned'));
        $this->assertSame([$active->email], $emails('active'));
        $this->assertCount(2, $emails(''));
    }

    // --- Password reset ----------------------------------------------------

    public function test_a_reset_link_is_emailed_to_a_real_account(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ada@example.com']);

        Livewire::test(ForgotPassword::class)
            ->set('email', 'ada@example.com')
            ->call('sendResetLink')
            ->assertHasNoErrors()
            ->assertSet('sent', true);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_an_unknown_address_gets_the_same_answer_and_no_email(): void
    {
        // Identical response, so this page can't be used to enumerate accounts.
        Notification::fake();

        Livewire::test(ForgotPassword::class)
            ->set('email', 'tidak-ada@example.com')
            ->call('sendResetLink')
            ->assertHasNoErrors()
            ->assertSet('sent', true);

        Notification::assertNothingSent();
    }

    public function test_a_banned_account_gets_no_reset_link(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'blokir@example.com', 'banned_at' => now()]);

        Livewire::test(ForgotPassword::class)
            ->set('email', 'blokir@example.com')
            ->call('sendResetLink')
            ->assertSet('sent', true);

        Notification::assertNothingSent();
    }

    public function test_a_valid_token_sets_the_new_password(): void
    {
        $user = User::factory()->create(['email' => 'ganti@example.com', 'password' => 'kata-sandi-lama']);
        $token = Password::createToken($user);

        Livewire::test(ResetPassword::class, ['token' => $token])
            ->set('email', 'ganti@example.com')
            ->set('password', 'kata-sandi-baru')
            ->set('passwordConfirmation', 'kata-sandi-baru')
            ->call('resetPassword')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('kata-sandi-baru', $user->fresh()->password));
        $this->assertTrue(Auth::validate(['email' => 'ganti@example.com', 'password' => 'kata-sandi-baru']));
    }

    public function test_a_bogus_token_is_refused_and_the_password_stands(): void
    {
        $user = User::factory()->create(['email' => 'aman@example.com', 'password' => 'kata-sandi-lama']);

        Livewire::test(ResetPassword::class, ['token' => 'token-karangan'])
            ->set('email', 'aman@example.com')
            ->set('password', 'kata-sandi-baru')
            ->set('passwordConfirmation', 'kata-sandi-baru')
            ->call('resetPassword')
            ->assertHasErrors('email');

        $this->assertTrue(Hash::check('kata-sandi-lama', $user->fresh()->password));
    }

    public function test_a_mismatched_confirmation_is_refused(): void
    {
        $user = User::factory()->create(['password' => 'kata-sandi-lama']);
        $token = Password::createToken($user);

        Livewire::test(ResetPassword::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'kata-sandi-baru')
            ->set('passwordConfirmation', 'salah-ketik')
            ->call('resetPassword')
            ->assertHasErrors('password');

        $this->assertTrue(Hash::check('kata-sandi-lama', $user->fresh()->password));
    }

    // --- UUIDs -------------------------------------------------------------

    public function test_every_record_gets_a_uuid_on_creation(): void
    {
        $website = Website::factory()->create();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $website->uuid);
        $this->assertSame('uuid', $website->getRouteKeyName());
        $this->assertSame($website->uuid, $website->getRouteKey());
    }

    public function test_a_website_is_addressed_by_uuid_not_by_its_primary_key(): void
    {
        $website = Website::factory()->create();
        $user = $this->superadmin();

        $this->assertStringContainsString($website->uuid, route('situs.show', $website));
        $this->actingAs($user)->get(route('situs.show', $website))->assertOk();
        // The old numeric URL no longer resolves to anything.
        $this->actingAs($user)->get('/situs/'.$website->id)->assertNotFound();
    }

    public function test_the_situs_list_never_prints_a_raw_primary_key(): void
    {
        $website = Website::factory()->create(['name' => 'Situs Uji UUID']);

        $body = $this->actingAs($this->superadmin())->get(route('situs'))->assertOk()->getContent();

        $this->assertStringContainsString($website->uuid, $body);
        $this->assertStringNotContainsString("delete({$website->id})", $body);
        $this->assertStringNotContainsString("openEdit({$website->id})", $body);
    }

    protected function superadmin(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id')]);
    }
}
