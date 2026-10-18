<?php

namespace Tests\Feature;

use App\Livewire\Websites\Index;
use App\Livewire\Websites\Show;
use App\Models\Role;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The site form's DNS/domain fields and the "Sertifikat, DNS & Domain" panel:
 * an empty site reads "Belum dicek", a checked one shows what was found, and
 * certificate text (attacker-controlled) is always escaped.
 */
class CertificateDnsPanelTest extends TestCase
{
    use RefreshDatabase;

    // --- Form: rotating IPs ---------------------------------------------------

    public function test_a_site_can_be_marked_as_rotating_its_ips(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->call('openCreate')
            ->set('name', 'Portal CDN')
            ->set('url', 'https://cdn-portal.example')
            ->set('dnsIpRotates', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('websites', ['name' => 'Portal CDN', 'dns_ip_rotates' => true]);
    }

    public function test_editing_preloads_the_flag_and_create_resets_it(): void
    {
        $website = Website::factory()->create(['dns_ip_rotates' => true]);

        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->call('openEdit', $website->uuid)
            ->assertSet('dnsIpRotates', true)
            ->call('openCreate')
            ->assertSet('dnsIpRotates', false);
    }

    public function test_the_flag_can_be_switched_off_again(): void
    {
        $website = Website::factory()->create(['dns_ip_rotates' => true]);

        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->call('openEdit', $website->uuid)
            ->set('dnsIpRotates', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($website->fresh()->dns_ip_rotates);
    }

    // --- Form: domain expiry source --------------------------------------------

    public function test_a_typed_domain_date_is_marked_manual(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->call('openCreate')
            ->set('name', 'Dinas Manual')
            ->set('url', 'https://manual-date.example')
            ->set('domainExpiresAt', '2027-05-01')
            ->call('save')
            ->assertHasNoErrors();

        $website = Website::where('name', 'Dinas Manual')->sole();
        $this->assertSame('2027-05-01', $website->domain_expires_at->format('Y-m-d'));
        $this->assertSame('manual', $website->domain_expiry_source);
    }

    public function test_re_saving_an_rdap_date_untouched_keeps_its_source(): void
    {
        $website = Website::factory()->create(['domain_expires_at' => '2027-05-01', 'domain_expiry_source' => 'rdap']);

        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->call('openEdit', $website->uuid)
            ->set('name', 'Nama Baru')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('rdap', $website->fresh()->domain_expiry_source);
    }

    public function test_changing_an_rdap_date_makes_it_manual(): void
    {
        $website = Website::factory()->create(['domain_expires_at' => '2027-05-01', 'domain_expiry_source' => 'rdap']);

        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->call('openEdit', $website->uuid)
            ->set('domainExpiresAt', '2027-06-01')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('manual', $website->fresh()->domain_expiry_source);
    }

    public function test_clearing_the_date_clears_its_source(): void
    {
        $website = Website::factory()->create(['domain_expires_at' => '2027-05-01', 'domain_expiry_source' => 'manual']);

        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->call('openEdit', $website->uuid)
            ->set('domainExpiresAt', '')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $website->fresh();
        $this->assertNull($fresh->domain_expires_at);
        $this->assertNull($fresh->domain_expiry_source);
    }

    public function test_an_invalid_domain_date_is_rejected(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->call('openCreate')
            ->set('name', 'Tanggal Rusak')
            ->set('url', 'https://bad-date.example')
            ->set('domainExpiresAt', 'bukan-tanggal')
            ->call('save')
            ->assertHasErrors(['domainExpiresAt']);

        $this->assertDatabaseMissing('websites', ['name' => 'Tanggal Rusak']);
    }

    public function test_a_viewer_cannot_open_the_edit_form(): void
    {
        $viewer = User::factory()->create(['role_id' => Role::factory()->withPermissions(['situs.view'])->create()->id]);
        $website = Website::factory()->create(['dns_ip_rotates' => false]);

        Livewire::actingAs($viewer)
            ->test(Index::class)
            ->call('openEdit', $website->uuid)
            ->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(Index::class)
            ->call('openCreate')
            ->assertForbidden();

        $this->assertFalse($website->fresh()->dns_ip_rotates);
    }

    // --- Site page panel ----------------------------------------------------

    public function test_an_unchecked_site_reads_belum_dicek_in_every_column(): void
    {
        $website = Website::factory()->create(['ssl_checked_at' => null, 'dns_records' => null, 'domain_expires_at' => null]);

        $html = $this->show($website)
            ->assertSee('Sertifikat, DNS & Domain')
            ->html();

        $this->assertSame(3, substr_count($html, 'Belum dicek.'));
    }

    public function test_a_checked_site_shows_what_was_found(): void
    {
        $website = Website::factory()->create([
            'ssl_checked_at' => now()->subHour(),
            'ssl_expires_at' => now()->addDays(10),
            'ssl_issuer' => "R3 (Let's Encrypt)",
            'ssl_names' => ['other.example', 'www.other.example'],
            'ssl_chain_valid' => false,
            'ssl_hostname_match' => false,
            'dns_records' => ['A' => ['93.184.216.34'], 'NS' => ['ns1.contoh.example'], 'MX' => ['10 mail.contoh.example'], 'apex' => ['contoh.example']],
            'dns_checked_at' => now()->subHour(),
            'dns_ip_rotates' => true,
            'domain_expires_at' => now()->addDays(30),
            'domain_expiry_source' => 'rdap',
        ]);

        $html = $this->show($website)
            ->assertSee("R3 (Let's Encrypt)")
            ->assertSee('Tidak valid')
            ->assertSee('Tidak cocok')
            ->assertSee('www.other.example')
            ->assertSee('93.184.216.34')
            ->assertSee('ns1.contoh.example')
            ->assertSee('10 mail.contoh.example')
            ->assertSee('IP berganti-ganti')
            ->assertSee('RDAP')
            ->assertSee('hari lagi')
            ->html();

        $this->assertSame(0, substr_count($html, 'Belum dicek.'));
        $this->assertStringNotContainsString('>contoh.example<', $html, 'the internal apex marker is not a record');
    }

    public function test_an_expired_certificate_reads_kedaluwarsa(): void
    {
        $website = Website::factory()->create([
            'ssl_checked_at' => now()->subHour(),
            'ssl_expires_at' => now()->subDays(2),
            'ssl_chain_valid' => null,
            'ssl_hostname_match' => true,
        ]);

        $this->show($website)
            ->assertSee('Kedaluwarsa')
            ->assertSee('Tidak diketahui')
            ->assertSee('Cocok');
    }

    public function test_certificate_text_is_escaped(): void
    {
        $payload = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
        $website = Website::factory()->create([
            'ssl_checked_at' => now()->subHour(),
            'ssl_expires_at' => now()->addDays(60),
            'ssl_issuer' => $payload,
            'ssl_error' => $payload,
            'ssl_names' => ['<b>san</b>.example'],
            'ssl_hostname_match' => false,
            'dns_records' => ['CNAME' => ['<svg onload=alert(1)>']],
        ]);

        $html = $this->show($website)
            ->assertSee($payload)
            ->html();

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringNotContainsString('<b>san</b>', $html);
        $this->assertStringNotContainsString('<svg onload', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_panel_needs_view_permission(): void
    {
        $user = User::factory()->create(['role_id' => Role::factory()->withPermissions(['dashboard.view'])->create()->id]);

        $this->actingAs($user)->get(route('situs.show', Website::factory()->create()))->assertForbidden();
    }

    protected function show(Website $website)
    {
        return Livewire::actingAs(User::factory()->create())->test(Show::class, ['website' => $website]);
    }
}
