<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Notifications\IncidentEscalated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The gap here is not detection - the first alert already went out. It is the
 * far more common failure where everyone assumed somebody else picked it up.
 */
class IncidentEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        Setting::set('escalation_enabled', '1');
        Setting::set('escalation_minutes', '30');
    }

    public function test_a_stale_critical_incident_is_chased(): void
    {
        Notification::fake();

        $incident = $this->incident(detectedMinutesAgo: 45);

        $this->artisan('incidents:escalate')->assertSuccessful();

        Notification::assertSentTo($this->admin, IncidentEscalated::class);
        $this->assertNotNull($incident->fresh()->meta['escalated_at']);
    }

    public function test_an_incident_inside_the_window_is_left_alone(): void
    {
        Notification::fake();

        $this->incident(detectedMinutesAgo: 10);

        $this->artisan('incidents:escalate')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_exactly_one_reminder_is_sent_per_incident(): void
    {
        Notification::fake();

        $this->incident(detectedMinutesAgo: 45);

        $this->artisan('incidents:escalate');
        $this->artisan('incidents:escalate');
        $this->artisan('incidents:escalate');

        // A repeating alarm is one people mute, and a muted channel is worse
        // than no channel.
        Notification::assertSentToTimes($this->admin, IncidentEscalated::class, 1);
    }

    public function test_a_resolved_incident_is_never_chased(): void
    {
        Notification::fake();

        $incident = $this->incident(detectedMinutesAgo: 45);
        $incident->update(['status' => 'resolved', 'resolved_at' => now()]);

        $this->artisan('incidents:escalate')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_warning_level_incident_is_not_chased(): void
    {
        Notification::fake();

        $this->incident(detectedMinutesAgo: 45, severity: 'warning');

        $this->artisan('incidents:escalate')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_nothing_happens_while_escalation_is_switched_off(): void
    {
        Notification::fake();
        Setting::set('escalation_enabled', '0');

        $this->incident(detectedMinutesAgo: 45);

        $this->artisan('incidents:escalate')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_site_under_maintenance_is_not_chased(): void
    {
        Notification::fake();

        $incident = $this->incident(detectedMinutesAgo: 45);

        MaintenanceWindow::create([
            'website_id' => $incident->website_id,
            'reason' => 'Migrasi server',
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->addHour(),
        ]);

        // Down on purpose; chasing it is the opposite of useful.
        $this->artisan('incidents:escalate')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_configured_window_is_respected(): void
    {
        Notification::fake();
        Setting::set('escalation_minutes', '120');

        $this->incident(detectedMinutesAgo: 45);

        $this->artisan('incidents:escalate')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_reminder_says_how_long_it_has_been_running(): void
    {
        $incident = $this->incident(detectedMinutesAgo: 45);
        $payload = (new IncidentEscalated($incident->website, $incident))->toArray($this->admin);

        $this->assertStringContainsString('BELUM SELESAI', $payload['title']);
        $this->assertStringContainsString('45 menit', $payload['message']);
        $this->assertSame('critical', $payload['severity']);
    }

    protected function incident(int $detectedMinutesAgo, string $severity = 'critical'): Incident
    {
        $website = Website::factory()->create(['url' => 'https://eskalasi-'.uniqid().'.example']);

        return Incident::create([
            'website_id' => $website->id,
            'type' => 'downtime',
            'severity' => $severity,
            'title' => "{$website->name} tidak dapat diakses",
            'description' => 'Gagal merespons.',
            'status' => 'open',
            'detected_at' => now()->subMinutes($detectedMinutesAgo),
        ]);
    }
}
