<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Auth\TwoFactorChallenge;
use App\Livewire\Profile\Index as ProfileIndex;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Security\TwoFactor;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The second factor has to actually gate the session: a correct password alone
 * must not sign anyone in, and an enrolment must not take effect until a working
 * code has proved the authenticator really holds the secret.
 */
class TwoFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new RoleSeeder)->run();
    }

    // --- Enrolment ---------------------------------------------------------

    public function test_enrolment_does_nothing_until_a_working_code_is_entered(): void
    {
        $user = $this->user();

        $component = Livewire::actingAs($user)
            ->test(ProfileIndex::class)
            ->call('startTwoFactor');

        $this->assertNotNull($component->get('pendingSecret'));

        // Scanned but not proved: the account must still be exactly as it was, or
        // a mistyped scan becomes a lockout.
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());

        $component->set('twoFactorCode', '000000')
            ->call('confirmTwoFactor')
            ->assertHasErrors('twoFactorCode');

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_a_valid_code_enables_two_factor_and_shows_recovery_codes_once(): void
    {
        $user = $this->user();

        $component = Livewire::actingAs($user)
            ->test(ProfileIndex::class)
            ->call('startTwoFactor');

        $secret = $component->get('pendingSecret');

        $component->set('twoFactorCode', $this->codeFor($secret))
            ->call('confirmTwoFactor')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertTrue($user->hasTwoFactorEnabled());
        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertCount(TwoFactor::RECOVERY_CODE_COUNT, $user->recoveryCodes());
        $this->assertCount(TwoFactor::RECOVERY_CODE_COUNT, $component->get('freshRecoveryCodes'));

        $this->assertDatabaseHas('audit_logs', ['event' => 'users.2fa-diaktifkan']);
    }

    public function test_disabling_two_factor_requires_the_password(): void
    {
        $user = $this->enrolled();

        Livewire::actingAs($user)
            ->test(ProfileIndex::class)
            ->set('disablePassword', 'salah')
            ->call('disableTwoFactor')
            ->assertHasErrors('disablePassword');

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        Livewire::actingAs($user)
            ->test(ProfileIndex::class)
            ->set('disablePassword', 'kata-sandi-benar')
            ->call('disableTwoFactor')
            ->assertHasNoErrors();

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_the_secret_is_encrypted_at_rest(): void
    {
        $user = $this->enrolled();

        $stored = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->assertNotSame($user->two_factor_secret, $stored);
        $this->assertStringNotContainsString($user->two_factor_secret, (string) $stored);
    }

    // --- The login gate ----------------------------------------------------

    public function test_the_right_password_alone_does_not_sign_an_enrolled_user_in(): void
    {
        $user = $this->enrolled();

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'kata-sandi-benar')
            ->call('login')
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
        $this->assertSame($user->id, session('auth.2fa.user_id'));
    }

    public function test_a_valid_code_completes_the_login(): void
    {
        $user = $this->enrolled();

        $this->startChallengeFor($user);

        Livewire::test(TwoFactorChallenge::class)
            ->set('code', $this->codeFor($user->two_factor_secret))
            ->call('verify')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('auth.2fa.user_id'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'login.berhasil']);
    }

    public function test_a_wrong_code_keeps_the_session_out(): void
    {
        $user = $this->enrolled();

        $this->startChallengeFor($user);

        Livewire::test(TwoFactorChallenge::class)
            ->set('code', '000000')
            ->call('verify')
            ->assertHasErrors('code');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'login.2fa-gagal']);
    }

    public function test_the_code_is_throttled_separately_from_the_password(): void
    {
        $user = $this->enrolled();

        $this->startChallengeFor($user);

        $component = Livewire::test(TwoFactorChallenge::class);

        // Six digits is a small space; without its own limiter, somebody holding
        // the password would get unlimited guesses at the factor meant to stop them.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $component->set('code', '000000')->call('verify');
        }

        $component->set('code', '000000')->call('verify')->assertHasErrors('code');

        $this->assertStringContainsString(
            'Terlalu banyak percobaan',
            $component->errors()->first('code')
        );

        RateLimiter::clear('2fa|'.$user->id.'|127.0.0.1');
    }

    public function test_the_challenge_page_is_closed_without_a_ticket(): void
    {
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_an_expired_ticket_sends_the_user_back_to_the_login_page(): void
    {
        $user = $this->enrolled();

        session([
            'auth.2fa.user_id' => $user->id,
            'auth.2fa.started_at' => now()->subMinutes(10)->timestamp,
        ]);

        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // --- Recovery ----------------------------------------------------------

    public function test_a_recovery_code_works_once_and_then_is_gone(): void
    {
        $user = $this->enrolled();
        $code = $user->recoveryCodes()[0];

        $this->startChallengeFor($user);

        Livewire::test(TwoFactorChallenge::class)
            ->set('useRecovery', true)
            ->set('recoveryCode', $code)
            ->call('verify')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertNotContains($code, $user->fresh()->recoveryCodes());
        $this->assertCount(TwoFactor::RECOVERY_CODE_COUNT - 1, $user->fresh()->recoveryCodes());

        // Replaying it must not work a second time.
        Auth::logout();
        $this->startChallengeFor($user->fresh());

        Livewire::test(TwoFactorChallenge::class)
            ->set('useRecovery', true)
            ->set('recoveryCode', $code)
            ->call('verify')
            ->assertHasErrors('recoveryCode');
    }

    public function test_regenerating_recovery_codes_invalidates_the_old_set(): void
    {
        $user = $this->enrolled();
        $old = $user->recoveryCodes();

        Livewire::actingAs($user)
            ->test(ProfileIndex::class)
            ->call('regenerateRecoveryCodes');

        $new = $user->fresh()->recoveryCodes();

        $this->assertCount(TwoFactor::RECOVERY_CODE_COUNT, $new);
        $this->assertEmpty(array_intersect($old, $new));
    }

    public function test_the_console_recovery_path_clears_an_enrolment(): void
    {
        $user = $this->enrolled();

        $this->artisan('two-factor:disable', ['email' => $user->email])
            ->expectsConfirmation("Nonaktifkan verifikasi dua faktor untuk {$user->email}?", 'yes')
            ->assertSuccessful();

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertDatabaseHas('audit_logs', ['event' => 'users.2fa-dinonaktifkan']);
    }

    // --- Mandatory enforcement ---------------------------------------------

    public function test_when_required_a_user_without_a_second_factor_is_held_on_their_profile(): void
    {
        Setting::set('two_factor_required', '1');

        $user = $this->user();

        // Held, not locked out: the profile page is where the requirement is
        // satisfied, so it and logout have to stay reachable.
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('profil'));
        $this->actingAs($user)->get(route('profil'))->assertOk();
    }

    public function test_when_required_an_enrolled_user_is_unaffected(): void
    {
        Setting::set('two_factor_required', '1');

        $this->actingAs($this->enrolled())->get(route('dashboard'))->assertOk();
    }

    public function test_the_requirement_is_off_by_default(): void
    {
        $this->actingAs($this->user())->get(route('dashboard'))->assertOk();
    }

    // --- Helpers -----------------------------------------------------------

    protected function user(): User
    {
        return User::factory()->create([
            'password' => Hash::make('kata-sandi-benar'),
            'role_id' => Role::where('slug', 'superadmin')->value('id'),
        ]);
    }

    /** A user with a confirmed enrolment and a full set of recovery codes. */
    protected function enrolled(): User
    {
        $twoFactor = app(TwoFactor::class);
        $user = $this->user();

        $user->forceFill([
            'two_factor_secret' => $twoFactor->generateSecret(),
            'two_factor_recovery_codes' => $twoFactor->generateRecoveryCodes(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }

    /** Puts a fresh, valid challenge ticket in the session. */
    protected function startChallengeFor(User $user): void
    {
        session([
            'auth.2fa.user_id' => $user->id,
            'auth.2fa.started_at' => now()->timestamp,
        ]);
    }

    /** The code an authenticator app would be showing for this secret right now. */
    protected function codeFor(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }
}
