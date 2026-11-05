<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Notifications\AttackAnomalyDetected;
use App\Notifications\SslExpiringNotification;
use App\Notifications\WebsiteDownNotification;
use App\Notifications\WebsiteRecoveredNotification;
use App\Services\Monitoring\WebsiteChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Every kind of incident this app can raise has to reach the operator by email,
 * not just in the bell menu - that is the whole point of a monitoring dashboard
 * nobody is staring at.
 */
class IncidentEmailCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->configureMail();
        $this->admin = User::factory()->create();
    }

    public function test_downtime_emails(): void
    {
        Http::fake(['down.example' => Http::response('', 500)]);
        $website = Website::factory()->create(['url' => 'http://down.example', 'down_threshold_failures' => 1]);

        (new WebsiteChecker)->check($website);

        $this->assertEmailed(WebsiteDownNotification::class);
    }

    public function test_recovery_emails(): void
    {
        Http::fake(['up.example' => Http::response('ok', 200)]);
        $website = Website::factory()->create([
            'url' => 'http://up.example',
            'last_status' => 'down',
            'consecutive_failures' => 3,
        ]);
        // The all-clear answers a down email, so it needs the incident that email announced.
        Incident::create([
            'website_id' => $website->id,
            'type' => 'downtime',
            'severity' => 'critical',
            'title' => 'Down',
            'status' => 'open',
            'detected_at' => now()->subMinutes(10),
        ]);

        (new WebsiteChecker)->check($website);

        $this->assertEmailed(WebsiteRecoveredNotification::class);
        $this->assertSame(0, Incident::where('website_id', $website->id)->open()->count());
    }

    public function test_recovery_without_a_resolved_incident_emails_nobody(): void
    {
        // Down with no incident: e.g. it went down inside a maintenance window.
        Http::fake(['quiet-up.example' => Http::response('ok', 200)]);
        $website = Website::factory()->create([
            'url' => 'http://quiet-up.example',
            'last_status' => 'down',
            'consecutive_failures' => 3,
        ]);

        (new WebsiteChecker)->check($website);

        Notification::assertNotSentTo($this->admin, WebsiteRecoveredNotification::class);
        $this->assertSame('up', $website->fresh()->last_status);
    }

    public function test_a_response_time_spike_emails(): void
    {
        $website = Website::factory()->create(['url' => 'http://spike.example']);

        // A calm baseline for the spike to stand out against. 50ms is the floor
        // detectResponseTimeAnomaly() will consider at all, so the fake below has
        // to take more than 3x that (150ms) to register as a spike.
        foreach (range(1, 10) as $i) {
            $website->checks()->create([
                'is_up' => true, 'status_code' => 200, 'response_time_ms' => 50,
                'checked_at' => now()->subMinutes(11 - $i),
            ]);
        }

        Http::fake(['spike.example' => function () {
            usleep(200_000);

            return Http::response('ok', 200);
        }]);

        (new WebsiteChecker)->check($website);

        $this->assertEmailed(AttackAnomalyDetected::class);
    }

    public function test_a_drastic_content_change_emails(): void
    {
        $website = Website::factory()->create(['url' => 'http://deface.example']);
        $website->checks()->create([
            'is_up' => true, 'status_code' => 200, 'response_time_ms' => 100,
            'content_length' => 100, 'checked_at' => now()->subMinutes(5),
        ]);

        Http::fake(['deface.example' => Http::response(str_repeat('x', 5000), 200)]);

        (new WebsiteChecker)->check($website);

        $this->assertEmailed(AttackAnomalyDetected::class);
    }

    public function test_an_expiring_ssl_certificate_emails(): void
    {
        // This was the gap: the incident was raised but nobody was ever told, which
        // is the one case where silence guarantees the outage happens.
        Http::fake(['ssl.example' => Http::response('ok', 200)]);
        $website = Website::factory()->create(['url' => 'https://ssl.example', 'ssl_checked_at' => null]);

        $this->checkerWithCertificateExpiring(now()->addDays(3))->check($website);

        $this->assertEmailed(SslExpiringNotification::class);
        $this->assertDatabaseHas('incidents', ['website_id' => $website->id, 'type' => 'ssl']);
    }

    public function test_a_healthy_certificate_emails_nobody(): void
    {
        Http::fake(['fine.example' => Http::response('ok', 200)]);
        $website = Website::factory()->create(['url' => 'https://fine.example', 'ssl_checked_at' => null]);

        $this->checkerWithCertificateExpiring(now()->addMonths(6))->check($website);

        Notification::assertNotSentTo($this->admin, SslExpiringNotification::class);
    }

    public function test_the_same_certificate_is_only_warned_about_once(): void
    {
        Http::fake(['once.example' => Http::response('ok', 200)]);
        $website = Website::factory()->create(['url' => 'https://once.example', 'ssl_checked_at' => null]);

        $this->checkerWithCertificateExpiring(now()->addDays(3))->check($website);
        $website->update(['ssl_checked_at' => null]);
        $this->checkerWithCertificateExpiring(now()->addDays(3))->check($website);

        Notification::assertSentToTimes($this->admin, SslExpiringNotification::class, 1);
        $this->assertSame(1, Incident::where('type', 'ssl')->count());
    }

    public function test_an_expired_certificate_emails(): void
    {
        Http::fake(['expired.example' => Http::response('ok', 200)]);
        $website = Website::factory()->create(['url' => 'https://expired.example', 'ssl_checked_at' => null]);

        $this->checkerWithCertificate(['expires_at' => now()->subDays(2), 'chain_valid' => null])->check($website);

        $this->assertEmailed(SslExpiringNotification::class);
        $this->assertSslIncident($website, 'expired', 'critical');
    }

    public function test_a_hostname_mismatch_emails(): void
    {
        Http::fake(['mismatch.example' => Http::response('ok', 200)]);
        $website = Website::factory()->create(['url' => 'https://mismatch.example', 'ssl_checked_at' => null]);

        $this->checkerWithCertificate(['names' => ['other.example'], 'hostname_match' => false])->check($website);

        $this->assertEmailed(SslExpiringNotification::class);
        $this->assertSslIncident($website, 'hostname', 'critical');
    }

    public function test_an_untrusted_chain_emails(): void
    {
        Http::fake(['chain.example' => Http::response('ok', 200)]);
        $website = Website::factory()->create(['url' => 'https://chain.example', 'ssl_checked_at' => null]);

        $this->checkerWithCertificate(['chain_valid' => false, 'error' => 'SSL operation failed: certificate verify failed'])->check($website);

        $this->assertEmailed(SslExpiringNotification::class);
        $this->assertSslIncident($website, 'chain', 'warning');
    }

    public function test_nothing_is_emailed_while_email_is_switched_off(): void
    {
        Setting::set('mail_enabled', '0');
        Http::fake(['quiet.example' => Http::response('', 500)]);
        $website = Website::factory()->create(['url' => 'http://quiet.example', 'down_threshold_failures' => 1]);

        (new WebsiteChecker)->check($website);

        // The bell-menu entry still happens; only the email channel drops out.
        Notification::assertSentTo($this->admin, WebsiteDownNotification::class, function ($notification) {
            return $notification->via($this->admin) === ['database'];
        });
    }

    protected function assertSslIncident(Website $website, string $reason, string $severity): void
    {
        $incident = Incident::where('website_id', $website->id)->where('type', 'ssl')->sole();

        $this->assertSame($reason, $incident->meta['reason']);
        $this->assertSame($severity, $incident->severity);
    }

    /** Asserts the notification went out AND that email was one of its channels. */
    protected function assertEmailed(string $notificationClass): void
    {
        Notification::assertSentTo($this->admin, $notificationClass, function ($notification) {
            $this->assertContains('mail', $notification->via($this->admin), 'email channel is active');

            // Rendering it here is what catches a template that references data the
            // notification never supplies.
            $this->assertNotEmpty($notification->toMail($this->admin)->render());

            return true;
        });
    }

    /** A checker whose TLS probe returns a fixed date instead of opening a socket. */
    protected function checkerWithCertificateExpiring(Carbon $expiresAt): WebsiteChecker
    {
        return $this->checkerWithCertificate(['expires_at' => $expiresAt]);
    }

    /** A checker whose TLS probe returns a healthy certificate, with $overrides applied. */
    protected function checkerWithCertificate(array $overrides): WebsiteChecker
    {
        $cert = $overrides + [
            'reachable' => true,
            'expires_at' => now()->addMonths(6),
            'issuer' => "R3 (Let's Encrypt)",
            'names' => ['ssl.example'],
            'chain_valid' => true,
            'hostname_match' => true,
            'protocol' => 'TLSv1.3',
            'error' => null,
        ];

        return new class($cert) extends WebsiteChecker
        {
            public function __construct(protected array $cert) {}

            protected function inspectCertificate(string $url): ?array
            {
                return $this->cert;
            }
        };
    }

    protected function configureMail(): void
    {
        Setting::set('mail_enabled', '1');
        Setting::set('mail_host', 'smtp.example.com');
        Setting::set('mail_from_address', 'noreply@example.com');
    }
}
