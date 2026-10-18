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
use App\Services\Security\OutboundUrlGuard;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
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

    /** DnsMonitor::lookUp() calls made through fakeDns(). */
    protected int $lookups = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lookups = 0;
        $this->admin = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    // --- CNAME, apex and legacy baselines ----------------------------------

    public function test_a_cname_change_is_critical_even_on_a_rotating_site(): void
    {
        $monitor = app(DnsMonitor::class);

        $this->assertSame('critical', $monitor->severityFor(['CNAME' => []]));
        $this->assertSame('critical', $monitor->severityFor(['CNAME' => []], true));
        $this->assertSame('critical', $monitor->severityFor(['NS' => []], true));
        $this->assertSame('warning', $monitor->severityFor(['A' => [], 'AAAA' => []], true));
        $this->assertSame('warning', $monitor->severityFor(['MX' => []], true));
    }

    public function test_a_repointed_cname_opens_a_critical_incident(): void
    {
        Notification::fake();
        $website = $this->website();
        $website->update(['dns_records' => ['CNAME' => ['site.cdn-a.example'], 'apex' => [$this->host($website)]]]);

        $this->fakeDns(['CNAME' => ['site.cdn-b.example'], 'apex' => [$this->host($website)]]);
        app(WebsiteChecker::class)->checkDns($website);

        $incident = Incident::where('type', 'dns')->sole();
        $this->assertSame('critical', $incident->severity);
        $this->assertSame(['site.cdn-b.example'], $incident->meta['changes']['CNAME']['ke']);
    }

    public function test_a_rotating_site_gets_a_warning_for_an_address_change(): void
    {
        Notification::fake();
        $website = $this->website();
        $website->update(['dns_ip_rotates' => true, 'dns_records' => ['A' => ['203.0.113.10']]]);

        $this->fakeDns(['A' => ['198.51.100.7']]);
        app(WebsiteChecker::class)->checkDns($website);

        $this->assertSame('warning', Incident::where('type', 'dns')->sole()->severity);
    }

    public function test_ns_mx_from_a_different_apex_are_not_compared(): void
    {
        Notification::fake();
        $website = Website::factory()->create(['url' => 'https://www.apex-shift.example']);
        // Legacy baseline: NS/MX read off www, no CNAME, no apex key.
        $website->update(['dns_records' => ['A' => ['203.0.113.10'], 'NS' => ['ns.www-host.example'], 'MX' => ['10 old.example']]]);

        $this->fakeDns(['A' => ['203.0.113.10'], 'CNAME' => ['www.cdn.example'], 'NS' => ['ns1.apex.example'], 'MX' => ['10 mail.apex.example'], 'apex' => ['apex-shift.example']]);
        app(WebsiteChecker::class)->checkDns($website);

        $this->assertDatabaseCount('incidents', 0);
        $this->assertSame(['apex-shift.example'], $website->fresh()->dns_records['apex'], 'the new baseline is stored');
    }

    public function test_a_legacy_baseline_on_the_apex_itself_still_compares_ns(): void
    {
        Notification::fake();
        $website = Website::factory()->create(['url' => 'https://apex-same.example']);
        $website->update(['dns_records' => ['A' => ['203.0.113.10'], 'NS' => ['ns1.old.example']]]);

        $this->fakeDns(['A' => ['203.0.113.10'], 'NS' => ['ns1.hijack.example'], 'apex' => ['apex-same.example']]);
        app(WebsiteChecker::class)->checkDns($website);

        $incident = Incident::where('type', 'dns')->sole();
        $this->assertSame('critical', $incident->severity);
        $this->assertSame(['ns1.old.example'], $incident->meta['changes']['NS']['dari']);
    }

    // --- Changes while an incident is open ---------------------------------

    public function test_a_later_change_is_appended_to_the_open_incident(): void
    {
        Notification::fake();
        $website = $this->website();
        $website->update(['dns_records' => ['A' => ['203.0.113.10'], 'MX' => ['10 mail.example']]]);
        $checker = app(WebsiteChecker::class);

        $this->fakeDns(['A' => ['198.51.100.7'], 'MX' => ['10 mail.example']]);
        $checker->checkDns($website);
        $this->fakeDns(['A' => ['198.51.100.8'], 'MX' => ['20 evil.example']]);
        $checker->checkDns($website->fresh(), force: true);

        $incident = Incident::where('type', 'dns')->sole();
        $this->assertSame(['203.0.113.10'], $incident->meta['changes']['A']['dari'], 'cumulative: original');
        $this->assertSame(['198.51.100.8'], $incident->meta['changes']['A']['ke'], 'cumulative: latest');
        $this->assertSame(['10 mail.example'], $incident->meta['changes']['MX']['dari']);
        $this->assertCount(1, $incident->meta['history']);
        $this->assertSame(['198.51.100.7'], $incident->meta['history'][0]['changes']['A']['dari']);
        Notification::assertSentToTimes($this->admin, DnsChangedNotification::class, 1);
    }

    public function test_a_warning_that_turns_critical_re_alerts_once(): void
    {
        Notification::fake();
        $website = $this->website();
        $website->update(['dns_records' => ['NS' => ['ns1.ok.example'], 'MX' => ['10 mail.example']]]);
        $checker = app(WebsiteChecker::class);

        $this->fakeDns(['NS' => ['ns1.ok.example'], 'MX' => ['10 other.example']]);
        $checker->checkDns($website);
        $this->assertSame('warning', Incident::where('type', 'dns')->sole()->severity);

        $this->fakeDns(['NS' => ['ns1.evil.example'], 'MX' => ['10 other.example']]);
        $checker->checkDns($website->fresh(), force: true);
        $incident = Incident::where('type', 'dns')->sole();
        $this->assertSame('critical', $incident->severity);
        $this->assertTrue($incident->meta['escalated']);

        $this->fakeDns(['NS' => ['ns2.evil.example'], 'MX' => ['10 other.example']]);
        $checker->checkDns($website->fresh(), force: true);

        Notification::assertSentToTimes($this->admin, DnsChangedNotification::class, 2);
    }

    public function test_the_history_keeps_the_last_twenty_changes(): void
    {
        Notification::fake();
        $website = $this->website();
        $website->update(['dns_records' => ['A' => ['203.0.113.1']]]);
        $checker = app(WebsiteChecker::class);

        foreach (range(2, 26) as $i) {
            $this->fakeDns(['A' => ["203.0.113.{$i}"]]);
            $checker->checkDns($website->fresh(), force: true);
        }

        $meta = Incident::where('type', 'dns')->sole()->meta;
        $this->assertCount(20, $meta['history']);
        $this->assertSame(['203.0.113.26'], end($meta['history'])['changes']['A']['ke']);
        $this->assertSame(['203.0.113.1'], $meta['changes']['A']['dari']);
    }

    // --- IP drift between daily checks -------------------------------------

    public function test_drifted_addresses_pull_the_dns_check_forward_once_an_hour(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        OutboundUrlGuard::fakeDns(['drift.example' => ['93.184.216.99']]);
        $website = Website::factory()->create(['url' => 'http://drift.example']);
        $website->update(['dns_records' => ['A' => ['93.184.216.34']], 'dns_checked_at' => now()->subHours(2)]);
        $this->fakeDns(['A' => ['93.184.216.99']]);
        $checker = app(WebsiteChecker::class);

        $checker->check($website);

        $this->assertSame(1, $this->lookups);
        $this->assertNotNull($website->fresh()->dns_ip_drift_at);
        $this->assertSame('critical', Incident::where('type', 'dns')->sole()->severity);

        // Baseline drifts again within the hour: throttled.
        $website->update(['dns_records' => ['A' => ['93.184.216.34']]]);
        $checker->check($website->fresh());
        $this->assertSame(1, $this->lookups);

        Carbon::setTestNow(now()->addMinutes(61));
        $checker->check($website->fresh());
        $this->assertSame(2, $this->lookups);
    }

    public function test_matching_addresses_cost_no_lookup(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        OutboundUrlGuard::fakeDns(['same.example' => ['93.184.216.34']]);
        $website = Website::factory()->create(['url' => 'http://same.example']);
        $website->update(['dns_records' => ['A' => ['93.184.216.34']], 'dns_checked_at' => now()->subHours(2)]);
        $this->fakeDns(['A' => ['93.184.216.34']]);

        app(WebsiteChecker::class)->check($website);

        $this->assertSame(0, $this->lookups);
        $this->assertNull($website->fresh()->dns_ip_drift_at);
    }

    public function test_a_rotating_site_never_triggers_a_drift_check(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        OutboundUrlGuard::fakeDns(['cdn.example' => ['93.184.216.99']]);
        $website = Website::factory()->create(['url' => 'http://cdn.example']);
        $website->update(['dns_ip_rotates' => true, 'dns_records' => ['A' => ['93.184.216.34']], 'dns_checked_at' => now()->subHours(2)]);
        $this->fakeDns(['A' => ['93.184.216.99']]);

        app(WebsiteChecker::class)->check($website);

        $this->assertSame(0, $this->lookups);
    }

    public function test_a_dns_failure_is_no_drift_evidence(): void
    {
        Notification::fake();
        Sleep::fake();
        OutboundUrlGuard::fakeDns([]);
        $website = Website::factory()->create(['url' => 'http://gone.example']);
        $website->update(['dns_records' => ['A' => ['93.184.216.34']], 'dns_checked_at' => now()->subHours(2)]);
        $this->fakeDns(['A' => ['93.184.216.99']]);

        $check = app(WebsiteChecker::class)->check($website);

        $this->assertSame('dns', $check->error_type);
        $this->assertSame(0, $this->lookups);
    }

    public function test_more_addresses_than_the_stored_cap_is_not_drift(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $addresses = array_map(fn (int $i) => "93.184.216.{$i}", range(1, DnsMonitor::MAX_VALUES + 5));
        OutboundUrlGuard::fakeDns(['many.example' => $addresses]);
        $website = Website::factory()->create(['url' => 'http://many.example']);
        // The baseline as DnsMonitor stores it: sorted and capped.
        $baseline = app(DnsMonitor::class)->normalize('A', $addresses);
        $website->update(['dns_records' => ['A' => $baseline], 'dns_checked_at' => now()->subHours(2)]);
        $this->fakeDns(['A' => $baseline]);

        app(WebsiteChecker::class)->check($website);

        $this->assertCount(DnsMonitor::MAX_VALUES, $baseline);
        $this->assertSame(0, $this->lookups);
        $this->assertNull($website->fresh()->dns_ip_drift_at);
    }

    // --- Domain expiry: thresholds, escalation, source ----------------------

    public function test_the_domain_critical_threshold_comes_from_config(): void
    {
        Notification::fake();
        Http::fake(['rdap.org/*' => Http::response([], 404)]);
        Carbon::setTestNow('2026-10-18 10:00:00');

        $three = $this->website();
        $three->update(['domain_expires_at' => now()->addDays(3)]);
        app(WebsiteChecker::class)->checkDomainExpiry($three);

        $four = $this->website();
        $four->update(['domain_expires_at' => now()->addDays(4)]);
        app(WebsiteChecker::class)->checkDomainExpiry($four);

        $this->assertSame('critical', Incident::where('website_id', $three->id)->sole()->severity);
        $this->assertSame('warning', Incident::where('website_id', $four->id)->sole()->severity);
    }

    public function test_a_domain_warning_escalates_once_then_turns_expired_quietly(): void
    {
        Notification::fake();
        Http::fake(['rdap.org/*' => Http::response([], 404)]);
        Carbon::setTestNow('2026-10-01 10:00:00');
        $website = $this->website();
        $website->update(['domain_expires_at' => '2026-10-21']);

        app(WebsiteChecker::class)->checkDomainExpiry($website);
        $this->assertSame('warning', Incident::where('type', 'domain')->sole()->severity);

        Carbon::setTestNow('2026-10-19 10:00:00');
        app(WebsiteChecker::class)->checkDomainExpiry($website->fresh());
        $incident = Incident::where('type', 'domain')->sole();
        $this->assertSame('critical', $incident->severity);
        $this->assertTrue($incident->meta['escalated']);

        Carbon::setTestNow('2026-10-20 10:00:00');
        app(WebsiteChecker::class)->checkDomainExpiry($website->fresh());

        Carbon::setTestNow('2026-10-23 10:00:00');
        app(WebsiteChecker::class)->checkDomainExpiry($website->fresh());
        $this->assertStringContainsString('sudah kedaluwarsa', Incident::where('type', 'domain')->sole()->title);

        Notification::assertSentToTimes($this->admin, DomainExpiringNotification::class, 2);
    }

    public function test_a_renewed_domain_resolves_its_incident(): void
    {
        Notification::fake();
        Http::fake(['rdap.org/*' => Http::response([], 404)]);
        $website = $this->website();
        $website->update(['domain_expires_at' => now()->addDays(20)]);
        app(WebsiteChecker::class)->checkDomainExpiry($website);

        $website->update(['domain_expires_at' => now()->addYear(), 'domain_checked_at' => null]);
        app(WebsiteChecker::class)->checkDomainExpiry($website->fresh());

        $this->assertSame(0, Incident::where('type', 'domain')->open()->count());
    }

    public function test_an_rdap_date_is_marked_as_such(): void
    {
        Notification::fake();
        Http::fake(['rdap.org/*' => Http::response(['events' => [['eventAction' => 'expiration', 'eventDate' => '2028-01-01T00:00:00Z']]], 200)]);
        $website = $this->website();
        $website->update(['domain_expiry_source' => 'manual', 'domain_expires_at' => '2027-01-01']);

        app(WebsiteChecker::class)->checkDomainExpiry($website);

        $this->assertSame('rdap', $website->fresh()->domain_expiry_source);
    }

    public function test_an_empty_rdap_answer_keeps_a_manual_source(): void
    {
        Notification::fake();
        Http::fake(['rdap.org/*' => Http::response([], 404)]);
        $website = $this->website();
        $website->update(['domain_expiry_source' => 'manual', 'domain_expires_at' => now()->addYear()]);

        app(WebsiteChecker::class)->checkDomainExpiry($website);

        $this->assertSame('manual', $website->fresh()->domain_expiry_source);
    }

    public function test_a_malformed_domain_never_reaches_rdap(): void
    {
        Http::fake();
        $expiry = app(DomainExpiry::class);

        $this->assertNull($expiry->lookUp('https://a.exa_mple.com/'));
        $this->assertNull($expiry->lookUp('evil.com/../admin?x=1'));
        $this->assertNull($expiry->lookUp('https://93.184.216.34/'));
        Http::assertNothingSent();
    }

    public function test_sites_sharing_an_apex_cost_one_rdap_call(): void
    {
        Notification::fake();
        Http::fake(['rdap.org/*' => Http::response([], 404)]);
        $checker = app(WebsiteChecker::class);

        foreach (['https://shared.example', 'https://www.shared.example', 'https://portal.shared.example'] as $url) {
            $checker->checkDomainExpiry(Website::factory()->create(['url' => $url]));
        }

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://rdap.org/domain/shared.example');
    }

    public function test_an_rdap_body_is_capped(): void
    {
        $expiry = new class extends DomainExpiry
        {
            public function handler(callable $inner): callable
            {
                return $this->cappedBodyHandler($inner);
            }
        };
        $handler = $expiry->handler(function ($request, array $options) {
            foreach (range(1, 3) as $ignored) {
                $options['sink']->write(str_repeat('x', DomainExpiry::MAX_BODY_BYTES));
            }

            $options['sink']->rewind();

            return Create::promiseFor(new Response(200, [], $options['sink']));
        });

        $response = $handler(new Request('GET', 'https://rdap.org/domain/big.example'), [])->wait();

        $this->assertSame(DomainExpiry::MAX_BODY_BYTES, strlen((string) $response->getBody()));
    }

    public function test_a_truncated_rdap_body_yields_no_date(): void
    {
        $json = json_encode(['events' => [['eventAction' => 'expiration', 'eventDate' => '2028-01-01T00:00:00Z']], 'pad' => str_repeat('x', 100)]);
        Http::fake(['rdap.org/*' => Http::response(substr($json, 0, -20), 200, ['Content-Type' => 'application/json'])]);

        $this->assertNull(app(DomainExpiry::class)->lookUp('https://truncated.example'));
    }

    // --- Helpers -----------------------------------------------------------

    protected function website(): Website
    {
        static $sequence = 0;

        return Website::factory()->create(['url' => 'https://domain-'.(++$sequence).'.example']);
    }

    protected function host(Website $website): string
    {
        return parse_url($website->url, PHP_URL_HOST);
    }

    /**
     * Swaps the resolver for a fixed answer - a test must not depend on what the
     * machine's DNS happens to say today.
     *
     * @param  array<string, array<int, string>>  $records
     */
    protected function fakeDns(array $records): void
    {
        $this->swap(DnsMonitor::class, new class($records, fn () => $this->lookups++) extends DnsMonitor
        {
            public function __construct(protected array $stub, protected ?\Closure $counter = null) {}

            public function lookUp(string $host): array
            {
                $this->counter && ($this->counter)();

                return $this->stub;
            }
        });
    }
}
