<?php

namespace Tests\Feature;

use App\Http\Controllers\JudolEvidenceController;
use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use App\Rules\PublicUrl;
use App\Services\Monitoring\CertificateInspector;
use App\Services\Monitoring\DefacementScanner;
use App\Services\Monitoring\JudolScanner;
use App\Services\Monitoring\SecurityGrader;
use App\Services\Monitoring\UptimeHistory;
use App\Services\Monitoring\WebsiteChecker;
use App\Services\Security\OutboundUrlGuard;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * The fetch connects only to the addresses the guard vetted (CURLOPT_RESOLVE),
 * per request - so a DNS answer that changes after the check cannot redirect it.
 * Http::fake stubs above the handler, so these drive Guzzle directly.
 */
class DnsPinningTest extends TestCase
{
    use RefreshDatabase;

    protected const IP_PATTERN = '/\b\d{1,3}(?:\.\d{1,3}){3}\b|\bfd00::|\[::/i';

    /** @var list<array{uri: string, options: array}> */
    protected array $sent = [];

    /** @var list<Response> */
    protected array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sent = [];
        $this->replies = [];
    }

    public function test_the_vetted_address_is_pinned_on_the_default_port(): void
    {
        OutboundUrlGuard::fakeDns(['evil.test' => ['93.184.216.34']]);

        $this->send('https://evil.test/');
        $this->send('http://evil.test/');

        $this->assertSame(['evil.test:443:93.184.216.34'], $this->sent[0]['options']['curl'][CURLOPT_RESOLVE]);
        $this->assertSame(['evil.test:80:93.184.216.34'], $this->sent[1]['options']['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('', $this->sent[0]['options']['proxy']);
    }

    public function test_an_explicit_port_is_pinned(): void
    {
        OutboundUrlGuard::fakeDns(['evil.test' => ['93.184.216.34']]);

        $this->send('http://Evil.test:8080/path');

        $this->assertSame(['evil.test:8080:93.184.216.34'], $this->sent[0]['options']['curl'][CURLOPT_RESOLVE]);
    }

    public function test_every_address_is_pinned_with_ipv6_in_brackets_up_to_eight(): void
    {
        $v4 = array_map(fn (int $i) => "93.184.216.{$i}", range(1, 9));
        OutboundUrlGuard::fakeDns([
            'dual.test' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
            'many.test' => $v4,
        ]);

        $this->send('https://dual.test/');
        $this->send('https://many.test/');

        $this->assertSame(['dual.test:443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]'], $this->sent[0]['options']['curl'][CURLOPT_RESOLVE]);
        $this->assertSame(['many.test:443:'.implode(',', array_slice($v4, 0, 8))], $this->sent[1]['options']['curl'][CURLOPT_RESOLVE]);
    }

    public function test_an_ip_literal_is_validated_but_not_resolved(): void
    {
        $this->send('https://93.184.216.34/');

        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $this->sent[0]['options']['curl'] ?? []);
        $this->assertSame('', $this->sent[0]['options']['proxy']);

        $this->assertRefused('http://10.0.0.1/', RuntimeException::class);
        $this->assertRefused('http://[::1]:8080/', RuntimeException::class);
    }

    public function test_a_host_rebound_to_a_private_address_is_refused(): void
    {
        OutboundUrlGuard::fakeDns(['rebind.test' => ['93.184.216.34']]);
        $this->send('https://rebind.test/');

        OutboundUrlGuard::flush();
        OutboundUrlGuard::fakeDns(['rebind.test' => ['93.184.216.34', '10.0.0.1']]);

        $this->assertRefused('https://rebind.test/', RuntimeException::class);
        $this->assertCount(1, $this->sent, 'the rebound request never reached curl');
    }

    public function test_an_unresolvable_host_is_never_sent(): void
    {
        OutboundUrlGuard::fakeDns([]);

        $this->assertNull(OutboundUrlGuard::reason('https://nowhere.test/'), 'still addable');

        try {
            $this->handler()(new Request('GET', 'https://nowhere.test/'), [])->wait();
            $this->fail('expected a refusal');
        } catch (ConnectException $e) {
            $this->assertSame(6, $e->getHandlerContext()['errno']);
        }

        $this->assertSame([], $this->sent);
    }

    public function test_each_redirect_hop_gets_its_own_pin(): void
    {
        OutboundUrlGuard::fakeDns(['start.test' => ['93.184.216.34'], 'other.test' => ['93.184.216.35']]);
        $this->replies = [new Response(302, ['Location' => 'http://other.test:8081/next']), new Response(200)];

        $response = $this->client()->get('https://start.test/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $this->sent);
        $this->assertSame(['start.test:443:93.184.216.34'], $this->sent[0]['options']['curl'][CURLOPT_RESOLVE]);
        $this->assertSame(['other.test:8081:93.184.216.35'], $this->sent[1]['options']['curl'][CURLOPT_RESOLVE]);
    }

    public function test_a_redirect_to_a_private_host_is_refused_by_the_handler_itself(): void
    {
        OutboundUrlGuard::fakeDns(['start.test' => ['93.184.216.34'], 'internal.test' => ['10.0.0.7']]);

        foreach (['http://internal.test/admin', 'http://169.254.169.254/latest/meta-data/'] as $location) {
            $this->sent = [];
            $this->replies = [new Response(302, ['Location' => $location])];

            try {
                // Plain allow_redirects: no on_redirect, so only the handler stands in the way.
                $this->client(['allow_redirects' => ['max' => 5]])->get('https://start.test/');
                $this->fail("redirect to {$location} should be refused");
            } catch (RuntimeException $e) {
                $this->assertDoesNotMatchRegularExpression(self::IP_PATTERN, $e->getMessage());
            }

            $this->assertCount(1, $this->sent, "{$location} never reached curl");
        }
    }

    public function test_the_on_redirect_refusal_names_no_address(): void
    {
        OutboundUrlGuard::fakeDns(['start.test' => ['93.184.216.34']]);
        $this->replies = [new Response(302, ['Location' => 'http://10.0.0.7/'])];

        try {
            $this->client(OutboundUrlGuard::httpOptions())->get('https://start.test/');
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('Pengalihan ditolak', $e->getMessage());
            $this->assertDoesNotMatchRegularExpression(self::IP_PATTERN, $e->getMessage());
        }
    }

    public function test_the_checker_handler_is_pinned(): void
    {
        OutboundUrlGuard::fakeDns(['rebind.test' => ['127.0.0.1']]);
        $handler = (new ReflectionMethod(WebsiteChecker::class, 'cappedBodyHandler'))->invoke(new WebsiteChecker);

        foreach (['http://127.0.0.1:1/', 'http://rebind.test:1/'] as $url) {
            try {
                $handler(new Request('GET', $url), [])->wait();
                $this->fail("{$url} should be refused");
            } catch (RuntimeException $e) {
                $this->assertSame(RuntimeException::class, $e::class, 'refused by the guard, not a curl connect error');
            }
        }
    }

    public function test_a_blocked_check_stores_no_address(): void
    {
        Notification::fake();
        Sleep::fake();
        User::factory()->create();
        OutboundUrlGuard::fakeDns(['rebind.test' => ['10.0.0.9']]);
        $website = Website::factory()->create(['url' => 'https://rebind.test']);

        $check = app(WebsiteChecker::class)->check($website);

        $this->assertSame('blocked', $check->error_type);
        $this->assertDoesNotMatchRegularExpression(self::IP_PATTERN, $check->error_message);
    }

    public function test_an_unresolvable_site_is_a_dns_failure_without_reaching_curl(): void
    {
        Http::allowStrayRequests(); // unresolvable: pinningHandler() refuses before any socket
        Notification::fake();
        Sleep::fake();
        User::factory()->create();
        OutboundUrlGuard::fakeDns([]);
        $website = Website::factory()->create(['url' => 'https://nowhere.test']);

        $check = app(WebsiteChecker::class)->check($website);

        $this->assertFalse($check->is_up);
        $this->assertSame('dns', $check->error_type);
    }

    public function test_the_public_url_rule_names_no_address_but_logs_it(): void
    {
        Log::spy();
        OutboundUrlGuard::fakeDns(['intranet.test' => ['192.168.10.5']]);

        foreach (['http://10.0.0.5/', 'http://intranet.test/'] as $url) {
            $validator = Validator::make(['url' => $url], ['url' => [new PublicUrl]]);

            $this->assertTrue($validator->fails());
            $this->assertDoesNotMatchRegularExpression(self::IP_PATTERN, $validator->errors()->first('url'));
        }

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ($context['address'] ?? null) === '192.168.10.5');
    }

    public function test_an_old_blocked_incident_shows_a_generic_cause_detail(): void
    {
        $website = Website::factory()->create();
        Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical', 'title' => 'Down',
            'meta' => ['error_type' => 'blocked', 'error_message' => 'Alamat ini mengarah ke jaringan internal (10.0.0.5) dan tidak boleh dipantau.'],
            'status' => 'resolved', 'detected_at' => now()->subHour(), 'resolved_at' => now()->subMinutes(30),
        ]);

        $row = app(UptimeHistory::class)->downtimes($website->id)[0];

        $this->assertSame('blocked', $row['cause']);
        $this->assertDoesNotMatchRegularExpression(self::IP_PATTERN, $row['cause_detail']);
    }

    public function test_qa_retry_redirect_and_ssl_fallback_through_the_checker_are_all_pinned(): void
    {
        Http::allowStrayRequests(); // transport is pinned/faked below, never a real socket
        Notification::fake();
        Sleep::fake();
        User::factory()->create();
        // No real TLS handshake: the inspector only records where it would connect.
        $this->app->instance(CertificateInspector::class, $inspector = $this->recordingInspector());
        OutboundUrlGuard::fakeDns(['start.test' => ['93.184.216.34'], 'other.test' => ['93.184.216.35']]);
        $attempt = 0;
        $spy = function (RequestInterface $request, array $options) use (&$attempt) {
            $this->sent[] = ['uri' => (string) $request->getUri(), 'options' => $options];

            return match (++$attempt) {
                // Retried (connect refused), then a TLS error that goes to the verify=false fallback.
                1 => Create::rejectionFor(new ConnectException('cURL error 7: Failed to connect', $request, null, ['errno' => 7])),
                2 => Create::rejectionFor(new ConnectException('cURL error 60: SSL certificate problem', $request, null, ['errno' => 60])),
                3 => Create::promiseFor(new Response(302, ['Location' => 'https://other.test:8443/next'])),
                default => Create::promiseFor(new Response(200, [], 'ok')),
            };
        };
        $checker = new class($spy) extends WebsiteChecker
        {
            public function __construct(private $spy) {}

            protected function cappedBodyHandler(?callable $handler = null): callable
            {
                return parent::cappedBodyHandler(OutboundUrlGuard::pinningHandler($this->spy));
            }
        };

        $check = $checker->check(Website::factory()->create(['url' => 'https://start.test']));

        $this->assertTrue($check->is_up, (string) $check->error_message);
        $this->assertCount(4, $this->sent);
        foreach ([0, 1, 2] as $i) {
            $this->assertSame(['start.test:443:93.184.216.34'], $this->sent[$i]['options']['curl'][CURLOPT_RESOLVE]);
        }
        $this->assertFalse($this->sent[2]['options']['verify'], 'the fallback request');
        $this->assertSame(['other.test:8443:93.184.216.35'], $this->sent[3]['options']['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($this->sent[3]['options']['verify'], 'the fallback redirect hop');
    }

    public function test_qa_the_pin_format_is_honoured_by_real_curl(): void
    {
        // The spy tests pin the string format; this proves curl actually matches that key (case, trailing dot, brackets).
        $dir = sys_get_temp_dir().'/pin-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/index.html', 'PINNED');
        $port = random_int(20000, 40000);
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $dir], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);

        try {
            foreach (['http://pin-a.invalid', 'http://PIN-B.invalid', 'http://pin-c.invalid.'] as $base) {
                $host = strtolower(parse_url($base, PHP_URL_HOST));
                OutboundUrlGuard::flush();
                OutboundUrlGuard::fakeDns([$host => ['2606:2800:220:1::1', '93.184.216.34']]);
                $this->sent = [];
                $this->send("{$base}:{$port}/");

                // Same key curl was handed, addresses swapped for loopback (the guard rightly refuses loopback).
                $resolve = str_replace(['2606:2800:220:1::1', '93.184.216.34'], ['::1', '127.0.0.1'], $this->sent[0]['options']['curl'][CURLOPT_RESOLVE]);
                $body = null;

                foreach (range(1, 20) as $ignored) {
                    try {
                        $response = (new CurlHandler)(new Request('GET', "{$base}:{$port}/"), ['curl' => [CURLOPT_RESOLVE => $resolve], 'proxy' => '', 'connect_timeout' => 2, 'timeout' => 5])->wait();
                        $body = (string) $response->getBody();
                        break;
                    } catch (ConnectException $e) {
                        // errno 6 means curl ignored the pin and resolved the name itself.
                        $this->assertNotSame(6, $e->getHandlerContext()['errno'] ?? null, "{$base}: pin not applied");
                        usleep(100_000);
                    }
                }

                if ($body === null) {
                    $this->markTestSkipped('Local PHP server did not start.');
                }

                $this->assertSame('PINNED', $body, $base);
            }
        } finally {
            proc_terminate($server);
            @unlink($dir.'/index.html');
            @rmdir($dir);
        }
    }

    /**
     * Known bug: error_type only enters incident meta with this change, so every incident opened
     * before deploy has no error_type - the generic branch never matches the rows it was written for.
     */
    public function test_qa_a_pre_deploy_blocked_incident_without_error_type_names_no_address(): void
    {
        $website = Website::factory()->create();
        Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical', 'title' => 'Down',
            'description' => 'Alamat ini mengarah ke jaringan internal (10.0.0.5) dan tidak boleh dipantau.',
            'meta' => ['status_code' => null, 'error_message' => 'Alamat ini mengarah ke jaringan internal (10.0.0.5) dan tidak boleh dipantau.'],
            'status' => 'resolved', 'detected_at' => now()->subHour(), 'resolved_at' => now()->subMinutes(30),
        ]);

        $row = app(UptimeHistory::class)->downtimes($website->id)[0];

        $this->assertDoesNotMatchRegularExpression(self::IP_PATTERN, (string) $row['cause_detail']);
    }

    /**
     * Known bug: an empty lookup is memoized for 60s, so the retry after a transient DNS failure
     * reuses the miss and never asks the resolver again - curl used to re-resolve on that retry.
     */
    public function test_qa_the_retry_after_a_dns_blip_asks_the_resolver_again(): void
    {
        Http::allowStrayRequests(); // transport is pinned/faked below, never a real socket
        Notification::fake();
        Sleep::fake();
        User::factory()->create();
        // No real TLS handshake: the inspector only records where it would connect.
        $this->app->instance(CertificateInspector::class, $inspector = $this->recordingInspector());
        OutboundUrlGuard::fakeDns([]);
        // The resolver answers again by the time the 300ms backoff is over.
        Sleep::whenFakingSleep(fn () => OutboundUrlGuard::fakeDns(['blip.test' => ['93.184.216.34']]));
        $spy = function (RequestInterface $request, array $options) {
            $this->sent[] = ['uri' => (string) $request->getUri(), 'options' => $options];

            return Create::promiseFor(new Response(200, [], 'ok'));
        };
        $checker = new class($spy) extends WebsiteChecker
        {
            public function __construct(private $spy) {}

            protected function cappedBodyHandler(?callable $handler = null): callable
            {
                return parent::cappedBodyHandler(OutboundUrlGuard::pinningHandler($this->spy));
            }
        };

        $check = $checker->check(Website::factory()->create(['url' => 'https://blip.test']));

        $this->assertTrue($check->is_up, (string) $check->error_message);
        $this->assertSame(['blip.test:443:93.184.216.34'], $this->sent[0]['options']['curl'][CURLOPT_RESOLVE]);
    }

    /**
     * Known bug: maybeCheckSsl() opens ssl://host:port with PHP's own resolver - no guard, no pin -
     * so a host the guard vetted as public can still be connected to on a loopback/internal address.
     */
    public function test_qa_the_certificate_probe_never_connects_to_an_address_the_guard_did_not_vet(): void
    {
        if (gethostbyname('localtest.me') !== '127.0.0.1') {
            $this->markTestSkipped('Needs public DNS: localtest.me must resolve to 127.0.0.1.');
        }

        Notification::fake();
        Sleep::fake();
        User::factory()->create();
        // No real TLS handshake: the inspector only records where it would connect.
        $this->app->instance(CertificateInspector::class, $inspector = $this->recordingInspector());
        // The guard is told the host is public; the system resolver says loopback (a rebind).
        OutboundUrlGuard::fakeDns(['localtest.me' => ['93.184.216.34']]);
        Http::fake(['*' => Http::response('ok', 200)]);
        $port = random_int(20000, 40000);
        $listener = stream_socket_server("tcp://127.0.0.1:{$port}");

        try {
            app(WebsiteChecker::class)->check(Website::factory()->create(['url' => "https://localtest.me:{$port}"]));

            $this->assertFalse(@stream_socket_accept($listener, 0), 'the certificate probe reached 127.0.0.1');
            $this->assertSame('93.184.216.34', $inspector->calls[0]['ip'] ?? null);
        } finally {
            fclose($listener);
        }
    }

    public function test_qa_the_certificate_inspector_only_connects_to_the_vetted_address(): void
    {
        OutboundUrlGuard::fakeDns(['cert.test' => ['93.184.216.34']]);
        $inspector = $this->recordingInspector();

        $inspector->inspect('https://cert.test/');
        $inspector->tlsVersion('https://cert.test:8443/');

        $this->assertNotEmpty($inspector->calls);
        foreach ($inspector->calls as $call) {
            $this->assertSame('93.184.216.34', $call['ip']);
            $this->assertSame('cert.test', $call['host'], 'SNI on the name, socket on the vetted IP');
        }
        $this->assertSame(8443, end($inspector->calls)['port']);
    }

    public function test_qa_the_certificate_inspector_refuses_a_host_rebound_to_a_private_address(): void
    {
        OutboundUrlGuard::fakeDns(['rebind.test' => ['93.184.216.34', '10.0.0.1']]);
        $inspector = $this->recordingInspector();

        $this->assertNull($inspector->inspect('https://rebind.test/'));
        $this->assertNull($inspector->tlsVersion('https://rebind.test/'));
        $this->assertNull($inspector->inspect('https://[::1]/'));
        $this->assertSame([], $inspector->calls);
    }

    public function test_qa_the_grader_reads_the_tls_version_through_the_pinned_inspector(): void
    {
        OutboundUrlGuard::fakeDns(['graded.test' => ['93.184.216.34']]);
        Http::fake(['*' => Http::response('ok', 200)]);
        $inspector = $this->recordingInspector();
        $this->app->instance(CertificateInspector::class, $inspector);

        $grade = app(SecurityGrader::class)->grade(Website::factory()->create(['url' => 'https://graded.test']));

        $this->assertCount(1, $inspector->calls);
        $this->assertSame('93.184.216.34', $inspector->calls[0]['ip']);
        $tls = collect($grade['findings'])->firstWhere('key', 'tls_version');
        $this->assertTrue($tls['ok']);
    }

    public function test_qa_the_grader_never_grades_a_site_that_resolves_internally(): void
    {
        OutboundUrlGuard::fakeDns(['internal.test' => ['10.0.0.7']]);
        Http::fake(['*' => Http::response('ok', 200)]);
        $inspector = $this->recordingInspector();
        $this->app->instance(CertificateInspector::class, $inspector);

        $this->assertNull(app(SecurityGrader::class)->grade(Website::factory()->create(['url' => 'https://internal.test'])));
        Http::assertNothingSent();
        $this->assertSame([], $inspector->calls);
    }

    public function test_qa_neither_the_grader_nor_the_inspector_falls_back_to_the_system_resolver(): void
    {
        // /etc/hosts maps the machine's own name to loopback; the guard (faked) knows no address for it.
        $host = strtolower(gethostname());
        $ip = gethostbyname($host);

        if (! str_starts_with($ip, '127.')) {
            $this->markTestSkipped('The local hostname does not resolve to loopback here.');
        }

        $port = random_int(20000, 40000);
        $listener = @stream_socket_server("tcp://{$ip}:{$port}");

        if (! $listener) {
            $this->markTestSkipped("Cannot listen on {$ip}:{$port}.");
        }

        try {
            OutboundUrlGuard::fakeDns([]);

            $this->assertNull(app(SecurityGrader::class)->grade(Website::factory()->create(['url' => "https://{$host}:{$port}"])));
            $this->assertNull(app(CertificateInspector::class)->inspect("https://{$host}:{$port}/"));
            $this->assertNull(app(CertificateInspector::class)->tlsVersion("https://{$host}:{$port}/"));

            $this->assertFalse(@stream_socket_accept($listener, 0), 'something connected via the system resolver');
        } finally {
            fclose($listener);
        }
    }

    /** CertificateInspector whose handshake() records where it would connect. */
    protected function recordingInspector(): CertificateInspector
    {
        return new class extends CertificateInspector
        {
            /** @var list<array{ip: string, port: int, host: string}> */
            public array $calls = [];

            protected function handshake(string $ip, int $port, string $host, array $ssl): array
            {
                $this->calls[] = ['ip' => $ip, 'port' => $port, 'host' => $host];

                return ['connected' => true, 'cert' => null, 'protocol' => 'TLSv1.3', 'error' => null];
            }
        };
    }

    public function test_the_judol_crawler_connects_only_to_vetted_addresses(): void
    {
        Http::allowStrayRequests(); // transport is pinned/faked below, never a real socket
        OutboundUrlGuard::fakeDns(['judol.test' => ['93.184.216.34']]);
        OutboundUrlGuard::fakeTransport($this->transport());

        $client = (new ReflectionMethod(JudolScanner::class, 'httpClient'))->invoke(app(JudolScanner::class));
        $client->get('https://judol.test/');

        $this->assertSame(['judol.test:443:93.184.216.34'], $this->sent[0]['options']['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('', $this->sent[0]['options']['proxy']);
    }

    public function test_the_defacement_scanner_connects_only_to_vetted_addresses(): void
    {
        Http::allowStrayRequests(); // transport is pinned/faked below, never a real socket
        OutboundUrlGuard::fakeDns(['deface.test' => ['93.184.216.34']]);
        OutboundUrlGuard::fakeTransport($this->transport());
        $fetch = new ReflectionMethod(DefacementScanner::class, 'fetch');

        $this->assertSame('ok', $fetch->invoke(app(DefacementScanner::class), 'https://deface.test/'));
        $this->assertSame(['deface.test:443:93.184.216.34'], $this->sent[0]['options']['curl'][CURLOPT_RESOLVE]);

        // Nothing vetted for the host: the request never reaches the transport.
        $this->assertNull($fetch->invoke(app(DefacementScanner::class), 'https://unvetted.test/'));
        $this->assertCount(1, $this->sent);
    }

    public function test_the_judol_evidence_fetch_connects_only_to_vetted_addresses(): void
    {
        Http::allowStrayRequests(); // transport is pinned/faked below, never a real socket
        OutboundUrlGuard::fakeDns(['img.test' => ['93.184.216.34']]);
        OutboundUrlGuard::fakeTransport($this->transport());

        $client = (new ReflectionMethod(JudolEvidenceController::class, 'client'))->invoke(app(JudolEvidenceController::class));
        $client->get('https://img.test/banner.png');

        $this->assertSame(['img.test:443:93.184.216.34'], $this->sent[0]['options']['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('', $this->sent[0]['options']['proxy']);
    }

    /** A transport for OutboundUrlGuard::fakeTransport() that records what reached it. */
    protected function transport(): callable
    {
        return function (RequestInterface $request, array $options) {
            $this->sent[] = ['uri' => (string) $request->getUri(), 'options' => $options];

            return Create::promiseFor(new Response(200, ['Content-Type' => 'text/html'], 'ok'));
        };
    }

    /** pinningHandler() around a spy that records what it would have handed to curl. */
    protected function handler(): callable
    {
        return OutboundUrlGuard::pinningHandler(function (RequestInterface $request, array $options) {
            $this->sent[] = ['uri' => (string) $request->getUri(), 'options' => $options];

            return Create::promiseFor(array_shift($this->replies) ?? new Response(200));
        });
    }

    protected function client(array $options = []): Client
    {
        return new Client(['handler' => HandlerStack::create($this->handler())] + $options);
    }

    protected function send(string $url): void
    {
        $this->handler()(new Request('GET', $url), [])->wait();
    }

    /** @param class-string<Throwable> $expected */
    protected function assertRefused(string $url, string $expected): void
    {
        $before = count($this->sent);

        try {
            $this->send($url);
            $this->fail("{$url} should be refused");
        } catch (Throwable $e) {
            $this->assertInstanceOf($expected, $e);
            $this->assertDoesNotMatchRegularExpression(self::IP_PATTERN, $e->getMessage());
        }

        $this->assertCount($before, $this->sent);
    }
}
