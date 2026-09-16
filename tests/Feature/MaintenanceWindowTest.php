<?php

namespace Tests\Feature;

use App\Livewire\Websites\Show as WebsiteShow;
use App\Models\MaintenanceWindow;
use App\Models\User;
use App\Models\Website;
use App\Notifications\WebsiteDownNotification;
use App\Notifications\WebsiteRecoveredNotification;
use App\Services\Monitoring\WebsiteChecker;
use App\Support\Dates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Planned downtime must not page anybody.
 *
 * False alarms are not cosmetic: an operator who has learned the red dot is
 * usually a migration window is the one who will miss the window that isn't.
 */
class MaintenanceWindowTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // notifyAdmins() sends to User::all(), so without a recipient every
        // "an alert was sent" assertion would pass for the wrong reason.
        $this->admin = User::factory()->create();
    }

    // --- Suppression -------------------------------------------------------

    public function test_an_outage_during_a_window_raises_no_incident_and_no_email(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response('', 500)]);

        $website = $this->website();
        $this->openWindow($website);

        $this->runChecksUntilDown($website);

        $this->assertSame('down', $website->fresh()->last_status);
        $this->assertDatabaseCount('incidents', 0);
        Notification::assertNothingSent();
    }

    public function test_the_same_outage_outside_a_window_does_raise_one(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response('', 500)]);

        $website = $this->website();

        $this->runChecksUntilDown($website);

        $this->assertDatabaseHas('incidents', ['website_id' => $website->id, 'type' => 'downtime']);
        Notification::assertSentTimes(WebsiteDownNotification::class, 1);
    }

    public function test_checks_are_still_recorded_during_a_window(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response('', 500)]);

        $website = $this->website();
        $this->openWindow($website);

        app(WebsiteChecker::class)->check($website);

        // The uptime history has to stay honest, or a planned outage quietly
        // flatters the monthly figures.
        $this->assertDatabaseCount('website_checks', 1);
        $this->assertDatabaseHas('website_checks', ['website_id' => $website->id, 'is_up' => false]);
    }

    public function test_recovery_inside_a_window_sends_no_all_clear_either(): void
    {
        Notification::fake();

        $website = $this->website();
        $website->update(['last_status' => 'down', 'consecutive_failures' => 3]);
        $this->openWindow($website);

        Http::fake(['*' => Http::response('ok', 200)]);
        app(WebsiteChecker::class)->check($website);

        $this->assertSame('up', $website->fresh()->last_status);
        Notification::assertNotSentTo($this->admin, WebsiteRecoveredNotification::class);
    }

    public function test_a_window_that_has_ended_stops_suppressing(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response('', 500)]);

        $website = $this->website();
        MaintenanceWindow::create([
            'website_id' => $website->id,
            'reason' => 'Selesai kemarin',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);

        $this->runChecksUntilDown($website);

        $this->assertDatabaseHas('incidents', ['website_id' => $website->id, 'type' => 'downtime']);
    }

    public function test_an_all_sites_window_covers_a_site_that_was_never_named(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response('', 500)]);

        $website = $this->website();
        MaintenanceWindow::create([
            'website_id' => null,
            'reason' => 'Migrasi server',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->runChecksUntilDown($website);

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_a_window_on_one_site_does_not_cover_another(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response('', 500)]);

        $quiet = $this->website();
        $loud = $this->website();
        $this->openWindow($quiet);

        $this->runChecksUntilDown($loud);

        $this->assertDatabaseHas('incidents', ['website_id' => $loud->id]);
        $this->assertDatabaseMissing('incidents', ['website_id' => $quiet->id]);
    }

    // --- Status ------------------------------------------------------------

    public function test_a_site_under_maintenance_reads_as_maintenance_not_down(): void
    {
        $website = $this->website();
        $website->update(['last_status' => 'down']);
        $this->openWindow($website);

        $this->assertTrue($website->isUnderMaintenance());
        $this->assertSame('maintenance', $website->statusKey(false, false, true));
        $this->assertArrayHasKey('maintenance', Website::STATUSES);
    }

    public function test_the_bulk_lookup_agrees_with_the_per_record_method(): void
    {
        $covered = $this->website();
        $bare = $this->website();
        $this->openWindow($covered);

        $ids = Website::maintenanceIds(Website::all());

        $this->assertArrayHasKey($covered->id, $ids);
        $this->assertArrayNotHasKey($bare->id, $ids);
    }

    public function test_the_bulk_lookup_handles_an_all_sites_window(): void
    {
        $this->website();
        $this->website();

        MaintenanceWindow::create([
            'website_id' => null,
            'reason' => 'Migrasi server',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->assertCount(2, Website::maintenanceIds(Website::all()));
    }

    // --- Managing them -----------------------------------------------------

    public function test_an_operator_can_schedule_a_window_in_app_timezone(): void
    {
        $website = $this->website();

        Livewire::actingAs($this->admin)
            ->test(WebsiteShow::class, ['website' => $website])
            ->call('openMaintenanceForm')
            ->set('maintenanceReason', 'Migrasi server')
            ->set('maintenanceStartsAt', '2026-09-16T02:00')
            ->set('maintenanceEndsAt', '2026-09-16T05:00')
            ->call('saveMaintenance')
            ->assertHasNoErrors();

        $window = MaintenanceWindow::firstOrFail();

        $this->assertSame('Migrasi server', $window->reason);
        // Typed as 02:00 WIB, so it must not be stored as 02:00 UTC - that would
        // put the window seven hours away from the work it is meant to cover.
        $this->assertSame('2026-09-16 02:00', Dates::plain($window->starts_at, 'Y-m-d H:i'));
    }

    public function test_the_end_must_come_after_the_start(): void
    {
        Livewire::actingAs($this->admin)
            ->test(WebsiteShow::class, ['website' => $this->website()])
            ->call('openMaintenanceForm')
            ->set('maintenanceReason', 'Salah urutan')
            ->set('maintenanceStartsAt', '2026-09-16T05:00')
            ->set('maintenanceEndsAt', '2026-09-16T02:00')
            ->call('saveMaintenance')
            ->assertHasErrors('maintenanceEndsAt');

        $this->assertDatabaseCount('maintenance_windows', 0);
    }

    public function test_scheduling_a_window_is_audited(): void
    {
        Livewire::actingAs($this->admin)
            ->test(WebsiteShow::class, ['website' => $this->website()])
            ->call('openMaintenanceForm')
            ->set('maintenanceReason', 'Migrasi server')
            ->set('maintenanceStartsAt', '2026-09-16T02:00')
            ->set('maintenanceEndsAt', '2026-09-16T05:00')
            ->call('saveMaintenance');

        $this->assertDatabaseHas('audit_logs', ['event' => 'pemeliharaan.ditambahkan']);
    }

    public function test_an_operator_can_remove_a_window(): void
    {
        $website = $this->website();
        $window = $this->openWindow($website);

        Livewire::actingAs($this->admin)
            ->test(WebsiteShow::class, ['website' => $website])
            ->call('deleteMaintenance', $window->uuid);

        $this->assertDatabaseCount('maintenance_windows', 0);
    }

    public function test_one_sites_page_cannot_delete_another_sites_window(): void
    {
        $mine = $this->website();
        $theirs = $this->website();
        $window = $this->openWindow($theirs);

        Livewire::actingAs($this->admin)
            ->test(WebsiteShow::class, ['website' => $mine])
            ->call('deleteMaintenance', $window->uuid);

        $this->assertDatabaseCount('maintenance_windows', 1);
    }

    // --- Helpers -----------------------------------------------------------

    /** A reserved-TLD host, so the SSRF guard's DNS lookup is a fast, certain miss. */
    protected function website(): Website
    {
        static $sequence = 0;

        return Website::factory()->create([
            'url' => 'https://situs-'.(++$sequence).'.example',
            'down_threshold_failures' => 1,
        ]);
    }

    protected function openWindow(Website $website): MaintenanceWindow
    {
        return MaintenanceWindow::create([
            'website_id' => $website->id,
            'reason' => 'Migrasi server',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
    }

    protected function runChecksUntilDown(Website $website): void
    {
        $checker = app(WebsiteChecker::class);

        foreach (range(1, $website->down_threshold_failures) as $ignored) {
            $checker->check($website->fresh());
        }
    }
}
