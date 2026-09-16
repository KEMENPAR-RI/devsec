<?php

namespace Tests\Feature;

use App\Livewire\Profile\Index as ProfileIndex;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AccountSecurityAlert;
use App\Services\Security\AccountAlerts;
use App\Services\Security\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The audit log answers "what happened" once somebody thinks to look. These
 * alerts are what make them look.
 */
class AccountAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sign_in_from_a_familiar_address_says_nothing(): void
    {
        Notification::fake();

        $user = $this->user();
        $this->recordLoginFrom($user, '127.0.0.1');

        app(AccountAlerts::class)->notifyIfNewDevice($user);

        Notification::assertNothingSent();
    }

    public function test_a_sign_in_from_a_new_address_warns_the_owner(): void
    {
        Notification::fake();

        $user = $this->user();
        $this->recordLoginFrom($user, '203.0.113.10');

        // The test request comes from 127.0.0.1, which this account has never
        // signed in from.
        app(AccountAlerts::class)->notifyIfNewDevice($user);

        Notification::assertSentTo($user, AccountSecurityAlert::class,
            fn (AccountSecurityAlert $alert) => $alert->event === 'login_perangkat_baru');
    }

    public function test_the_very_first_sign_in_is_not_treated_as_a_new_device(): void
    {
        Notification::fake();

        // Nothing to compare against yet, and an alert here would reach the owner
        // before they have any way to act on it.
        app(AccountAlerts::class)->notifyIfNewDevice($this->user());

        Notification::assertNothingSent();
    }

    public function test_one_users_history_does_not_make_another_users_address_familiar(): void
    {
        Notification::fake();

        $user = $this->user();
        $other = $this->user();

        $this->recordLoginFrom($user, '203.0.113.10');
        $this->recordLoginFrom($other, '127.0.0.1');

        app(AccountAlerts::class)->notifyIfNewDevice($user);

        Notification::assertSentTo($user, AccountSecurityAlert::class);
    }

    public function test_changing_the_password_warns_the_owner(): void
    {
        Notification::fake();

        $user = $this->user();

        Livewire::actingAs($user)
            ->test(ProfileIndex::class)
            ->set('currentPassword', 'KataSandiLama9x')
            ->set('password', 'KataSandiBaru9x')
            ->set('passwordConfirmation', 'KataSandiBaru9x')
            ->call('updatePassword')
            ->assertHasNoErrors();

        Notification::assertSentTo($user, AccountSecurityAlert::class,
            fn (AccountSecurityAlert $alert) => $alert->event === 'kata_sandi_diubah');
    }

    public function test_turning_the_second_factor_off_warns_the_owner(): void
    {
        Notification::fake();

        $twoFactor = app(TwoFactor::class);
        $user = $this->user();
        $user->forceFill([
            'two_factor_secret' => $twoFactor->generateSecret(),
            'two_factor_recovery_codes' => $twoFactor->generateRecoveryCodes(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        Livewire::actingAs($user)
            ->test(ProfileIndex::class)
            ->set('disablePassword', 'KataSandiLama9x')
            ->call('disableTwoFactor')
            ->assertHasNoErrors();

        Notification::assertSentTo($user, AccountSecurityAlert::class,
            fn (AccountSecurityAlert $alert) => $alert->event === '2fa_dinonaktifkan');
    }

    public function test_an_undeliverable_alert_does_not_fail_the_action_it_reports(): void
    {
        $user = $this->user();

        // A password change that already succeeded must not report failure because
        // the courtesy email could not go out.
        Notification::fake();
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('SMTP mati'));

        Livewire::actingAs($user)
            ->test(ProfileIndex::class)
            ->set('currentPassword', 'KataSandiLama9x')
            ->set('password', 'KataSandiBaru9x')
            ->set('passwordConfirmation', 'KataSandiBaru9x')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('KataSandiBaru9x', $user->fresh()->password));
    }

    protected function user(): User
    {
        return User::factory()->create(['password' => Hash::make('KataSandiLama9x')]);
    }

    protected function recordLoginFrom(User $user, string $ip): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'actor_name' => $user->name,
            'actor_email' => $user->email,
            'event' => 'login.berhasil',
            'subject' => $user->email,
            'ip_address' => $ip,
            'created_at' => now()->subDay(),
        ]);
    }
}
