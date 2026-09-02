<?php

namespace Tests\Feature;

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Websites\Index as WebsitesIndex;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Notifications\ResetPasswordNotification;
use App\Services\AppSettings;
use App\Services\Notifications\MailSettings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class AppConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new RoleSeeder)->run();
    }

    // --- The bug that stopped every email leaving the app ------------------

    public function test_applying_mail_settings_actually_selects_the_smtp_mailer(): void
    {
        // Regression guard: configuring mail.mailers.smtp.* without also pointing
        // mail.default at it left every message going to the log driver, so nothing
        // was ever delivered - reset links included.
        config(['mail.default' => 'log']);
        $this->configureMail();

        app(MailSettings::class)->applyToRuntime();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
    }

    public function test_a_half_configured_install_is_left_on_the_log_driver(): void
    {
        config(['mail.default' => 'log']);
        Setting::set('mail_enabled', '0');

        app(MailSettings::class)->applyToRuntime();

        $this->assertSame('log', config('mail.default'), 'nothing to send through yet');
        $this->assertFalse(app(MailSettings::class)->isConfigured());
    }

    public function test_the_reset_page_says_so_instead_of_pretending_when_mail_is_off(): void
    {
        Notification::fake();
        Setting::set('mail_enabled', '0');
        User::factory()->create(['email' => 'ada@example.com']);

        Livewire::test(ForgotPassword::class)
            ->set('email', 'ada@example.com')
            ->call('sendResetLink')
            ->assertHasErrors('email')
            ->assertSet('sent', false);

        Notification::assertNothingSent();
    }

    public function test_a_configured_install_still_sends_the_reset_link(): void
    {
        Notification::fake();
        RateLimiter::clear('reset|');
        $this->configureMail();
        $user = User::factory()->create(['email' => 'ada@example.com']);

        Livewire::test(ForgotPassword::class)
            ->set('email', 'ada@example.com')
            ->call('sendResetLink')
            ->assertHasNoErrors()
            ->assertSet('sent', true);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    // --- Konfigurasi Aplikasi ----------------------------------------------

    public function test_the_app_name_and_timezone_are_editable_and_take_effect(): void
    {
        Livewire::actingAs($this->superadmin())
            ->test(SettingsIndex::class)
            ->set('appName', 'Monitoring Dinas')
            ->set('appTimezone', 'Asia/Makassar')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Monitoring Dinas', app(AppSettings::class)->name());
        $this->assertSame('Asia/Makassar', app(AppSettings::class)->timezone());
        $this->assertSame('Monitoring Dinas', config('app.name'));
    }

    public function test_an_unknown_timezone_is_rejected(): void
    {
        Livewire::actingAs($this->superadmin())
            ->test(SettingsIndex::class)
            ->set('appTimezone', 'Mars/Olympus_Mons')
            ->call('save')
            ->assertHasErrors('appTimezone');
    }

    public function test_new_site_defaults_come_from_settings_not_from_hard_coded_numbers(): void
    {
        Livewire::actingAs($this->superadmin())
            ->test(SettingsIndex::class)
            ->set('defaultCheckIntervalMinutes', 12)
            ->set('defaultJudolScanIntervalMinutes', 240)
            ->set('defaultDownThresholdFailures', 5)
            ->set('defaultResponseTimeThresholdMs', 20000)
            ->call('save')
            ->assertHasNoErrors();

        Livewire::actingAs($this->superadmin())
            ->test(WebsitesIndex::class)
            ->call('openCreate')
            ->assertSet('checkIntervalMinutes', 12)
            ->assertSet('judolScanIntervalMinutes', 240)
            ->assertSet('downThresholdFailures', 5)
            ->assertSet('responseTimeThresholdMs', 20000);
    }

    public function test_bulk_import_uses_the_same_defaults(): void
    {
        Setting::set('default_check_interval_minutes', '30');
        Setting::set('default_response_time_threshold_ms', '25000');

        Livewire::actingAs($this->superadmin())
            ->test(WebsitesIndex::class)
            ->call('openBulk')
            ->set('bulkInput', 'contoh-massal.go.id')
            ->call('bulkImport');

        $this->assertDatabaseHas('websites', [
            'url' => 'https://contoh-massal.go.id',
            'check_interval_minutes' => 30,
            'response_time_threshold_ms' => 25000,
        ]);
    }

    // --- Tabs ---------------------------------------------------------------

    public function test_the_settings_page_shows_the_three_renamed_tabs(): void
    {
        $this->actingAs($this->superadmin())
            ->get(route('pengaturan'))
            ->assertOk()
            ->assertSee('Klasifikasi AI')
            ->assertSee('Konfigurasi Email')
            ->assertSee('Konfigurasi Aplikasi')
            // The template editor kept its home inside the email tab.
            ->assertSee('Template Email')
            ->assertSee('Bawaan Situs Baru');
    }

    // --- Seeding ------------------------------------------------------------

    public function test_the_database_seeder_fills_in_every_uuid(): void
    {
        // Regression guard: DatabaseSeeder used WithoutModelEvents, which muted the
        // "creating" hook that generates uuids, so `migrate:fresh --seed` died on
        // the first insert with "Field 'uuid' doesn't have a default value".
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(User::whereNull('uuid')->doesntExist());
        $this->assertTrue(Website::whereNull('uuid')->doesntExist());
        $this->assertGreaterThan(0, User::count());
        $this->assertGreaterThan(0, Website::count());
    }

    protected function configureMail(): void
    {
        Setting::set('mail_enabled', '1');
        Setting::set('mail_host', 'smtp.example.com');
        Setting::set('mail_from_address', 'noreply@example.com');
    }

    protected function superadmin(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id')]);
    }
}
