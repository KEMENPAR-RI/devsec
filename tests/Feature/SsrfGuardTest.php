<?php

namespace Tests\Feature;

use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Websites\Index;
use App\Models\User;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every address this server can be told to fetch goes through OutboundUrlGuard.
 * Without it, anyone who may add a site can aim the monitor at the network it
 * runs in - loopback, an intranet host, or a cloud metadata endpoint.
 */
class SsrfGuardTest extends TestCase
{
    use RefreshDatabase;

    public static function blockedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/'],
            'loopback by name' => ['http://localhost/'],
            'loopback ipv6' => ['http://[::1]/'],
            'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'private class A' => ['http://10.0.0.5/admin'],
            'private class B' => ['http://172.16.4.9/'],
            'private class C' => ['http://192.168.1.1/'],
            'link-local metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'carrier-grade NAT' => ['http://100.64.0.1/'],
            'unique local ipv6' => ['http://[fd00::1]/'],
            'non-http scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://127.0.0.1:6379/'],
            'userinfo credentials' => ['http://user:pass@93.184.216.34/'],
            'userinfo username only' => ['http://user@93.184.216.34/'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function test_guard_refuses_internal_addresses(string $url): void
    {
        $this->assertNotNull(OutboundUrlGuard::reason($url), "{$url} seharusnya ditolak");
    }

    public function test_guard_allows_a_public_address(): void
    {
        $this->assertNull(OutboundUrlGuard::reason('http://93.184.216.34/'));
    }

    public function test_guard_allows_a_host_that_does_not_resolve(): void
    {
        // Unresolvable is not internal - the fetch fails on its own, and a site
        // whose DNS is briefly broken must still be addable.
        $this->assertNull(OutboundUrlGuard::reason('https://portal-test.example'));
    }

    public function test_guard_allows_a_host_resolving_into_an_allowed_private_range(): void
    {
        config(['monitoring.allowed_private_cidrs' => ['10.10.100.0/24']]);
        OutboundUrlGuard::fakeDns(['www.intranet.example' => ['10.10.100.156']]);

        $this->assertNull(OutboundUrlGuard::reason('https://www.intranet.example'));
    }

    public function test_guard_still_refuses_private_addresses_outside_the_allowed_range(): void
    {
        config(['monitoring.allowed_private_cidrs' => ['10.10.100.0/24']]);
        OutboundUrlGuard::fakeDns([
            'other.intranet.example' => ['10.10.101.5'],
            'mixed.intranet.example' => ['10.10.100.5', '192.168.1.1'],
        ]);

        $this->assertNotNull(OutboundUrlGuard::reason('https://other.intranet.example'));
        $this->assertNotNull(OutboundUrlGuard::reason('https://mixed.intranet.example'));
        $this->assertNotNull(OutboundUrlGuard::reason('http://10.10.101.5/'));
    }

    public function test_allowlist_never_unblocks_loopback_metadata_or_cgnat(): void
    {
        config(['monitoring.allowed_private_cidrs' => ['127.0.0.0/8', '169.254.0.0/16', '100.64.0.0/10', '0.0.0.0/0']]);

        foreach (['http://127.0.0.1/', 'http://169.254.169.254/', 'http://100.64.0.1/', 'http://10.0.0.5/'] as $url) {
            $this->assertNotNull(OutboundUrlGuard::reason($url), "{$url} seharusnya tetap ditolak");
        }
    }

    public function test_allowed_private_range_stays_hidden_from_dns_snapshots(): void
    {
        config(['monitoring.allowed_private_cidrs' => ['10.10.100.0/24']]);

        $this->assertFalse(OutboundUrlGuard::isPublicAddress('10.10.100.156'));
    }

    public function test_bulk_import_accepts_hosts_in_the_allowed_private_range(): void
    {
        config(['monitoring.allowed_private_cidrs' => ['10.10.100.0/24']]);
        OutboundUrlGuard::fakeDns(['www.intranet.example' => ['10.10.100.156']]);

        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->set('bulkInput', "www.intranet.example\nhttp://192.168.1.1")
            ->call('bulkImport');

        $this->assertDatabaseCount('websites', 1);
        $this->assertDatabaseHas('websites', ['url' => 'https://www.intranet.example']);
    }

    public function test_website_form_rejects_an_internal_url(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->call('openCreate')
            ->set('name', 'Router')
            ->set('url', 'http://192.168.1.1')
            ->call('save')
            ->assertHasErrors('url');

        $this->assertDatabaseCount('websites', 0);
    }

    public function test_bulk_import_rejects_an_internal_url(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->set('bulkInput', "Publik, https://portal-test.example\nMetadata, http://169.254.169.254")
            ->call('bulkImport');

        $this->assertDatabaseCount('websites', 1);
        $this->assertDatabaseHas('websites', ['url' => 'https://portal-test.example']);
    }

    public function test_the_ai_provider_url_rejects_an_internal_address(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(SettingsIndex::class)
            ->set('aiProvider', 'openai')
            ->set('aiBaseUrl', 'http://127.0.0.1:11434/v1')
            ->set('aiModel', 'llama3')
            ->call('save')
            ->assertHasErrors('aiBaseUrl');
    }
}
