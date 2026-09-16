<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\User;
use App\Models\Website;
use App\Notifications\AttackAnomalyDetected;
use App\Services\Monitoring\DefacementScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * "Is something executing here that does not belong" - a different question from
 * the judol scanner's "does this page talk about gambling", and the one that
 * catches a compromise before anyone bothers to add visible spam.
 */
class DefacementScanTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
    }

    // --- What counts as foreign --------------------------------------------

    public function test_a_script_from_an_unknown_host_is_flagged(): void
    {
        $this->fakePage('<script src="https://jahat.example/load.js"></script>');

        $findings = app(DefacementScanner::class)->scan($this->website());

        $this->assertCount(1, $findings);
        $this->assertSame('jahat.example', $findings[0]['detail']);
    }

    public function test_the_sites_own_scripts_are_not_foreign(): void
    {
        $this->fakePage(
            '<script src="/js/app.js"></script>'
            .'<script src="https://situs-1.example/js/b.js"></script>'
            .'<script src="https://cdn.situs-1.example/c.js"></script>'
        );

        $this->assertSame([], app(DefacementScanner::class)->scan($this->website()));
    }

    public function test_ordinary_third_party_hosts_are_not_foreign(): void
    {
        // Flagging Google Analytics every night is how the whole feature gets
        // switched off.
        $this->fakePage(
            '<script src="https://www.googletagmanager.com/gtag/js"></script>'
            .'<script src="https://cdn.jsdelivr.net/npm/x.js"></script>'
        );

        $this->assertSame([], app(DefacementScanner::class)->scan($this->website()));
    }

    public function test_one_host_is_reported_once_however_many_tags_it_has(): void
    {
        $this->fakePage(str_repeat('<script src="https://jahat.example/a.js"></script>', 5));

        $this->assertCount(1, app(DefacementScanner::class)->scan($this->website()));
    }

    public function test_a_protocol_relative_script_is_still_resolved(): void
    {
        $this->fakePage('<script src="//jahat.example/load.js"></script>');

        $findings = app(DefacementScanner::class)->scan($this->website());

        $this->assertSame('jahat.example', $findings[0]['detail']);
    }

    // --- Hidden frames ------------------------------------------------------

    public function test_a_display_none_iframe_is_flagged(): void
    {
        $this->fakePage('<iframe src="https://jahat.example/f" style="display:none"></iframe>');

        $findings = app(DefacementScanner::class)->scan($this->website());

        $this->assertSame('Iframe tersembunyi', $findings[0]['jenis']);
    }

    public function test_a_zero_sized_iframe_is_flagged(): void
    {
        $this->fakePage('<iframe src="https://jahat.example/f" width="0" height="0"></iframe>');

        $this->assertNotEmpty(app(DefacementScanner::class)->scan($this->website()));
    }

    public function test_an_ordinary_visible_iframe_is_not_flagged(): void
    {
        // A YouTube embed is not an intrusion.
        $this->fakePage('<iframe src="https://www.youtube.com/embed/x" width="560" height="315"></iframe>');

        $this->assertSame([], app(DefacementScanner::class)->scan($this->website()));
    }

    // --- Obfuscated code ----------------------------------------------------

    public function test_an_obfuscated_eval_is_flagged(): void
    {
        $this->fakePage('<script>eval(atob("ZG9jdW1lbnQ="));</script>');

        $findings = app(DefacementScanner::class)->scan($this->website());

        $this->assertSame('Pola kode mencurigakan', $findings[0]['jenis']);
    }

    public function test_a_miner_signature_is_flagged(): void
    {
        $this->fakePage('<script src="https://situs-1.example/coinhive.min.js"></script>');

        $this->assertNotEmpty(app(DefacementScanner::class)->scan($this->website()));
    }

    public function test_a_clean_page_produces_nothing(): void
    {
        $this->fakePage('<h1>Selamat datang</h1><script src="/js/app.js"></script>');

        $this->assertSame([], app(DefacementScanner::class)->scan($this->website()));
    }

    public function test_an_unreachable_site_produces_nothing(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertSame([], app(DefacementScanner::class)->scan($this->website()));
    }

    public function test_an_internal_address_is_never_fetched(): void
    {
        Http::fake();

        $this->assertSame([], app(DefacementScanner::class)->scan(
            Website::factory()->create(['url' => 'http://10.0.0.5'])
        ));

        Http::assertNothingSent();
    }

    // --- The command --------------------------------------------------------

    public function test_the_command_raises_one_incident_and_alerts(): void
    {
        Notification::fake();
        $this->fakePage('<script src="https://jahat.example/load.js"></script>');
        $this->website();

        $this->artisan('websites:scan-defacement')->assertSuccessful();

        $incident = Incident::where('type', 'attack')->firstOrFail();

        $this->assertSame('foreign_script', $incident->meta['reason']);
        $this->assertSame('jahat.example', $incident->meta['temuan'][0]['detail']);
        Notification::assertSentTo($this->admin, AttackAnomalyDetected::class);
    }

    public function test_the_same_injection_does_not_reopen_every_night(): void
    {
        Notification::fake();
        $this->fakePage('<script src="https://jahat.example/load.js"></script>');
        $this->website();

        $this->artisan('websites:scan-defacement');
        $this->artisan('websites:scan-defacement');

        // The loader is still there tomorrow; a fresh incident each night would
        // bury the site that just got hit.
        $this->assertSame(1, Incident::where('type', 'attack')->count());
    }

    public function test_the_command_skips_sites_under_maintenance(): void
    {
        Notification::fake();
        $this->fakePage('<script src="https://jahat.example/load.js"></script>');
        $website = $this->website();

        MaintenanceWindow::create([
            'website_id' => $website->id,
            'reason' => 'Migrasi server',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->artisan('websites:scan-defacement')->assertSuccessful();

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_the_incident_detail_names_each_finding(): void
    {
        Notification::fake();
        $this->fakePage('<script src="https://jahat.example/load.js"></script><iframe width="0" height="0"></iframe>');
        $this->website();

        $this->artisan('websites:scan-defacement');

        $lines = Incident::where('type', 'attack')->firstOrFail()->detailLines();

        $this->assertSame('Kode asing di halaman depan', $lines['Indikasi']);
        $this->assertArrayHasKey('Skrip dari domain asing #1', $lines);
    }

    // --- Helpers ------------------------------------------------------------

    protected function website(): Website
    {
        return Website::factory()->create(['url' => 'https://situs-1.example']);
    }

    protected function fakePage(string $body): void
    {
        Http::fake(['*' => Http::response("<html><body>{$body}</body></html>", 200)]);
    }
}
