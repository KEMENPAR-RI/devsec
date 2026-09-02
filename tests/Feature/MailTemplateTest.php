<?php

namespace Tests\Feature;

use App\Mail\IncidentEmail;
use App\Mail\TemplatedMail;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\WebsiteDownNotification;
use App\Services\Notifications\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class MailTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_template_renders_both_an_incident_and_a_password_reset(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['name' => 'Portal Layanan']);
        $incident = Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'Portal Layanan tidak dapat diakses', 'status' => 'open', 'detected_at' => now(),
        ]);

        $incidentHtml = (new WebsiteDownNotification($website, $incident))->toMail($user)->render();
        $resetHtml = (new ResetPasswordNotification('token-uji'))->toMail($user)->render();

        // Same shell for both - the header block comes from the shared template.
        foreach ([$incidentHtml, $resetHtml] as $html) {
            $this->assertStringContainsString(config('app.name'), $html);
            $this->assertStringContainsString('max-width:520px', $html);
        }

        // ...with content that belongs to each.
        $this->assertStringContainsString('Portal Layanan', $incidentHtml);
        $this->assertStringContainsString('Lihat Detail', $incidentHtml);

        $this->assertStringContainsString('Atur Ulang Kata Sandi', $resetHtml);
        $this->assertStringContainsString('Berlaku selama', $resetHtml);
        // The old template hard-coded these; a reset email must not carry them.
        $this->assertStringNotContainsString('Lihat Detail', $resetHtml);
        $this->assertStringNotContainsString('>Situs<', $resetHtml);
    }

    public function test_the_reset_email_carries_a_working_link_and_the_apps_subject(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $token = Password::createToken($user);

        $mail = (new ResetPasswordNotification($token))->toMail($user);

        $this->assertSame('['.config('app.name').'] Atur ulang kata sandi Anda', $mail->envelope()->subject);
        $this->assertStringContainsString(
            route('password.reset', ['token' => $token, 'email' => 'ada@example.com']),
            $mail->render()
        );
    }

    public function test_an_email_with_no_action_shows_no_button_and_no_empty_label(): void
    {
        $html = TemplatedMail::for([
            'title' => 'Pemberitahuan',
            'message' => 'Tanpa tautan apa pun.',
        ])->render();

        $this->assertStringContainsString('Pemberitahuan', $html);
        $this->assertStringNotContainsString('<a href=', $html, 'no action means no button');
        // An absent eyebrow must not leave a bare coloured line above the title.
        $this->assertStringNotContainsString('text-transform:uppercase', $html);
    }

    public function test_details_rows_are_escaped_and_blank_ones_dropped(): void
    {
        $html = app(MailSettings::class)->renderDetails([
            'Situs' => 'Contoh & Mitra',
            'Waktu' => '',
            'Catatan' => '<script>alert(1)</script>',
        ]);

        $this->assertStringContainsString('Contoh &amp; Mitra', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('Waktu', $html, 'a blank value drops its whole row');
    }

    public function test_details_render_nothing_at_all_when_every_row_is_blank(): void
    {
        $this->assertSame('', app(MailSettings::class)->renderDetails(['Situs' => '', 'Waktu' => null]));
    }

    public function test_a_customized_template_is_still_honoured(): void
    {
        Setting::set('mail_template_subject', 'Halo: {{title}}');
        Setting::set('mail_template_body', '<p>{{title}} - {{action_label}} di {{action_url}}</p>');

        $mail = TemplatedMail::for([
            'title' => 'Uji Kustom',
            'action_url' => 'https://contoh.test/detail',
            'action_label' => 'Buka',
        ]);

        $this->assertSame('Halo: Uji Kustom', $mail->envelope()->subject);
        $this->assertStringContainsString('Uji Kustom - Buka di https://contoh.test/detail', $mail->render());
    }

    public function test_incident_email_is_still_its_own_named_mailable(): void
    {
        // The notification classes and their tests refer to it by name; it is just
        // a TemplatedMail underneath now.
        $this->assertInstanceOf(TemplatedMail::class, new IncidentEmail(['title' => 'x']));
    }
}
