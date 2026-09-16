<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Monitoring\WebsiteChecker;
use App\Services\Notifications\WebhookNotifier;
use App\Services\Notifications\WebhookSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * Email is where an incident gets read eventually; this channel is where it gets
 * read now. What matters is that it fires once per event, only for the severities
 * asked for, and never takes the monitoring loop down with it.
 */
class WebhookNotificationTest extends TestCase
{
    use RefreshDatabase;

    // --- Wiring ------------------------------------------------------------

    public function test_nothing_is_sent_while_the_channel_is_off(): void
    {
        Http::fake();

        $this->assertFalse(app(WebhookNotifier::class)->send([
            'title' => 'Uji', 'message' => 'Uji', 'severity' => 'critical',
        ]));

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_while_the_configuration_is_incomplete(): void
    {
        Http::fake();
        Setting::set('webhook_enabled', '1');
        // No token or chat id saved.

        $this->assertFalse(app(WebhookSettings::class)->isConfigured());
        $this->assertFalse(app(WebhookNotifier::class)->send([
            'title' => 'Uji', 'message' => 'Uji', 'severity' => 'critical',
        ]));

        Http::assertNothingSent();
    }

    public function test_the_message_carries_the_severity_and_title(): void
    {
        Http::fake(['*' => Http::response('{"ok":true}', 200)]);
        $this->configureTelegram();

        $this->assertTrue(app(WebhookNotifier::class)->send([
            'title' => 'Situs Contoh tidak dapat diakses',
            'message' => 'Gagal merespons tiga kali berturut-turut.',
            'severity' => 'critical',
            'url' => 'https://pemantau.example/situs/1',
        ]));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bot123:ABC/sendMessage'
                && str_contains($request['text'], '[KRITIS]')
                && str_contains($request['text'], 'Situs Contoh tidak dapat diakses');
        });
    }

    public function test_telegram_addresses_the_bot_api_with_the_chat_id(): void
    {
        Http::fake(['*' => Http::response('{"ok":true}', 200)]);

        Setting::set('webhook_enabled', '1');
        Setting::set('webhook_token', Crypt::encryptString('123:ABC'));
        Setting::set('webhook_target', '-1001234567890');

        app(WebhookNotifier::class)->send(['title' => 'Uji', 'message' => 'Pesan', 'severity' => 'critical']);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bot123:ABC/sendMessage'
            && $request['chat_id'] === '-1001234567890');
    }

    // --- Severity filtering ------------------------------------------------

    public function test_a_severity_that_was_not_asked_for_is_skipped(): void
    {
        Http::fake();
        $this->configureTelegram();
        Setting::set('webhook_severities', 'critical');

        $this->assertFalse(app(WebhookNotifier::class)->send([
            'title' => 'Ringan', 'message' => 'Ringan', 'severity' => 'info',
        ]));

        Http::assertNothingSent();
    }

    public function test_an_empty_severity_choice_still_keeps_critical(): void
    {
        Setting::set('webhook_severities', '');

        // Silencing everything by accident would be worse than a noisy channel.
        $this->assertSame(['critical'], app(WebhookSettings::class)->severities());
    }

    // --- Behaviour inside the monitoring loop -------------------------------

    public function test_one_outage_produces_exactly_one_message_regardless_of_admin_count(): void
    {
        Notification::fake();
        $this->configureTelegram();

        User::factory()->count(4)->create();

        Http::fake([
            'api.telegram.org/*' => Http::response('{"ok":true}', 200),
            '*' => Http::response('', 500),
        ]);

        $website = Website::factory()->create(['url' => 'https://situs-webhook.example', 'down_threshold_failures' => 1]);
        app(WebsiteChecker::class)->check($website);

        // Four admins, one shared channel, one message.
        $posted = collect(Http::recorded())
            ->filter(fn (array $pair) => $pair[0]->url() === 'https://api.telegram.org/bot123:ABC/sendMessage');

        $this->assertCount(1, $posted);
    }

    public function test_a_failing_channel_does_not_break_the_check(): void
    {
        Notification::fake();
        $this->configureTelegram();

        Http::fake([
            'hooks.contoh.example/*' => fn () => throw new RuntimeException('kanal mati'),
            '*' => Http::response('', 500),
        ]);

        $website = Website::factory()->create(['url' => 'https://situs-webhook2.example', 'down_threshold_failures' => 1]);

        // The check that found the problem must still be recorded even when the
        // place we wanted to shout about it is unreachable.
        app(WebsiteChecker::class)->check($website);

        $this->assertDatabaseCount('website_checks', 1);
        $this->assertDatabaseHas('incidents', ['website_id' => $website->id, 'type' => 'downtime']);
    }

    // --- The test button ---------------------------------------------------

    public function test_the_test_button_reports_a_rejection_rather_than_failing_silently(): void
    {
        Http::fake(['*' => Http::response('forbidden', 403)]);
        $this->configureTelegram();

        $result = app(WebhookNotifier::class)->test();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('403', $result['message']);
    }

    public function test_the_test_button_confirms_a_successful_send(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->configureTelegram();

        $this->assertTrue(app(WebhookNotifier::class)->test()['success']);
    }

    protected function configureTelegram(): void
    {
        Setting::set('webhook_enabled', '1');
        Setting::set('webhook_token', Crypt::encryptString('123:ABC'));
        Setting::set('webhook_target', '-1001234567890');
        Setting::set('webhook_severities', 'critical,warning,info');
    }
}
