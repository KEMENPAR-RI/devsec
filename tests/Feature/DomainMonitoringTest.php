<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\User;
use App\Models\Website;
use App\Notifications\DnsChangedNotification;
use App\Notifications\DomainExpiringNotification;
use App\Services\Monitoring\DnsMonitor;
use App\Services\Monitoring\DomainExpiry;
use App\Services\Monitoring\WebsiteChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The two checks an HTTP probe can never make.
 *
 * A hijacked domain answers 200 from somebody else's server; an expired one is
 * the most common way an institutional site actually dies, and the only one that
 * is entirely preventable given notice.
 */
class DomainMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
    }

    // --- Comparing answer sets ---------------------------------------------

    public function test_the_diff_ignores_record_order(): void
    {
        $monitor = app(DnsMonitor::class);

        // DNS rotates the order it answers in on purpose; comparing unsorted
        // would report a change on literally every lookup.
        $this->assertSame([], $monitor->diff(
            ['A' => ['1.1.1.1', '2.2.2.2']],
            ['A' => ['1.1.1.1', '2.2.2.2']],
        ));
    }

    public function test_the_diff_reports_what_moved(): void
    {
        $changes = app(DnsMonitor::class)->diff(
            ['A' => ['203.0.113.10'], 'NS' => ['ns1.contoh.example']],
            ['A' => ['198.51.100.7'], 'NS' => ['ns1.contoh.example']],
        );

        $this->assertArrayHasKey('A', $changes);
        $this->assertArrayNotHasKey('NS', $changes);
        $this->assertSame(['203.0.113.10'], $changes['A']['dari']);
    }

    public function test_a_type_that_only_appears_on_one_side_is_not_a_change(): void
    {
        // A transient SERVFAIL on the MX lookup must not read as "every mail
        // record was deleted".
        $this->assertSame([], app(DnsMonitor::class)->diff(
            ['A' => ['203.0.113.10'], 'MX' => ['10 mail.contoh.example']],
            ['A' => ['203.0.113.10']],
        ));
    }

    public function test_a_nameserver_change_outranks_a_mail_change(): void
    {
        $monitor = app(DnsMonitor::class);

        $this->assertSame('critical', $monitor->severityFor(['NS' => []]));
        $this->assertSame('critical', $monitor->severityFor(['A' => []]));
        $this->assertSame('warning', $monitor->severityFor(['MX' => []]));
    }

    // --- DNS incidents -----------------------------------------------------

    public function test_the_first_lookup_records_a_baseline_without_alarming(): void
    {
        Notification::fake();

        $website = $this->website();
        $this->fakeDns(['A' => ['203.0.113.10']]);

        app(WebsiteChecker::class)->checkDns($website);

        $this->assertSame(['A' => ['203.0.113.10']], $website->fresh()->dns_records);
        $this->assertDatabaseCount('incidents', 0);
        Notification::assertNothingSent();
    }

    public function test_a_changed_record_opens_an_incident_and_alerts(): void
    {
        Notification::fake();

        $website = $this->website();
        $website->update(['dns_records' => ['A' => ['203.0.113.10']]]);

        $this->fakeDns(['A' => ['198.51.100.7']]);
        app(WebsiteChecker::class)->checkDns($website);

        $incident = Incident::where('type', 'dns')->firstOrFail();

        $this->assertSame('critical', $incident->severity);
        $this->assertSame(['203.0.113.10'], $incident->meta['changes']['A']['dari']);
        Notification::assertSentTo($this->admin, DnsChangedNotification::class);
    }

    public function test_an_unchanged_record_set_stays_quiet(): void
    {
        Notification::fake();

        $website = $this->website();
        $website->update(['dns_records' => ['A' => ['203.0.113.10']]]);

        $this->fakeDns(['A' => ['203.0.113.10']]);
        app(WebsiteChecker::class)->checkDns($website);

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_an_empty_lookup_never_overwrites_a_good_baseline(): void
    {
        Notification::fake();

        $website = $this->website();
        $website->update(['dns_records' => ['A' => ['203.0.113.10']]]);

        // The resolver having a bad moment must not be recorded as "the zone is
        // now empty", which would then read as a change on the next run.
        $this->fakeDns([]);
        app(WebsiteChecker::class)->checkDns($website);

        $this->assertSame(['A' => ['203.0.113.10']], $website->fresh()->dns_records);
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_dns_is_only_looked_up_once_a_day(): void
    {
        $website = $this->website();
        $website->update(['dns_records' => ['A' => ['203.0.113.10']], 'dns_checked_at' => now()->subHour()]);

        $this->fakeDns(['A' => ['198.51.100.7']]);
        app(WebsiteChecker::class)->checkDns($website);

        $this->assertSame(['A' => ['203.0.113.10']], $website->fresh()->dns_records);
    }

    // --- Domain expiry -----------------------------------------------------

    public function test_a_domain_expiring_soon_opens_an_incident(): void
    {
        Notification::fake();
        Http::fake(['rdap.org/*' => Http::response([], 404)]);

        $website = $this->website();
        $website->update(['domain_expires_at' => now()->addDays(20)]);

        app(WebsiteChecker::class)->checkDomainExpiry($website);

        $incident = Incident::where('type', 'domain')->firstOrFail();

        $this->assertSame('warning', $incident->severity);
        Notification::assertSentTo($this->admin, DomainExpiringNotification::class);
    }

    public function test_a_domain_that_already_lapsed_is_critical(): void
    {
        Notification::fake();
        Http::fake(['rdap.org/*' => Http::response([], 404)]);

        $website = $this->website();
        $website->update(['domain_expires_at' => now()->subDay()]);

        app(WebsiteChecker::class)->checkDomainExpiry($website);

        $this->assertSame('critical', Incident::where('type', 'domain')->firstOrFail()->severity);
    }

    public function test_a_domain_with_plenty_of_time_left_stays_quiet(): void
    {
        Notification::fake();
        Http::fake(['rdap.org/*' => Http::response([], 404)]);

        $website = $this->website();
        $website->update(['domain_expires_at' => now()->addYear()]);

        app(WebsiteChecker::class)->checkDomainExpiry($website);

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_rdap_fills_in_the_date_when_the_registry_publishes_one(): void
    {
        Notification::fake();
        Http::fake(['rdap.org/*' => Http::response([
            'events' => [
                ['eventAction' => 'registration', 'eventDate' => '2020-03-01T00:00:00Z'],
                ['eventAction' => 'expiration', 'eventDate' => '2027-03-01T00:00:00Z'],
            ],
        ], 200)]);

        $website = $this->website();

        app(WebsiteChecker::class)->checkDomainExpiry($website);

        $this->assertSame('2027-03-01', $website->fresh()->domain_expires_at->format('Y-m-d'));
    }

    public function test_a_registry_with_no_rdap_never_clears_a_manual_date(): void
    {
        Notification::fake();
        // .id publishes no RDAP, so this is the normal case for the sites this
        // app actually watches - and wiping a known-good date here would be worse
        // than not looking at all.
        Http::fake(['rdap.org/*' => Http::response([], 404)]);

        $website = $this->website();
        $website->update(['domain_expires_at' => now()->addYear()->startOfDay()]);
        $expected = $website->fresh()->domain_expires_at->format('Y-m-d');

        app(WebsiteChecker::class)->checkDomainExpiry($website);

        $this->assertSame($expected, $website->fresh()->domain_expires_at->format('Y-m-d'));
    }

    public function test_one_warning_per_domain_not_one_a_day(): void
    {
        Notification::fake();
        Http::fake(['rdap.org/*' => Http::response([], 404)]);

        $website = $this->website();
        $website->update(['domain_expires_at' => now()->addDays(20)]);

        $checker = app(WebsiteChecker::class);
        $checker->checkDomainExpiry($website);
        $website->update(['domain_checked_at' => null]);
        $checker->checkDomainExpiry($website->fresh());

        $this->assertSame(1, Incident::where('type', 'domain')->count());
    }

    // --- Registrable domain ------------------------------------------------

    public function test_an_indonesian_second_level_suffix_keeps_three_labels(): void
    {
        $expiry = app(DomainExpiry::class);

        // Asking a registry about "go.id" gets a flat refusal - the registrable
        // name is the three-label one.
        $this->assertSame('kemenparekraf.go.id', $expiry->registrableDomain('https://www.kemenparekraf.go.id/berita'));
        $this->assertSame('contoh.co.id', $expiry->registrableDomain('https://contoh.co.id'));
    }

    public function test_an_ordinary_domain_keeps_two_labels(): void
    {
        $expiry = app(DomainExpiry::class);

        $this->assertSame('contoh.com', $expiry->registrableDomain('https://www.contoh.com/halaman'));
        $this->assertNull($expiry->registrableDomain('https://203.0.113.10/'));
    }

    // --- The scheduled command ---------------------------------------------

    public function test_the_command_skips_sites_under_maintenance(): void
    {
        Notification::fake();
        Http::fake(['rdap.org/*' => Http::response([], 404)]);

        $website = $this->website();
        $website->update(['domain_expires_at' => now()->addDays(20)]);

        MaintenanceWindow::create([
            'website_id' => $website->id,
            'reason' => 'Migrasi server',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->artisan('websites:check-domains')->assertSuccessful();

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_the_command_skips_inactive_sites(): void
    {
        Notification::fake();
        Http::fake(['rdap.org/*' => Http::response([], 404)]);

        $website = $this->website();
        $website->update(['is_active' => false, 'domain_expires_at' => now()->addDays(20)]);

        $this->artisan('websites:check-domains')->assertSuccessful();

        $this->assertDatabaseCount('incidents', 0);
    }

    // --- Helpers -----------------------------------------------------------

    protected function website(): Website
    {
        static $sequence = 0;

        return Website::factory()->create(['url' => 'https://domain-'.(++$sequence).'.example']);
    }

    /**
     * Swaps the resolver for a fixed answer - a test must not depend on what the
     * machine's DNS happens to say today.
     *
     * @param  array<string, array<int, string>>  $records
     */
    protected function fakeDns(array $records): void
    {
        $this->swap(DnsMonitor::class, new class($records) extends DnsMonitor
        {
            public function __construct(protected array $stub) {}

            public function lookUp(string $host): array
            {
                return $this->stub;
            }
        });
    }
}
