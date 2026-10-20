<?php

namespace Tests\Feature;

use App\Livewire\Defacement\Index;
use App\Models\AuditLog;
use App\Models\DefacementFinding;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\PageSnapshot;
use App\Models\User;
use App\Models\Website;
use App\Notifications\AttackAnomalyDetected;
use App\Services\Monitoring\DefacementSettings;
use App\Services\Monitoring\JudolScanner;
use App\Services\Monitoring\PageTextExtractor;
use App\Services\Monitoring\TextNormalizer;
use App\Support\ResponseSizeLimit;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Content defacement: a page judged against its own baseline - a defacer
 * signature, most of it replaced, or shrunk to nearly nothing.
 */
class DefacementDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected const NEWS = [
        'Bupati meresmikan jembatan penghubung dua kecamatan di wilayah utara.',
        'Dinas Kesehatan menggelar vaksinasi gratis di seluruh puskesmas kabupaten.',
        'Pendaftaran peserta didik baru jenjang SMP dibuka mulai bulan depan.',
        'Festival budaya daerah menampilkan tari tradisional dari sepuluh desa.',
        'Pemerintah daerah menyalurkan bantuan bibit padi kepada kelompok tani.',
        'Layanan perizinan usaha kini dapat diajukan melalui aplikasi daring.',
        'Perpustakaan daerah memperpanjang jam layanan hingga pukul delapan malam.',
        'Program bedah rumah menyasar seratus keluarga kurang mampu tahun ini.',
        'Satpol PP menertibkan reklame tanpa izin di sepanjang jalan protokol.',
        'Lomba kebersihan antarkelurahan diumumkan pemenangnya pekan lalu.',
    ];

    protected User $admin;

    protected Website $website;

    protected string $homepage = '';

    protected int $status = 200;

    /** When set, the homepage body is cut at this many bytes, as the size limit would. */
    protected ?int $cutAt = null;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = User::factory()->create();
        $this->website = Website::factory()->create(['url' => 'https://pemda.example', 'name' => 'Pemda']);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '/';

            if ($path === '/' && $this->cutAt !== null) {
                $sink = new ResponseSizeLimit($this->cutAt);
                $sink->write($this->homepage);

                return Create::promiseFor(new PsrResponse($this->status, [], $sink));
            }

            return $path === '/' ? Http::response($this->homepage, $this->status) : Http::response('', 404);
        });
    }

    // --- The three PM examples ---------------------------------------------

    public function test_a_normal_homepage_produces_no_finding(): void
    {
        $this->serve($this->normal());
        $this->scan();
        $this->scan();

        $this->assertSame(0, DefacementFinding::count());
        $this->assertSame(0, Incident::count());
    }

    public function test_hacked_by_raises_one_finding_and_one_incident(): void
    {
        $this->serve($this->normal());
        $this->scan();

        $this->serve('<html><head><title>Hacked by X</title></head><body><h1>Hacked by X</h1><p>Your security is low. Greetz to all crew.</p></body></html>');
        $this->scan();
        $this->scan();

        $finding = DefacementFinding::sole();
        $incident = Incident::sole();

        $this->assertSame('open', $finding->status);
        $this->assertSame('content', $finding->kind);
        $this->assertSame('signature', $finding->reasons[0]['code']);
        $this->assertSame('attack', $incident->type);
        $this->assertSame('defacement', $incident->meta['reason']);
        $this->assertSame('critical', $incident->severity);
        $this->assertContains('https://pemda.example', $incident->meta['urls']);
        $this->assertStringContainsString('Bupati meresmikan', $incident->meta['before']);
        $this->assertStringContainsString('Hacked by X', $incident->meta['after']);
        $this->assertSame($incident->id, $finding->incident_id);
        Notification::assertSentToTimes($this->admin, AttackAnomalyDetected::class, 1);
    }

    public function test_replacing_one_news_item_is_not_defacement(): void
    {
        $this->serve($this->normal());
        $this->scan();

        $news = self::NEWS;
        $news[0] = 'Gubernur meninjau pembangunan pasar rakyat di pusat kota hari ini.';
        $this->serve($this->normal(news: $news));
        $this->scan();

        $this->assertSame(0, DefacementFinding::count());
    }

    // --- Obfuscated signatures ---------------------------------------------

    public function test_an_obfuscated_signature_is_still_recognised(): void
    {
        $this->serve($this->normal());
        $this->scan();

        $this->serve($this->normal(extra: '<p>h4ck3d by ѕhadow team</p>'));
        $this->scan();

        $this->assertSame('signature', DefacementFinding::sole()->reasons[0]['code']);
    }

    public function test_a_signature_already_in_the_baseline_does_not_trigger(): void
    {
        // A page that legitimately quotes "hacked by": reported on first sight (no
        // baseline yet), then accepted - later edits around it stay quiet.
        $article = '<p>Artikel: situs dinas lain pernah hacked by kelompok tak dikenal.</p>';
        $this->serve($this->normal(extra: $article));
        $this->scan();

        $finding = DefacementFinding::sole();
        Livewire::actingAs($this->admin)->test(Index::class)->call('markLegitimate', $finding->uuid, $finding->snapshot->content_hash);

        $news = self::NEWS;
        $news[0] = 'Gubernur meninjau pembangunan pasar rakyat di pusat kota hari ini.';
        $this->serve($this->normal(news: $news, extra: $article));
        $this->scan();

        $this->assertSame('legitimate', DefacementFinding::sole()->status);
    }

    // --- Replaced / shrunk + thresholds -------------------------------------

    public function test_title_and_most_content_replaced_is_defacement(): void
    {
        $this->serve($this->normal());
        $this->scan();

        $this->serve($this->replaced(7));
        $this->scan();

        $finding = DefacementFinding::sole();
        $this->assertContains('replaced', array_column($finding->reasons, 'code'));
        $this->assertGreaterThanOrEqual(0.6, (float) $finding->change_ratio);
    }

    public function test_the_replace_threshold_is_configurable(): void
    {
        app(DefacementSettings::class)->save(['replace_percent' => 80, 'shrink_percent' => 85, 'min_baseline_chars' => 300]);

        $this->serve($this->normal());
        $this->scan();
        $this->serve($this->replaced(7));
        $this->scan();

        $this->assertSame(0, DefacementFinding::count());
    }

    public function test_a_page_shrunk_to_almost_nothing_is_defacement(): void
    {
        $this->serve($this->normal());
        $this->scan();

        $this->serve('<html><head><title>Portal Pemda</title></head><body><img src="/x.jpg"><p>Bye.</p></body></html>');
        $this->scan();

        $this->assertContains('shrink', array_column(DefacementFinding::sole()->reasons, 'code'));
    }

    public function test_the_shrink_threshold_is_configurable(): void
    {
        $this->serve($this->normal());
        $this->scan();

        // Keep 2 of 10 items (~80% shrink): under the default 85% it is not "shrunk";
        // lowering the threshold to 70% makes it one.
        app(DefacementSettings::class)->save(['replace_percent' => 95, 'shrink_percent' => 70, 'min_baseline_chars' => 300]);
        $this->serve($this->normal(news: array_slice(self::NEWS, 0, 2)));
        $this->scan();

        $this->assertContains('shrink', array_column(DefacementFinding::sole()->reasons, 'code'));
    }

    public function test_a_maintenance_placeholder_is_not_reported_as_shrunk(): void
    {
        $this->serve($this->normal());
        $this->scan();

        $this->serve('<html><head><title>Portal Resmi Pemerintah Kabupaten</title></head><body><p>Situs sedang dalam perbaikan.</p></body></html>');
        $this->scan();

        $codes = DefacementFinding::query()->get()->flatMap(fn ($f) => array_column($f->reasons, 'code'))->all();
        $this->assertNotContains('shrink', $codes);
    }

    public function test_a_maintenance_placeholder_is_not_defacement_at_all(): void
    {
        // benign_patterns exist so "sedang dalam perbaikan" does not page anyone; a
        // placeholder replaces 100% of the text, so the replaced rule must honour them too.
        $this->serve($this->normal());
        $this->scan();

        $this->serve('<html><head><title>Portal Resmi Pemerintah Kabupaten</title></head><body><p>Situs sedang dalam perbaikan.</p></body></html>');
        $this->scan();

        $this->assertSame(0, DefacementFinding::count());
        $this->assertSame(0, Incident::count());
    }

    public function test_a_long_defaced_page_mentioning_maintenance_is_still_replaced(): void
    {
        // >3 blocks and >=300 chars: not a "small placeholder", so the benign word must not shield it.
        $this->serve($this->normal());
        $this->scan();

        $this->serve($this->normal(news: [
            'Website ini sedang maintenance karena admin kalian terlalu malas memperbaiki celah keamanan.',
            'Kami sudah masuk ke server kalian sejak minggu lalu dan membaca seluruh isi basis data.',
            'Semua akun pegawai sudah kami salin, silakan ganti kata sandi sebelum terlambat.',
            'Pesan untuk pengelola: perbarui perangkat lunak dan tutup panel admin dari publik.',
            'Sampai jumpa di target berikutnya, salam dari kelompok penjelajah dunia maya.',
        ]));
        $this->scan();

        $finding = DefacementFinding::sole();
        $this->assertContains('replaced', array_column($finding->reasons, 'code'));
        $this->assertSame('critical', Incident::sole()->severity);
    }

    public function test_a_few_long_blocks_mentioning_maintenance_are_still_replaced(): void
    {
        // Only 2 blocks but ~600 chars of new text: that is not a small placeholder either.
        $this->serve($this->normal());
        $this->scan();

        $long = 'Website ini sedang maintenance karena admin kalian terlalu malas memperbaiki celah keamanan. '
            .'Kami sudah masuk ke server kalian sejak minggu lalu dan membaca seluruh isi basis data. '
            .'Semua akun pegawai sudah kami salin, silakan ganti kata sandi sebelum terlambat. ';
        $this->serve('<html><head><title>Portal Resmi Pemerintah Kabupaten</title></head><body><div>'.$long.'</div><div>'
            .'Pesan untuk pengelola: perbarui perangkat lunak dan tutup panel admin dari publik. '
            .'Sampai jumpa di target berikutnya, salam dari kelompok penjelajah dunia maya yang tidak pernah tidur.</div></body></html>');
        $this->scan();

        $this->assertContains('replaced', array_column(DefacementFinding::sole()->reasons, 'code'));
    }

    public function test_a_small_placeholder_with_a_strong_signature_is_still_defacement(): void
    {
        $this->serve($this->normal());
        $this->scan();

        $this->serve('<html><head><title>Portal Resmi Pemerintah Kabupaten</title></head><body><p>Situs sedang dalam perbaikan. Hacked by X</p></body></html>');
        $this->scan();

        $finding = DefacementFinding::sole();
        $codes = array_column($finding->reasons, 'code');
        $this->assertContains('signature', $codes);
        $this->assertNotContains('replaced', $codes);
        $this->assertSame('critical', Incident::sole()->severity);
        Notification::assertSentToTimes($this->admin, AttackAnomalyDetected::class, 1);
    }

    public function test_a_coming_soon_placeholder_with_a_new_title_is_not_defacement(): void
    {
        $this->serve($this->normal());
        $this->scan();

        $this->serve('<html><head><title>Coming Soon</title></head><body><h1>Coming soon</h1><p>Situs baru segera hadir.</p></body></html>');
        $this->scan();

        $this->assertSame(0, DefacementFinding::count());
    }

    // --- Recovery, legitimate, failures, skips -----------------------------

    public function test_a_page_back_to_normal_recovers_and_resolves_the_incident(): void
    {
        $this->serve($this->normal());
        $this->scan();
        $this->serve('<html><head><title>Hacked by X</title></head><body><h1>Hacked by X</h1></body></html>');
        $this->scan();

        $this->serve($this->normal());
        $this->scan();

        $this->assertSame('recovered', DefacementFinding::sole()->status);
        $this->assertSame('resolved', Incident::sole()->status);
    }

    public function test_marking_a_redesign_legitimate_makes_it_the_new_baseline(): void
    {
        $this->serve($this->normal());
        $this->scan();
        $this->serve($this->replaced(7));
        $this->scan();
        $finding = DefacementFinding::sole();

        Livewire::actingAs($this->admin)
            ->test(Index::class)
            ->call('markLegitimate', $finding->uuid, $finding->snapshot->content_hash)
            ->assertHasNoErrors();

        $this->assertSame('legitimate', $finding->fresh()->status);
        $this->assertSame($this->admin->id, $finding->fresh()->resolved_by);
        $this->assertSame('resolved', Incident::sole()->status);
        $this->assertTrue(AuditLog::where('event', 'defacement.ditandai-sah')->exists());

        // The redesign stays; the next scans must stay quiet.
        $this->scan();
        $this->scan();

        $this->assertSame('legitimate', $finding->fresh()->status);
        $this->assertSame(1, Incident::count());
    }

    public function test_marking_resolved_closes_the_incident(): void
    {
        $this->serve($this->normal());
        $this->scan();
        $this->serve('<html><head><title>Hacked by X</title></head><body><h1>Hacked by X</h1></body></html>');
        $this->scan();

        Livewire::actingAs($this->admin)
            ->test(Index::class)
            ->call('markResolved', DefacementFinding::sole()->uuid);

        $this->assertSame('resolved', DefacementFinding::sole()->status);
        $this->assertSame('resolved', Incident::sole()->status);
        $this->assertTrue(AuditLog::where('event', 'defacement.ditandai-selesai')->exists());
    }

    public function test_thresholds_are_saved_from_the_page_and_validated(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Index::class)
            ->set('replacePercent', 10)
            ->call('saveThresholds')
            ->assertHasErrors(['replacePercent'])
            ->set('replacePercent', 70)
            ->set('shrinkPercent', 90)
            ->set('minBaselineChars', 500)
            ->call('saveThresholds')
            ->assertHasNoErrors();

        $settings = app(DefacementSettings::class);
        $this->assertSame(70, $settings->replacePercent());
        $this->assertSame(90, $settings->shrinkPercent());
        $this->assertSame(500, $settings->minBaselineChars());
    }

    public function test_a_failed_fetch_is_never_defacement(): void
    {
        $this->serve($this->normal());
        $this->scan();

        foreach ([500, 403] as $status) {
            $this->serve('', $status);
            $this->scan();
        }

        $this->serve('<html><head><title>Just a moment...</title></head><body><p>Checking your browser before accessing pemda.example.</p></body></html>');
        $this->scan();

        $this->assertSame(0, DefacementFinding::count());
        $this->assertSame(0, Incident::count());
    }

    public function test_sites_under_maintenance_or_inactive_are_skipped(): void
    {
        $this->serve($this->normal());
        $this->scan();

        MaintenanceWindow::create([
            'website_id' => $this->website->id,
            'reason' => 'Migrasi',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);
        $this->serve('<html><head><title>Hacked by X</title></head><body><h1>Hacked by X</h1></body></html>');
        $this->scan();

        $this->website->update(['is_active' => false]);
        MaintenanceWindow::query()->delete();
        $this->artisan('websites:scan-defacement')->assertSuccessful();

        $this->assertSame(0, DefacementFinding::count());
    }

    // --- Crawl path + injection --------------------------------------------

    public function test_the_judol_crawl_detects_defacement_too(): void
    {
        $this->serve($this->normal());
        (new JudolScanner)->scan($this->website->fresh());
        $this->travel(7)->hours();

        $this->serve('<html><head><title>Hacked by X</title></head><body><h1>Hacked by X</h1></body></html>');
        (new JudolScanner)->scan($this->website->fresh());

        $this->assertSame(1, DefacementFinding::count());
        $this->assertSame('defacement', Incident::sole()->meta['reason']);
    }

    public function test_the_command_and_the_crawl_share_one_finding_per_page(): void
    {
        $this->serve($this->normal());
        $this->scan();
        $this->serve('<html><head><title>Hacked by X</title></head><body><h1>Hacked by X</h1></body></html>');
        $this->scan();
        (new JudolScanner)->scan($this->website->fresh());

        $this->assertSame(1, DefacementFinding::count());
        $this->assertSame(1, Incident::count());
    }

    public function test_injected_code_is_listed_and_auto_resolves_once_clean(): void
    {
        $this->serve($this->normal(extra: '<script src="https://jahat.example/load.js"></script>'));
        $this->scan();

        $finding = DefacementFinding::where('kind', 'injection')->sole();
        $this->assertSame('open', $finding->status);
        $this->assertSame('foreign_script', Incident::sole()->meta['reason']);

        $this->serve($this->normal());
        $this->scan();

        $this->assertSame('recovered', $finding->fresh()->status);
        $this->assertSame('resolved', Incident::sole()->status);
    }

    public function test_the_incident_detail_shows_url_reason_and_before_after(): void
    {
        $this->serve($this->normal());
        $this->scan();
        $this->serve('<html><head><title>Hacked by X</title></head><body><h1>Hacked by X</h1></body></html>');
        $this->scan();

        $lines = implode("\n", array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($lines = Incident::sole()->detailLines()), $lines));

        $this->assertStringContainsString('https://pemda.example', $lines);
        $this->assertStringContainsString('Hacked by X', $lines);
        $this->assertStringContainsString('Bupati meresmikan', $lines);
    }

    // --- Helpers ------------------------------------------------------------

    // --- Security fix #2: challenge pages ----------------------------------

    public function test_only_a_real_challenge_page_is_skipped(): void
    {
        $extract = fn (string $html) => app(PageTextExtractor::class)->extract($html);

        $this->assertTrue(TextNormalizer::isChallengePage($extract('<html><head><title>Just a moment...</title></head><body><p>Checking your browser before accessing.</p><p>Ray ID: 8f2a</p></body></html>')));
        // A "Ray ID" footer alone is not a challenge page.
        $this->assertFalse(TextNormalizer::isChallengePage($extract('<html><head><title>Portal</title></head><body><h1>HACKED BY X</h1><small>Ray ID: 8f2a</small></body></html>')));
        // Two patterns in the body but none in the title.
        $this->assertFalse(TextNormalizer::isChallengePage($extract('<html><head><title>Portal</title></head><body><p>Just a moment, Ray ID 8f2a</p></body></html>')));
    }

    public function test_a_deface_page_with_a_ray_id_footer_is_still_reported(): void
    {
        $this->serve($this->normal());
        $this->scan();

        $this->serve('<html><head><title>Portal Resmi Pemerintah Kabupaten</title></head><body><h1>HACKED BY X</h1><p>Greetz all</p><small>Ray ID: 8f2a</small></body></html>');
        $this->scan();

        $this->assertSame('open', DefacementFinding::sole()->status);
    }

    public function test_a_challenge_page_carrying_a_strong_signature_is_still_reported(): void
    {
        $this->serve($this->normal());
        $this->scan();

        $this->serve('<html><head><title>Just a moment...</title></head><body><p>Checking your browser before accessing. Hacked by X</p></body></html>');
        $this->scan();

        $this->assertSame('open', DefacementFinding::sole()->status);
    }

    // --- Security fix #5: baseline drift -------------------------------------

    public function test_a_small_clean_change_moves_the_baseline(): void
    {
        $this->serve($this->normal());
        $this->scan();
        $before = PageSnapshot::sole()->baseline_hash;

        $this->serve($this->normal(news: $this->sameTitleReplaced(1)));
        $this->scan();

        $this->assertNotSame($before, PageSnapshot::sole()->baseline_hash);
        $this->assertSame(0, DefacementFinding::count());
    }

    public function test_content_replaced_over_several_clean_crawls_is_still_caught(): void
    {
        $this->serve($this->normal());
        $this->scan();
        $baseline = PageSnapshot::sole()->baseline_hash;

        // Half the page replaced, title kept: below every threshold, but too large to absorb.
        $this->serve($this->normal(news: $this->sameTitleReplaced(5)));
        $this->scan();
        $this->assertSame(0, DefacementFinding::count());
        $this->assertSame($baseline, PageSnapshot::sole()->baseline_hash);

        // The rest replaced: judged against the original baseline, not the half-way page.
        $this->serve($this->normal(news: $this->sameTitleReplaced(10)));
        $this->scan();

        $this->assertSame('open', DefacementFinding::sole()->status);
    }

    // --- Final re-test -------------------------------------------------------

    public function test_a_challenge_title_with_a_single_pattern_is_not_a_challenge_page(): void
    {
        $extract = fn (string $html) => app(PageTextExtractor::class)->extract($html);

        $this->assertFalse(TextNormalizer::isChallengePage($extract('<html><head><title>Just a moment...</title></head><body><h1>HACKED BY X</h1></body></html>')));
        $this->assertTrue(TextNormalizer::isChallengePage($extract('<html><head><title>Attention Required!</title></head><body><p>Please enable cookies.</p></body></html>')));
    }

    public function test_a_real_challenge_page_neither_reports_nor_moves_the_baseline(): void
    {
        $this->serve($this->normal());
        $this->scan();
        $baseline = PageSnapshot::sole()->baseline_hash;

        $this->serve('<html><head><title>Just a moment...</title></head><body><p>Checking your browser before accessing.</p><p>Ray ID: 8f2a</p></body></html>');
        $this->scan();

        $this->assertSame(0, DefacementFinding::count());
        $this->assertSame($baseline, PageSnapshot::sole()->baseline_hash);
    }

    public function test_mark_legitimate_with_a_stale_or_missing_hash_is_refused(): void
    {
        $this->serve($this->normal());
        $this->scan();
        $this->serve($this->replaced(7));
        $this->scan();
        $finding = DefacementFinding::sole();
        $baseline = PageSnapshot::sole()->baseline_hash;

        foreach ([sha1('halaman lain'), ''] as $hash) {
            Livewire::actingAs($this->admin)->test(Index::class)->call('markLegitimate', $finding->uuid, $hash);
        }

        $this->assertSame('open', $finding->fresh()->status);
        $this->assertSame($baseline, PageSnapshot::sole()->baseline_hash);
        $this->assertSame('open', Incident::sole()->status);
        $this->assertFalse(AuditLog::where('event', 'defacement.ditandai-sah')->exists());
    }

    public function test_mark_legitimate_on_a_closed_finding_is_refused(): void
    {
        $this->serve($this->normal());
        $this->scan();
        $this->serve($this->replaced(7));
        $this->scan();
        $finding = DefacementFinding::sole();
        $finding->update(['status' => 'resolved']);
        $baseline = PageSnapshot::sole()->baseline_hash;

        Livewire::actingAs($this->admin)->test(Index::class)->call('markLegitimate', $finding->uuid, $finding->snapshot->content_hash);

        $this->assertSame('resolved', $finding->fresh()->status);
        $this->assertSame($baseline, PageSnapshot::sole()->baseline_hash);
        $this->assertFalse(AuditLog::where('event', 'defacement.ditandai-sah')->exists());
    }

    /** @return array<int, string> the first $count news items replaced, title untouched */
    // --- Security fix 2: a page not fully read --------------------------------

    public function test_a_page_nested_past_the_depth_limit_neither_moves_the_baseline_nor_clears_an_injection(): void
    {
        $script = '<script src="https://jahat.example/load.js"></script>';
        $this->serve($this->normal(extra: $script));
        $this->scan();
        $baseline = PageSnapshot::sole()->baseline_hash;

        // The script sits past nesting level 256 - out of the DOM, still run by a browser.
        $this->serve($this->normal(news: $this->sameTitleReplaced(1), extra: str_repeat('<div>', 300).$script));
        $this->scan();

        $this->assertSame('open', DefacementFinding::where('kind', 'injection')->sole()->status);
        $this->assertSame('open', Incident::sole()->status);
        $this->assertSame($baseline, PageSnapshot::sole()->baseline_hash);
    }

    public function test_a_body_cut_at_the_size_limit_neither_moves_the_baseline_nor_clears_an_injection(): void
    {
        $script = '<script src="https://jahat.example/load.js"></script>';
        $this->serve($this->normal(extra: $script));
        $this->scan();
        $baseline = PageSnapshot::sole()->baseline_hash;

        $html = $this->normal(news: $this->sameTitleReplaced(1), extra: $script);
        $this->cutAt = strpos($html, '<script');
        $this->serve($html);
        $this->scan();

        $this->assertSame('open', DefacementFinding::where('kind', 'injection')->sole()->status);
        $this->assertSame('open', Incident::sole()->status);
        $this->assertSame($baseline, PageSnapshot::sole()->baseline_hash);
    }

    protected function sameTitleReplaced(int $count): array
    {
        $news = self::NEWS;

        for ($i = 0; $i < $count; $i++) {
            $news[$i] = "Konten pengganti nomor {$i} yang sama sekali berbeda dari berita sebelumnya di halaman.";
        }

        return $news;
    }

    protected function scan(): void
    {
        $this->artisan('websites:scan-defacement')->assertSuccessful();
        $this->travel(1)->days();
    }

    protected function serve(string $html, int $status = 200): void
    {
        $this->homepage = $html;
        $this->status = $status;
    }

    /** @param  array<int, string>|null  $news */
    protected function normal(?array $news = null, string $extra = '', string $title = 'Portal Resmi Pemerintah Kabupaten'): string
    {
        $items = collect($news ?? self::NEWS)->map(fn (string $n) => "<article><p>{$n}</p></article>")->implode('');

        return "<html><head><title>{$title}</title></head><body><h1>Berita Terkini</h1>{$items}{$extra}</body></html>";
    }

    /** Title changed and the first $count news items replaced. */
    protected function replaced(int $count): string
    {
        $news = self::NEWS;

        for ($i = 0; $i < $count; $i++) {
            $news[$i] = "Konten pengganti nomor {$i} yang sama sekali berbeda dari berita sebelumnya di halaman.";
        }

        return $this->normal(news: $news, title: 'Toko Daring Murah Meriah');
    }
}
