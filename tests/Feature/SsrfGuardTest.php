<?php

namespace Tests\Feature;

use App\Livewire\Websites\Index;
use App\Livewire\Websites\Show;
use App\Models\User;
use App\Models\Website;
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
        $this->assertNull(OutboundUrlGuard::reason('https://judol-test.example'));
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
            ->set('bulkInput', "Publik, https://judol-test.example\nMetadata, http://169.254.169.254")
            ->call('bulkImport');

        $this->assertDatabaseCount('websites', 1);
        $this->assertDatabaseHas('websites', ['url' => 'https://judol-test.example']);
    }

    public function test_manual_scan_rejects_an_internal_url(): void
    {
        $website = Website::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test(Show::class, ['website' => $website])
            ->set('manualScanUrl', 'http://127.0.0.1:8080/admin')
            ->call('scanSpecificUrl')
            ->assertHasErrors('manualScanUrl');
    }
}
