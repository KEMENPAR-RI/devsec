<?php

namespace Tests\Feature;

use App\Livewire\Websites\Show;
use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use App\Services\Monitoring\DnsMonitor;
use App\Services\Monitoring\WebsiteChecker;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\FakeDnsRecords;
use Tests\TestCase;

require_once __DIR__.'/../Support/FakeDnsRecords.php';

/**
 * DnsMonitor::query() end to end, with dns_get_record() answered from a table:
 * which name each type is read from, internal addresses masked before they are
 * stored, shown or alerted on, and answer sets capped.
 */
class DnsRecordSanitizingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
    }

    protected function tearDown(): void
    {
        FakeDnsRecords::reset();

        parent::tearDown();
    }

    public function test_host_types_are_read_off_the_host_and_ns_mx_off_the_apex(): void
    {
        FakeDnsRecords::fake([
            'www.contoh.go.id A' => [['ip' => '93.184.216.34']],
            'contoh.go.id NS' => [['target' => 'NS2.Contoh.go.id.'], ['target' => 'ns1.contoh.go.id']],
            'contoh.go.id MX' => [['target' => 'mail.contoh.go.id.', 'pri' => 10]],
        ]);

        $records = app(DnsMonitor::class)->lookUp('www.contoh.go.id');

        $this->assertSame(['93.184.216.34'], $records['A']);
        $this->assertSame(['ns1.contoh.go.id', 'ns2.contoh.go.id'], $records['NS']);
        $this->assertSame(['10 mail.contoh.go.id'], $records['MX']);
        $this->assertSame(['contoh.go.id'], $records['apex']);
        $this->assertArrayNotHasKey('AAAA', $records, 'an empty type is omitted, not stored as []');
        $this->assertContains('www.contoh.go.id CNAME', FakeDnsRecords::$queries);
        $this->assertContains('contoh.go.id NS', FakeDnsRecords::$queries);
        $this->assertNotContains('www.contoh.go.id NS', FakeDnsRecords::$queries);
    }

    public function test_internal_addresses_are_masked_and_public_ones_kept(): void
    {
        FakeDnsRecords::fake([
            'leak.example A' => [['ip' => '93.184.216.34'], ['ip' => '10.0.0.5'], ['ip' => '192.168.1.9'], ['ip' => '100.64.0.1']],
            'leak.example AAAA' => [['ipv6' => '::1'], ['ipv6' => '::ffff:127.0.0.1'], ['ipv6' => 'fd00::5']],
        ]);

        $records = app(DnsMonitor::class)->lookUp('leak.example');

        $this->assertSame(['93.184.216.34', DnsMonitor::INTERNAL], $records['A'], 'all internal values collapse into one marker');
        $this->assertSame([DnsMonitor::INTERNAL], $records['AAAA']);
        $this->assertStringNotContainsString('10.0.0.5', json_encode($records));
    }

    public function test_a_cname_is_kept_only_when_it_resolves_publicly(): void
    {
        OutboundUrlGuard::fakeDns(['cdn.public.example' => ['93.184.216.34'], 'db.corp.example' => ['10.1.2.3']]);
        FakeDnsRecords::fake([
            'a.example CNAME' => [['target' => 'cdn.public.example.']],
            'b.example CNAME' => [['target' => 'db.corp.example.']],
            'c.example CNAME' => [['target' => 'nowhere.example.']],
        ]);
        $monitor = app(DnsMonitor::class);

        $this->assertSame(['cdn.public.example'], $monitor->lookUp('a.example')['CNAME']);
        $this->assertSame([DnsMonitor::INTERNAL], $monitor->lookUp('b.example')['CNAME']);
        $this->assertSame([DnsMonitor::INTERNAL], $monitor->lookUp('c.example')['CNAME'], 'unresolved is not proof of public');
    }

    public function test_ns_and_mx_names_are_not_masked(): void
    {
        FakeDnsRecords::fake([
            'mail.example NS' => [['target' => 'ns1.internal-looking.example']],
            'mail.example MX' => [['target' => 'mx.internal-looking.example', 'pri' => 5]],
        ]);

        $records = app(DnsMonitor::class)->lookUp('mail.example');

        $this->assertSame(['ns1.internal-looking.example'], $records['NS']);
        $this->assertSame(['5 mx.internal-looking.example'], $records['MX']);
    }

    public function test_each_type_is_capped_and_sorted(): void
    {
        $rows = array_map(fn (int $i) => ['ip' => "93.184.216.{$i}"], range(60, 1));
        FakeDnsRecords::fake(['big.example A' => $rows]);

        $a = app(DnsMonitor::class)->lookUp('big.example')['A'];

        $this->assertCount(DnsMonitor::MAX_VALUES, $a);
        $sorted = $a;
        sort($sorted);
        $this->assertSame($sorted, $a);
    }

    public function test_a_record_moving_to_an_internal_address_alerts_without_naming_it(): void
    {
        Notification::fake();
        $website = Website::factory()->create(['url' => 'https://moved.example']);
        $website->update(['dns_records' => ['A' => ['93.184.216.34'], 'apex' => ['moved.example']]]);
        FakeDnsRecords::fake(['moved.example A' => [['ip' => '10.0.0.5']]]);

        app(WebsiteChecker::class)->checkDns($website);

        $incident = Incident::where('type', 'dns')->sole();
        $this->assertSame([DnsMonitor::INTERNAL], $incident->meta['changes']['A']['ke']);
        $this->assertSame('critical', $incident->severity);
        $this->assertStringNotContainsString('10.0.0.5', json_encode([$incident->meta, $website->fresh()->dns_records]));
    }

    public function test_a_record_moving_back_from_internal_is_a_change_too(): void
    {
        Notification::fake();
        $website = Website::factory()->create(['url' => 'https://back.example']);
        $website->update(['dns_records' => ['A' => [DnsMonitor::INTERNAL], 'apex' => ['back.example']]]);
        FakeDnsRecords::fake(['back.example A' => [['ip' => '93.184.216.34']]]);

        app(WebsiteChecker::class)->checkDns($website);

        $this->assertSame([DnsMonitor::INTERNAL], Incident::where('type', 'dns')->sole()->meta['changes']['A']['dari']);
    }

    public function test_a_large_answer_stays_capped_in_the_incident_history(): void
    {
        Notification::fake();
        $website = Website::factory()->create(['url' => 'https://many.example']);
        $website->update(['dns_records' => ['A' => ['93.184.216.34'], 'apex' => ['many.example']]]);
        $checker = app(WebsiteChecker::class);

        FakeDnsRecords::fake(['many.example A' => array_map(fn (int $i) => ['ip' => "93.184.217.{$i}"], range(1, 50))]);
        $checker->checkDns($website);
        FakeDnsRecords::fake(['many.example A' => array_map(fn (int $i) => ['ip' => "93.184.218.{$i}"], range(1, 50))]);
        $checker->checkDns($website->fresh(), force: true);

        $meta = Incident::where('type', 'dns')->sole()->meta;
        $this->assertCount(DnsMonitor::MAX_VALUES, $meta['changes']['A']['ke']);
        $this->assertCount(DnsMonitor::MAX_VALUES, $meta['history'][0]['changes']['A']['ke']);
        $this->assertCount(DnsMonitor::MAX_VALUES, $website->fresh()->dns_records['A']);
    }

    public function test_the_site_page_never_renders_an_internal_address(): void
    {
        Notification::fake();
        $website = Website::factory()->create(['url' => 'https://render.example']);
        FakeDnsRecords::fake([
            'render.example A' => [['ip' => '93.184.216.34'], ['ip' => '10.20.30.40']],
            'render.example AAAA' => [['ipv6' => 'fd12:3456::1']],
        ]);
        app(WebsiteChecker::class)->checkDns($website);

        Livewire::actingAs($this->admin)
            ->test(Show::class, ['website' => $website->fresh()])
            ->assertSee('93.184.216.34')
            ->assertSee(DnsMonitor::INTERNAL)
            ->assertDontSee('10.20.30.40')
            ->assertDontSee('fd12:3456::1');
    }
}
