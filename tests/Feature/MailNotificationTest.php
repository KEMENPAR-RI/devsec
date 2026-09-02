<?php

namespace Tests\Feature;

use App\Livewire\Settings\Index as SettingsIndex;
use App\Mail\IncidentEmail;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Notifications\WebsiteDownNotification;
use App\Services\Monitoring\WebsiteChecker;
use App\Services\Notifications\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class MailNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_email_is_sent_when_mail_notifications_are_disabled(): void
    {
        Notification::fake();
        Http::fake(fn () => Http::response('', 500));

        $user = User::factory()->create();
        $website = Website::factory()->create(['down_threshold_failures' => 1]);

        (new WebsiteChecker)->check($website);

        Notification::assertSentTo($user, WebsiteDownNotification::class, function ($notification, array $channels) {
            return $channels === ['database'];
        });
    }

    /**
     * Laravel's MailChannel decomposes a toMail(): Mailable into a raw
     * view/data/callback triple before handing it to the mailer - Mail::fake()
     * only tracks it as a Mailable on a direct Mail::to()->send() call, so the
     * documented way to test a notification's Mailable output is to fake
     * Notification and inspect what toMail() itself built (see
     * "the test email button..." below for the direct-send path, which IS
     * trackable via Mail::fake() since it bypasses the notification system).
     */
    public function test_a_rendered_incident_email_is_sent_when_mail_notifications_are_enabled(): void
    {
        Notification::fake();
        Http::fake(fn () => Http::response('', 500));

        $this->enableMail();

        $user = User::factory()->create();
        $website = Website::factory()->create(['name' => 'Portal Kemenpar', 'down_threshold_failures' => 1]);

        (new WebsiteChecker)->check($website);

        Notification::assertSentTo(
            $user,
            WebsiteDownNotification::class,
            function (WebsiteDownNotification $notification, array $channels) use ($user, $website) {
                $mail = $notification->toMail($user);

                return $channels === ['database', 'mail']
                    && $mail instanceof IncidentEmail
                    && $mail->hasTo($user->email)
                    && $mail->data['website_name'] === $website->name
                    && $mail->data['type'] === 'downtime';
            }
        );
    }

    public function test_email_channel_is_added_alongside_the_database_channel(): void
    {
        $this->enableMail();

        $notification = new WebsiteDownNotification(
            Website::factory()->make(),
            $this->makeIncident(),
        );

        $this->assertSame(['database', 'mail'], $notification->via(User::factory()->make()));
    }

    public function test_email_channel_is_absent_when_mail_is_disabled(): void
    {
        $notification = new WebsiteDownNotification(
            Website::factory()->make(),
            $this->makeIncident(),
        );

        $this->assertSame(['database'], $notification->via(User::factory()->make()));
    }

    protected function makeIncident(): Incident
    {
        return Incident::make([
            'type' => 'downtime',
            'severity' => 'critical',
            'title' => 'Situs down',
            'status' => 'open',
            'detected_at' => now(),
        ]);
    }

    public function test_mail_settings_substitutes_placeholders_in_subject_and_body(): void
    {
        Setting::set('mail_template_subject', '[{{app_name}}] {{title}}');
        Setting::set('mail_template_body', '<p>{{message}} - {{website_name}} ({{website_url}})</p><a href="{{action_url}}">Lihat</a>');

        $settings = app(MailSettings::class);

        $data = [
            'title' => 'Situs down',
            'message' => 'Tidak dapat diakses',
            'website_name' => 'Portal Kemenpar',
            'website_url' => 'https://kemenpar.go.id',
            'action_url' => 'https://dashboard.test/situs/1',
        ];

        $subject = $settings->renderSubject($data);
        $body = $settings->renderBody($data);

        $this->assertSame('['.config('app.name').'] Situs down', $subject);
        $this->assertStringContainsString('Tidak dapat diakses - Portal Kemenpar (https://kemenpar.go.id)', $body);
        $this->assertStringContainsString('href="https://dashboard.test/situs/1"', $body);
    }

    public function test_mail_settings_falls_back_to_the_default_template_until_overridden(): void
    {
        $settings = app(MailSettings::class);

        $this->assertSame($settings->defaultSubjectTemplate(), $settings->subjectTemplate());
        $this->assertSame($settings->defaultBodyTemplate(), $settings->bodyTemplate());
    }

    public function test_the_test_email_button_sends_a_rendered_test_email_to_the_current_user(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'admin@example.com']);

        Livewire::actingAs($user)
            ->test(SettingsIndex::class)
            ->set('mailHost', 'smtp.example.com')
            ->set('mailPort', 587)
            ->set('mailFromAddress', 'noreply@example.com')
            ->call('testEmail')
            ->assertSet('mailTestResult.success', true);

        Mail::assertSent(IncidentEmail::class, fn (IncidentEmail $mail) => $mail->hasTo('admin@example.com'));
    }

    protected function enableMail(): void
    {
        Setting::set('mail_enabled', '1');
        Setting::set('mail_host', 'smtp.example.com');
        Setting::set('mail_port', '587');
        Setting::set('mail_from_address', 'noreply@example.com');
        Setting::set('mail_from_name', config('app.name').' Monitoring');
    }
}
