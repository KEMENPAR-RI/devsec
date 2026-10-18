<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use App\Notifications\SslExpiringNotification;
use App\Services\Monitoring\CertificateInspector;
use App\Services\Monitoring\WebsiteChecker;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * The certificate is read straight off a pinned handshake, and every problem it
 * carries (expiry, wrong name, untrusted chain) is its own incident that closes
 * on its own. No socket is opened here: handshake() and inspectCertificate() are faked.
 */
class CertificateInspectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    /** @var list<array{ip: string, port: int, host: string, ssl: array}> */
    protected array $handshakes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->handshakes = [];
        $this->admin = User::factory()->create();
        // Any readable file stands in for a CA bundle; the verifying handshake is faked anyway.
        config(['monitoring.ssl.cafile' => __FILE__]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --- Inspector: what it connects to --------------------------------------

    public function test_nothing_is_inspected_without_https_a_vetted_address_or_a_public_target(): void
    {
        OutboundUrlGuard::fakeDns(['plain.test' => ['93.184.216.34'], 'internal.test' => ['10.0.0.7']]);
        $inspector = $this->inspector();

        $this->assertNull($inspector->inspect('http://plain.test/'));
        $this->assertNull($inspector->inspect('https://internal.test/'));
        $this->assertNull($inspector->inspect('https://127.0.0.1/'));
        $this->assertNull($inspector->inspect('https://unresolved.test/'));
        $this->assertNull($inspector->tlsVersion('https://internal.test/'));
        $this->assertSame([], $this->handshakes, 'no socket for anything the guard did not vet');
    }

    public function test_the_handshake_goes_to_the_vetted_ipv4_with_sni_on_the_host(): void
    {
        OutboundUrlGuard::fakeDns(['dual.test' => ['2606:2800:220:1::1', '93.184.216.34']]);

        $this->inspector()->inspect('https://Dual.test:8443/path');

        $this->assertNotEmpty($this->handshakes);
        foreach ($this->handshakes as $call) {
            $this->assertSame('93.184.216.34', $call['ip']);
            $this->assertSame(8443, $call['port']);
            $this->assertSame('dual.test', $call['host']);
        }
        $this->assertFalse($this->handshakes[0]['ssl']['verify_peer'], 'first read is unverified');
        $this->assertTrue($this->handshakes[0]['ssl']['capture_peer_cert']);
    }

    public function test_an_ipv6_only_host_uses_its_vetted_ipv6_on_the_default_port(): void
    {
        OutboundUrlGuard::fakeDns(['v6.test' => ['2606:2800:220:1::1']]);

        $this->inspector()->inspect('https://v6.test/');

        $this->assertSame('2606:2800:220:1::1', $this->handshakes[0]['ip']);
        $this->assertSame(443, $this->handshakes[0]['port']);
    }

    public function test_tls_version_is_read_from_one_pinned_unverified_handshake(): void
    {
        OutboundUrlGuard::fakeDns(['tls.test' => ['93.184.216.34']]);

        $this->assertSame('TLSv1.3', $this->inspector()->tlsVersion('https://tls.test/'));
        $this->assertCount(1, $this->handshakes);
        $this->assertSame('93.184.216.34', $this->handshakes[0]['ip']);
        $this->assertFalse($this->handshakes[0]['ssl']['verify_peer']);
    }

    // --- Inspector: what it reports ------------------------------------------

    public function test_a_healthy_certificate_is_fully_described_in_the_app_timezone(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        OutboundUrlGuard::fakeDns(['ok.test' => ['93.184.216.34']]);
        $validTo = now()->addDays(60)->getTimestamp();

        $result = $this->inspector(cert: $this->cert(['validTo_time_t' => $validTo]))->inspect('https://ok.test/');

        $this->assertTrue($result['reachable']);
        $this->assertSame('Asia/Jakarta', $result['expires_at']->getTimezone()->getName());
        $this->assertSame($validTo, $result['expires_at']->getTimestamp());
        $this->assertSame("R3 (Let's Encrypt)", $result['issuer']);
        $this->assertSame(['ok.test', 'www.ok.test', '*.ok.test'], $result['names']);
        $this->assertTrue($result['chain_valid']);
        $this->assertTrue($result['hostname_match']);
        $this->assertSame('TLSv1.3', $result['protocol']);
        $this->assertNull($result['error']);
        $this->assertCount(2, $this->handshakes);
        $this->assertTrue($this->handshakes[1]['ssl']['verify_peer'], 'second handshake verifies the chain');
        $this->assertFalse($this->handshakes[1]['ssl']['verify_peer_name'], 'the name is judged locally');
        $this->assertSame(__FILE__, $this->handshakes[1]['ssl']['cafile']);
    }

    public function test_a_failed_verification_marks_the_chain_invalid(): void
    {
        OutboundUrlGuard::fakeDns(['chain.test' => ['93.184.216.34']]);

        $result = $this->inspector(verifyError: 'SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed')
            ->inspect('https://chain.test/');

        $this->assertFalse($result['chain_valid']);
        $this->assertStringContainsString('certificate verify failed', $result['error']);
        $this->assertTrue($result['reachable']);
    }

    public function test_a_verifying_handshake_that_fails_for_another_reason_is_unknown_not_invalid(): void
    {
        OutboundUrlGuard::fakeDns(['flaky.test' => ['93.184.216.34']]);

        $result = $this->inspector(verifyError: 'Connection timed out')->inspect('https://flaky.test/');

        $this->assertNull($result['chain_valid']);
        $this->assertNull($result['error']);
    }

    public function test_an_expired_certificate_does_not_judge_the_chain(): void
    {
        OutboundUrlGuard::fakeDns(['old.test' => ['93.184.216.34']]);

        $result = $this->inspector(cert: $this->cert(['validTo_time_t' => time() - 86400]))->inspect('https://old.test/');

        $this->assertNull($result['chain_valid'], 'verification fails on the date, which says nothing about the chain');
        $this->assertTrue($result['expires_at']->isPast());
        $this->assertCount(1, $this->handshakes);
    }

    public function test_a_not_yet_valid_certificate_does_not_judge_the_chain(): void
    {
        OutboundUrlGuard::fakeDns(['future.test' => ['93.184.216.34']]);

        $result = $this->inspector(cert: $this->cert(['validFrom_time_t' => time() + 86400]))->inspect('https://future.test/');

        $this->assertNull($result['chain_valid']);
        $this->assertCount(1, $this->handshakes);
    }

    public function test_without_any_ca_bundle_the_chain_is_unknown(): void
    {
        $capath = function_exists('openssl_get_cert_locations') ? (openssl_get_cert_locations()['default_cert_dir'] ?? null) : null;

        if ($capath && is_dir($capath) && is_readable($capath)) {
            $this->markTestSkipped("OpenSSL's default CA dir ({$capath}) exists; the no-trust branch cannot be reached here.");
        }

        config(['monitoring.ssl.cafile' => '/nonexistent/ca.pem']);
        OutboundUrlGuard::fakeDns(['nocafile.test' => ['93.184.216.34']]);

        $result = $this->inspector(verifyError: 'certificate verify failed')->inspect('https://nocafile.test/');

        $this->assertNull($result['chain_valid'], 'without CA certificates every chain would "fail"');
        $this->assertCount(1, $this->handshakes, 'no verifying handshake at all');
    }

    public function test_an_unreadable_cafile_falls_back_to_the_default_ca_dir(): void
    {
        $capath = function_exists('openssl_get_cert_locations') ? (openssl_get_cert_locations()['default_cert_dir'] ?? null) : null;

        if (! $capath || ! is_dir($capath) || ! is_readable($capath)) {
            $this->markTestSkipped('No default CA dir on this machine.');
        }

        config(['monitoring.ssl.cafile' => '/nonexistent/ca.pem']);
        OutboundUrlGuard::fakeDns(['capath.test' => ['93.184.216.34']]);

        $this->inspector()->inspect('https://capath.test/');

        $this->assertSame($capath, $this->handshakes[1]['ssl']['capath']);
        $this->assertArrayNotHasKey('cafile', $this->handshakes[1]['ssl']);
    }

    public function test_no_certificate_means_unreachable_with_the_reason(): void
    {
        OutboundUrlGuard::fakeDns(['down.test' => ['93.184.216.34']]);

        $result = $this->inspector(firstReply: ['connected' => false, 'cert' => null, 'protocol' => null, 'error' => 'Connection refused'])
            ->inspect('https://down.test/');

        $this->assertFalse($result['reachable']);
        $this->assertNull($result['expires_at']);
        $this->assertSame([], $result['names']);
        $this->assertNull($result['chain_valid']);
        $this->assertNull($result['hostname_match']);
        $this->assertSame('Connection refused', $result['error']);
    }

    public function test_a_certificate_for_another_name_does_not_match_the_host(): void
    {
        OutboundUrlGuard::fakeDns(['victim.test' => ['93.184.216.34']]);

        $result = $this->inspector(cert: $this->cert(['extensions' => ['subjectAltName' => 'DNS:other.test']]))->inspect('https://victim.test/');

        $this->assertFalse($result['hostname_match']);
        $this->assertSame(['other.test'], $result['names']);
    }

    public function test_a_certificate_without_san_never_matches(): void
    {
        OutboundUrlGuard::fakeDns(['nosan.test' => ['93.184.216.34']]);

        // CN alone is ignored by browsers (RFC 6125 / CA/B Forum).
        $result = $this->inspector(cert: $this->cert(['subject' => ['CN' => 'nosan.test'], 'extensions' => []]))->inspect('https://nosan.test/');

        $this->assertSame([], $result['names']);
        $this->assertFalse($result['hostname_match']);
    }

    public function test_the_san_list_is_capped(): void
    {
        OutboundUrlGuard::fakeDns(['many.test' => ['93.184.216.34']]);
        $san = implode(', ', array_map(fn (int $i) => "DNS:h{$i}.many.test", range(1, 500)));

        $result = $this->inspector(cert: $this->cert(['extensions' => ['subjectAltName' => $san]]))->inspect('https://many.test/');

        $this->assertCount(100, $result['names']);
    }

    public function test_only_hostname_and_ip_san_entries_are_kept(): void
    {
        OutboundUrlGuard::fakeDns(['san.test' => ['93.184.216.34']]);
        $long = implode('.', array_map(fn (string $c) => str_repeat($c, 60), ['a', 'b', 'c', 'd', 'e'])).'.test';
        $san = implode(', ', [
            'DNS:san.test', 'DNS:*.san.test', 'IP Address:93.184.216.34', 'IP Address:2606:2800:220:1:0:0:0:1',
            'DNS:<script>alert(1)</script>.test', 'DNS:a b.test', 'DNS:foo.*.test', 'DNS:'.$long,
            'DNS:evil.test/path', 'URI:https://san.test', 'email:a@san.test', 'DNS:',
        ]);

        $names = $this->inspector(cert: $this->cert(['extensions' => ['subjectAltName' => $san]]))->inspect('https://san.test/')['names'];

        $this->assertGreaterThan(253, strlen($long));
        $this->assertSame(['san.test', '*.san.test', '93.184.216.34', '2606:2800:220:1:0:0:0:1'], $names);
    }

    public function test_hostname_matching_follows_rfc_6125(): void
    {
        $inspector = app(CertificateInspector::class);

        $this->assertTrue($inspector->matchesHost('www.example.com', ['*.example.com']));
        $this->assertTrue($inspector->matchesHost('WWW.Example.com.', ['www.example.com']));
        $this->assertTrue($inspector->matchesHost('example.com', ['EXAMPLE.COM']));
        $this->assertFalse($inspector->matchesHost('a.b.example.com', ['*.example.com']), 'one label only');
        $this->assertFalse($inspector->matchesHost('example.com', ['*.example.com']), 'the apex is not covered');
        $this->assertFalse($inspector->matchesHost('example.com', ['*.com']), 'no public-suffix wildcard');
        $this->assertFalse($inspector->matchesHost('evilexample.com', ['*example.com']));
        $this->assertTrue($inspector->matchesHost('93.184.216.34', ['93.184.216.34']));
        $this->assertTrue($inspector->matchesHost('[2606:2800:220:1::1]', ['2606:2800:0220:0001:0:0:0:1']));
        $this->assertFalse($inspector->matchesHost('93.184.216.34', ['*.184.216.34']), 'never a wildcard for an IP');
        $this->assertFalse($inspector->matchesHost('93.184.216.34', ['example.com']));
        $this->assertFalse($inspector->matchesHost('example.com', []));
    }

    // --- Checker: severities ------------------------------------------------

    public function test_fourteen_days_out_is_a_warning_and_fifteen_is_quiet(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-10-18 10:00:00');

        $quiet = $this->website();
        $this->checker(['expires_at' => now()->addDays(15)])->ssl($quiet);
        $this->assertSame(0, Incident::where('website_id', $quiet->id)->count());

        $warned = $this->website();
        $this->checker(['expires_at' => now()->addDays(14)])->ssl($warned);
        $this->assertSame('warning', $this->sslIncident($warned)->severity);
    }

    public function test_three_days_out_is_critical_and_four_is_a_warning(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-10-18 10:00:00');

        $four = $this->website();
        $this->checker(['expires_at' => now()->addDays(4)])->ssl($four);
        $this->assertSame('warning', $this->sslIncident($four)->severity);

        $three = $this->website();
        $this->checker(['expires_at' => now()->addDays(3)])->ssl($three);
        $incident = $this->sslIncident($three);
        $this->assertSame('critical', $incident->severity);
        $this->assertSame('expiring', $incident->meta['reason']);
    }

    public function test_the_thresholds_come_from_config(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-10-18 10:00:00');
        config(['monitoring.ssl.warning_days' => 30, 'monitoring.ssl.critical_days' => 7]);

        $website = $this->website();
        $this->checker(['expires_at' => now()->addDays(7)])->ssl($website);

        $this->assertSame('critical', $this->sslIncident($website)->severity);
    }

    public function test_an_expired_certificate_is_critical_with_its_own_title(): void
    {
        Notification::fake();

        $website = $this->website();
        $this->checker(['expires_at' => now()->subDay(), 'chain_valid' => null])->ssl($website);

        $incident = $this->sslIncident($website);
        $this->assertSame('critical', $incident->severity);
        $this->assertSame('expired', $incident->meta['reason']);
        $this->assertStringContainsString('sudah kedaluwarsa', $incident->title);
        Notification::assertSentToTimes($this->admin, SslExpiringNotification::class, 1);
    }

    public function test_days_are_counted_in_the_app_timezone_not_utc(): void
    {
        Notification::fake();
        config(['app.timezone' => 'Asia/Jakarta']);

        // 03:00 WIB on the 19th; expiry 08:00 WIB on the 22nd = 3 calendar days (UTC would say 4).
        Carbon::setTestNow(Carbon::parse('2026-10-18 20:00:00', 'UTC'));
        $website = $this->website();
        $this->checker(['expires_at' => Carbon::parse('2026-10-22 01:00:00', 'UTC')])->ssl($website);

        $incident = $this->sslIncident($website);
        $this->assertSame('critical', $incident->severity);
        $this->assertStringContainsString('22', $incident->description);
        $this->assertStringContainsString('+07:00', $incident->meta['expires_at']);
    }

    public function test_a_late_evening_check_does_not_count_the_next_day_early(): void
    {
        Notification::fake();
        config(['app.timezone' => 'Asia/Jakarta']);

        // 23:30 WIB on the 18th; expiry 00:30 WIB on the 22nd = 4 calendar days (UTC would say 3).
        Carbon::setTestNow(Carbon::parse('2026-10-18 16:30:00', 'UTC'));
        $website = $this->website();
        $this->checker(['expires_at' => Carbon::parse('2026-10-21 17:30:00', 'UTC')])->ssl($website);

        $this->assertSame('warning', $this->sslIncident($website)->severity);
    }

    public function test_a_hostname_mismatch_is_critical_and_names_what_the_certificate_covers(): void
    {
        Notification::fake();

        $website = $this->website('https://portal.example');
        $this->checker(['names' => ['other.example', 'www.other.example'], 'hostname_match' => false])->ssl($website);

        $incident = $this->sslIncident($website);
        $this->assertSame('critical', $incident->severity);
        $this->assertSame('hostname', $incident->meta['reason']);
        $this->assertSame('portal.example', $incident->meta['host']);
        $this->assertFalse($incident->meta['hostname_match']);
        $this->assertStringContainsString('other.example', $incident->description);
    }

    public function test_a_certificate_without_san_says_so(): void
    {
        Notification::fake();

        $website = $this->website();
        $this->checker(['names' => [], 'hostname_match' => false])->ssl($website);

        $this->assertStringContainsString('Subject Alternative Name', $this->sslIncident($website)->description);
    }

    public function test_an_untrusted_chain_is_a_warning(): void
    {
        Notification::fake();

        $website = $this->website();
        $this->checker(['chain_valid' => false, 'error' => 'certificate verify failed'])->ssl($website);

        $incident = $this->sslIncident($website);
        $this->assertSame('warning', $incident->severity);
        $this->assertSame('chain', $incident->meta['reason']);
        $this->assertFalse($incident->meta['chain_valid']);
        $this->assertSame('certificate verify failed', $incident->meta['error']);
    }

    public function test_a_healthy_certificate_raises_nothing_and_stores_the_snapshot(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-10-18 10:00:00');

        $website = $this->website();
        $this->checker(['expires_at' => now()->addDays(80)])->ssl($website);

        $fresh = $website->fresh();
        $this->assertSame(0, Incident::where('website_id', $website->id)->count());
        $this->assertSame(now()->addDays(80)->getTimestamp(), $fresh->ssl_expires_at->getTimestamp());
        $this->assertSame("R3 (Let's Encrypt)", $fresh->ssl_issuer);
        $this->assertSame(['ssl.example'], $fresh->ssl_names);
        $this->assertTrue($fresh->ssl_chain_valid);
        $this->assertTrue($fresh->ssl_hostname_match);
        $this->assertNull($fresh->ssl_error);
        $this->assertNotNull($fresh->ssl_checked_at);
        Notification::assertNothingSent();
    }

    // --- Checker: lifecycle -------------------------------------------------

    public function test_crossing_into_critical_re_alerts_exactly_once(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-10-01 10:00:00');
        $expiresAt = now()->addDays(10);
        $website = $this->website();

        $this->checker(['expires_at' => $expiresAt])->ssl($website);
        $this->assertSame('warning', $this->sslIncident($website)->severity);

        Carbon::setTestNow('2026-10-09 10:00:00');
        $this->checker(['expires_at' => $expiresAt])->ssl($website->fresh());
        $incident = $this->sslIncident($website);
        $this->assertSame('critical', $incident->severity);
        $this->assertTrue($incident->meta['escalated']);

        Carbon::setTestNow('2026-10-10 10:00:00');
        $this->checker(['expires_at' => $expiresAt])->ssl($website->fresh());

        Notification::assertSentToTimes($this->admin, SslExpiringNotification::class, 2);
        $this->assertSame(1, Incident::where('website_id', $website->id)->count());
    }

    public function test_expiring_turning_expired_updates_the_record_without_a_second_email(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-10-01 10:00:00');
        $expiresAt = now()->addDays(2);
        $website = $this->website();

        $this->checker(['expires_at' => $expiresAt])->ssl($website);

        Carbon::setTestNow('2026-10-04 10:00:00');
        $this->checker(['expires_at' => $expiresAt, 'chain_valid' => null])->ssl($website->fresh());

        $incident = $this->sslIncident($website);
        $this->assertSame('expired', $incident->meta['reason']);
        $this->assertStringContainsString('sudah kedaluwarsa', $incident->title);
        $this->assertSame('open', $incident->status);
        Notification::assertSentToTimes($this->admin, SslExpiringNotification::class, 1);
    }

    public function test_each_problem_resolves_on_its_own(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-10-01 10:00:00');
        $expiresAt = now()->addDays(10);
        $website = $this->website();

        $this->checker(['expires_at' => $expiresAt, 'chain_valid' => false, 'hostname_match' => false])->ssl($website);
        $this->assertSame(3, Incident::where('website_id', $website->id)->open()->count());

        // Intermediate installed and name fixed; the renewal still pending.
        Carbon::setTestNow('2026-10-02 10:00:00');
        $this->checker(['expires_at' => $expiresAt])->ssl($website->fresh());

        $open = Incident::where('website_id', $website->id)->open()->get();
        $this->assertCount(1, $open);
        $this->assertSame('expiring', $open->first()->meta['reason']);

        // Renewed.
        Carbon::setTestNow('2026-10-03 10:00:00');
        $this->checker(['expires_at' => now()->addDays(90)])->ssl($website->fresh());

        $this->assertSame(0, Incident::where('website_id', $website->id)->open()->count());
        Notification::assertSentToTimes($this->admin, SslExpiringNotification::class, 3);
    }

    public function test_a_legacy_expiry_incident_without_a_reason_is_resolved_by_a_renewal(): void
    {
        Notification::fake();
        $website = $this->website();
        Incident::create([
            'website_id' => $website->id, 'type' => 'ssl', 'severity' => 'warning', 'title' => 'Sertifikat SSL akan kedaluwarsa',
            'status' => 'open', 'detected_at' => now()->subDays(2),
        ]);

        $this->checker(['expires_at' => now()->addDays(90)])->ssl($website);

        $this->assertSame(0, Incident::where('website_id', $website->id)->open()->count());
    }

    public function test_a_legacy_expiry_incident_is_reused_not_duplicated(): void
    {
        Notification::fake();
        $website = $this->website();
        Incident::create([
            'website_id' => $website->id, 'type' => 'ssl', 'severity' => 'warning', 'title' => 'Sertifikat SSL akan kedaluwarsa',
            'status' => 'open', 'detected_at' => now()->subDays(2),
        ]);

        $this->checker(['expires_at' => now()->addDays(10)])->ssl($website);

        $this->assertSame(1, Incident::where('website_id', $website->id)->count());
        Notification::assertNothingSent();
    }

    public function test_an_unreachable_handshake_keeps_the_last_known_certificate(): void
    {
        Notification::fake();
        $website = $this->website();
        $website->update(['ssl_expires_at' => now()->addDays(40), 'ssl_issuer' => 'R3']);

        $this->checker(['reachable' => false, 'expires_at' => null, 'issuer' => null, 'names' => [], 'chain_valid' => null,
            'hostname_match' => null, 'error' => 'Connection refused'])->ssl($website);

        $fresh = $website->fresh();
        $this->assertNotNull($fresh->ssl_expires_at);
        $this->assertSame('R3', $fresh->ssl_issuer);
        $this->assertSame('Connection refused', $fresh->ssl_error);
        $this->assertSame(0, Incident::where('website_id', $website->id)->count());
    }

    public function test_the_certificate_is_inspected_at_most_once_a_day(): void
    {
        Notification::fake();
        $website = $this->website();
        $website->update(['ssl_checked_at' => now()->subHours(23)]);
        $checker = $this->checker([]);

        $checker->ssl($website);
        $this->assertSame(0, $checker->inspections);

        $website->update(['ssl_checked_at' => now()->subHours(25)]);
        $checker->ssl($website->fresh());
        $this->assertSame(1, $checker->inspections);
    }

    public function test_a_plain_http_site_is_never_inspected(): void
    {
        $checker = $this->checker([]);

        $checker->ssl($this->website('http://plain.example'));

        $this->assertSame(0, $checker->inspections);
    }

    // --- Checker: wiring into check() --------------------------------------

    public function test_a_tls_failure_still_gets_the_certificate_inspected(): void
    {
        Notification::fake();
        Sleep::fake();
        Http::fake(fn () => throw new ConnectionException('cURL error 60: SSL certificate problem: certificate has expired'));
        $website = $this->website();
        $checker = $this->checker(['expires_at' => now()->subDays(3), 'chain_valid' => null]);

        $check = $checker->check($website);

        $this->assertSame('ssl', $check->error_type);
        $this->assertSame(1, $checker->inspections);
        $this->assertSame('expired', $this->sslIncident($website)->meta['reason']);
    }

    public function test_a_plain_http_error_does_not_inspect_the_certificate(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response('', 500)]);
        $checker = $this->checker([]);

        $checker->check($this->website());

        $this->assertSame(0, $checker->inspections);
    }

    public function test_an_inspection_crash_never_costs_the_check_its_timestamp(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $website = $this->website();
        $checker = new class extends WebsiteChecker
        {
            protected function inspectCertificate(string $url): ?array
            {
                throw new RuntimeException('Malformed UTF-8 in certificate');
            }
        };

        $check = $checker->check($website);

        $this->assertTrue($check->is_up);
        $this->assertNotNull($website->fresh()->last_checked_at);
        $this->assertSame('up', $website->fresh()->last_status);
    }

    // --- Helpers -----------------------------------------------------------

    protected function website(?string $url = null): Website
    {
        static $sequence = 0;

        return Website::factory()->create(['url' => $url ?? 'https://ssl-'.(++$sequence).'.example', 'ssl_checked_at' => null]);
    }

    protected function sslIncident(Website $website): Incident
    {
        return Incident::where('website_id', $website->id)->where('type', 'ssl')->sole();
    }

    /** openssl_x509_parse() shape for a healthy, 60-day certificate. */
    protected function cert(array $overrides = []): array
    {
        return array_replace([
            'subject' => ['CN' => 'ok.test'],
            'issuer' => ['C' => 'US', 'O' => "Let's Encrypt", 'CN' => 'R3'],
            'validFrom_time_t' => time() - 86400 * 30,
            'validTo_time_t' => time() + 86400 * 60,
            'extensions' => ['subjectAltName' => 'DNS:ok.test, DNS:www.ok.test, DNS:*.ok.test, email:x@ok.test'],
        ], $overrides);
    }

    /**
     * An inspector whose handshake() records the call instead of opening a socket.
     * The unverified read returns $cert (or $firstReply); the verifying one fails with $verifyError when given.
     */
    protected function inspector(?array $cert = null, ?string $verifyError = null, ?array $firstReply = null): CertificateInspector
    {
        $cert ??= $this->cert();
        $record = function (string $ip, int $port, string $host, array $ssl): void {
            $this->handshakes[] = compact('ip', 'port', 'host', 'ssl');
        };

        return new class($record, $cert, $verifyError, $firstReply) extends CertificateInspector
        {
            public function __construct(private $record, private array $cert, private ?string $verifyError, private ?array $firstReply) {}

            protected function handshake(string $ip, int $port, string $host, array $ssl): array
            {
                ($this->record)($ip, $port, $host, $ssl);

                if (! empty($ssl['verify_peer'])) {
                    return $this->verifyError
                        ? ['connected' => false, 'cert' => null, 'protocol' => null, 'error' => $this->verifyError]
                        : ['connected' => true, 'cert' => null, 'protocol' => 'TLSv1.3', 'error' => null];
                }

                return $this->firstReply ?? ['connected' => true, 'cert' => $this->cert, 'protocol' => 'TLSv1.3', 'error' => null];
            }
        };
    }

    /** A checker whose certificate probe returns a fixed inspection result; ssl() runs the daily step directly. */
    protected function checker(array $overrides): WebsiteChecker
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
            public int $inspections = 0;

            public function __construct(protected array $cert) {}

            public function ssl(Website $website): void
            {
                $this->maybeCheckSsl($website);
            }

            protected function inspectCertificate(string $url): ?array
            {
                $this->inspections++;

                return $this->cert;
            }
        };
    }
}
