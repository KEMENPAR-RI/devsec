<?php

namespace Tests\Feature;

use App\Livewire\Profile\Index as ProfileIndex;
use App\Models\User;
use App\Support\DeviceName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A ban ends a session and a password change should too. Without that, an
 * account whose password leaked stays compromised for as long as the stolen
 * session lives, no matter what the owner does about it.
 */
class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs on the array driver for speed; these tests are about the
        // database driver the app actually ships with.
        config(['session.driver' => 'database']);
    }

    public function test_the_profile_lists_this_users_sessions_and_marks_the_current_one(): void
    {
        $user = $this->user();
        $other = $this->user();

        $this->actingAs($user);
        $this->storeSession('sesi-lain', $user, 'Mozilla/5.0 (Windows NT 10.0) Chrome/126.0 Safari/537.36');
        $this->storeSession('sesi-orang-lain', $other);

        $sessions = Livewire::actingAs($user)->test(ProfileIndex::class)->viewData('activeSessions');

        // Strictly this user's own - one operator must never see another's.
        $this->assertCount(1, $sessions);
        $this->assertSame('Chrome di Windows', $sessions->first()->device);
    }

    public function test_logging_out_other_devices_requires_the_password(): void
    {
        $user = $this->user();

        $this->actingAs($user);
        $this->storeSession('sesi-lain', $user);

        Livewire::actingAs($user)
            ->test(ProfileIndex::class)
            ->set('sessionPassword', 'salah')
            ->call('logoutOtherDevices')
            ->assertHasErrors('sessionPassword');

        $this->assertDatabaseHas('sessions', ['id' => 'sesi-lain']);
    }

    public function test_logging_out_other_devices_ends_them_and_keeps_this_one(): void
    {
        $user = $this->user();

        $this->actingAs($user);
        $current = session()->getId();
        $this->storeSession($current, $user);
        $this->storeSession('sesi-lain', $user);

        Livewire::actingAs($user)
            ->test(ProfileIndex::class)
            ->set('sessionPassword', 'kata-sandi-benar')
            ->call('logoutOtherDevices')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'sesi-lain']);
        $this->assertDatabaseHas('sessions', ['id' => $current]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'users.sesi-lain-dikeluarkan']);
    }

    public function test_changing_the_password_ends_every_other_session(): void
    {
        $user = $this->user();

        $this->actingAs($user);
        $this->storeSession('sesi-lama', $user);

        Livewire::actingAs($user)
            ->test(ProfileIndex::class)
            ->set('currentPassword', 'kata-sandi-benar')
            ->set('password', 'KataSandiBaru9x')
            ->set('passwordConfirmation', 'KataSandiBaru9x')
            ->call('updatePassword')
            ->assertHasNoErrors();

        // The whole point: a session opened with the old password must not outlive it.
        $this->assertDatabaseMissing('sessions', ['id' => 'sesi-lama']);
    }

    public function test_one_users_password_change_leaves_other_accounts_alone(): void
    {
        $user = $this->user();
        $other = $this->user();

        $this->actingAs($user);
        $this->storeSession('sesi-orang-lain', $other);

        Livewire::actingAs($user)
            ->test(ProfileIndex::class)
            ->set('currentPassword', 'kata-sandi-benar')
            ->set('password', 'KataSandiBaru9x')
            ->set('passwordConfirmation', 'KataSandiBaru9x')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sessions', ['id' => 'sesi-orang-lain']);
    }

    public function test_the_section_is_hidden_on_a_driver_that_cannot_list_sessions(): void
    {
        config(['session.driver' => 'file']);

        Livewire::actingAs($this->user())
            ->test(ProfileIndex::class)
            ->assertViewHas('sessionsSupported', false);
    }

    public function test_an_unknown_user_agent_still_produces_a_label(): void
    {
        $this->assertSame('Perangkat tidak dikenal', DeviceName::fromUserAgent(null));
        $this->assertSame('Android', DeviceName::fromUserAgent('Dalvik/2.1.0 (Linux; Android 14)'));
    }

    protected function user(): User
    {
        return User::factory()->create(['password' => Hash::make('kata-sandi-benar')]);
    }

    protected function storeSession(string $id, User $user, string $userAgent = 'Mozilla/5.0'): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '203.0.113.10',
            'user_agent' => $userAgent,
            'payload' => 'kosong',
            'last_activity' => now()->timestamp,
        ]);
    }
}
