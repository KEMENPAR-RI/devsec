<?php

namespace Tests\Feature;

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Models\Setting;
use App\Models\User;
use App\Services\Security\Recaptcha;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class RecaptchaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new RoleSeeder)->run();
        RateLimiter::clear('reset|');

    }

    public function test_it_is_off_until_both_keys_are_configured(): void
    {
        $recaptcha = app(Recaptcha::class);
        $this->assertFalse($recaptcha->enabled());

        // Switched on but half-filled must still count as off, or the toggle alone
        // would lock everybody out of the dashboard.
        Setting::set('recaptcha_enabled', '1');
        Setting::set('recaptcha_site_key', 'site-key');
        $this->assertFalse(app(Recaptcha::class)->enabled());

        $this->configure();
        $this->assertTrue(app(Recaptcha::class)->enabled());
    }

    public function test_the_login_page_loads_google_only_when_configured(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('recaptcha/api.js');

        $this->configure();

        $this->get(route('login'))->assertOk()->assertSee('recaptcha/api.js', false);
    }

    public function test_login_works_untouched_while_recaptcha_is_off(): void
    {
        Http::fake();
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'kata-sandi-benar']);

        Livewire::test(Login::class)
            ->set('email', 'ada@example.com')
            ->set('password', 'kata-sandi-benar')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticated();
        Http::assertNothingSent();
    }

    public function test_a_good_score_lets_the_login_through(): void
    {
        $this->configure();
        $this->fakeVerdict(['success' => true, 'score' => 0.9, 'action' => 'login']);
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'kata-sandi-benar']);

        Livewire::test(Login::class)
            ->set('email', 'ada@example.com')
            ->set('password', 'kata-sandi-benar')
            ->set('recaptchaToken', 'token-dari-browser')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticated();
    }

    public function test_a_low_score_is_refused_before_the_password_is_checked(): void
    {
        $this->configure();
        $this->fakeVerdict(['success' => true, 'score' => 0.1, 'action' => 'login']);
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'kata-sandi-benar']);

        Livewire::test(Login::class)
            ->set('email', 'ada@example.com')
            ->set('password', 'kata-sandi-benar')
            ->set('recaptchaToken', 'token-bot')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_rejected_token_is_refused(): void
    {
        $this->configure();
        $this->fakeVerdict(['success' => false, 'error-codes' => ['invalid-input-response']]);
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'kata-sandi-benar']);

        Livewire::test(Login::class)
            ->set('email', 'ada@example.com')
            ->set('password', 'kata-sandi-benar')
            ->set('recaptchaToken', 'token-palsu')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_token_minted_for_another_form_cannot_be_replayed_here(): void
    {
        $this->configure();
        $this->fakeVerdict(['success' => true, 'score' => 0.9, 'action' => 'password_reset']);
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'kata-sandi-benar']);

        Livewire::test(Login::class)
            ->set('email', 'ada@example.com')
            ->set('password', 'kata-sandi-benar')
            ->set('recaptchaToken', 'token-form-lain')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_missing_token_asks_the_user_to_reload_rather_than_signing_them_in(): void
    {
        $this->configure();
        Http::fake();
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'kata-sandi-benar']);

        Livewire::test(Login::class)
            ->set('email', 'ada@example.com')
            ->set('password', 'kata-sandi-benar')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_an_unreachable_google_lets_people_in_rather_than_locking_them_out(): void
    {
        // Deliberate: this is the dashboard people sign into to handle outages, so
        // Google being down must not become an outage of its own. An explicit
        // negative verdict still blocks - see the tests above.
        $this->configure();
        Http::fake([Recaptcha::VERIFY_URL => Http::response('', 503)]);
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'kata-sandi-benar']);

        Livewire::test(Login::class)
            ->set('email', 'ada@example.com')
            ->set('password', 'kata-sandi-benar')
            ->set('recaptchaToken', 'token-apa-saja')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticated();
    }

    public function test_the_threshold_is_configurable(): void
    {
        $this->configure();
        Setting::set('recaptcha_min_score', '0.8');
        $this->fakeVerdict(['success' => true, 'score' => 0.7, 'action' => 'login']);

        $verdict = app(Recaptcha::class)->verify('token', 'login');

        $this->assertFalse($verdict['ok']);
        $this->assertSame(0.7, $verdict['score']);
    }

    public function test_the_forgot_password_form_is_gated_too(): void
    {
        Notification::fake();
        $this->configure();
        Setting::set('mail_enabled', '1');
        Setting::set('mail_host', 'smtp.example.com');
        Setting::set('mail_from_address', 'noreply@example.com');
        $this->fakeVerdict(['success' => true, 'score' => 0.1, 'action' => 'password_reset']);
        User::factory()->create(['email' => 'ada@example.com']);

        Livewire::test(ForgotPassword::class)
            ->set('email', 'ada@example.com')
            ->set('recaptchaToken', 'token-bot')
            ->call('sendResetLink')
            ->assertHasErrors('email')
            ->assertSet('sent', false);

        Notification::assertNothingSent();
    }

    public function test_the_secret_key_is_stored_encrypted(): void
    {
        $this->configure();

        $this->assertNotSame('secret-key', Setting::get('recaptcha_secret_key'));
        $this->assertSame('secret-key', app(Recaptcha::class)->secretKey());
    }

    protected function configure(): void
    {
        Setting::set('recaptcha_enabled', '1');
        Setting::set('recaptcha_site_key', 'site-key');
        Setting::set('recaptcha_secret_key', Crypt::encryptString('secret-key'));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function fakeVerdict(array $body): void
    {
        Http::fake([Recaptcha::VERIFY_URL => Http::response($body, 200)]);
    }
}
