<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\JudolKeyword;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Notifications\AttackAnomalyDetected;
use App\Notifications\JudolContentDetected;
use App\Notifications\SslExpiringNotification;
use App\Notifications\WebsiteDownNotification;
use App\Notifications\WebsiteRecoveredNotification;
use App\Services\Monitoring\JudolScanner;
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

        (new WebsiteChecker)->check($website);

        $this->assertEmailed(WebsiteRecoveredNotification::class);
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

    public function test_judol_emails(): void
    {
        JudolKeyword::firstOrCreate(['keyword' => 'slot gacor'], ['is_active' => true]);
        Http::fake([
            'judol.example/sitemap.xml' => Http::response('', 404),
            'judol.example' => Http::response('<title>Slot Gacor</title>', 200),
        ]);
        $website = Website::factory()->create(['url' => 'http://judol.example']);

        (new JudolScanner)->scan($website);

        $this->assertEmailed(JudolContentDetected::class);
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
        return new class($expiresAt) extends WebsiteChecker
        {
            public function __construct(protected Carbon $expiresAt) {}

            protected function certificateExpiryDate(string $url): ?Carbon
            {
                return $this->expiresAt;
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
