<?php

namespace Tests\Feature;

use App\Livewire\ContentChanges\Index as ContentChangesIndex;
use App\Livewire\JudolDetections\Index as JudolIndex;
use App\Models\DefacementFinding;
use App\Models\Incident;
use App\Models\JudolFinding;
use App\Models\PageChange;
use App\Models\PageSnapshot;
use App\Models\Role;
use App\Models\User;
use App\Models\Website;
use App\Services\Monitoring\InjectionProfile;
use App\Services\Monitoring\JudolScanner;
use App\Services\Monitoring\LinkFarmDetector;
use App\Services\Monitoring\PageContentMonitor;
use App\Services\Monitoring\PageTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Acceptance: defacement optimization (ADR 0002) - dynamic noise, judol link farming, seeding, severity filter. */
class DefacementOptimizationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = 'https://www.dinas.go.id/';

    protected const NEWS = '<p>Berita hari ini tentang pelayanan publik yang terus ditingkatkan oleh pemerintah daerah.</p>'
        .'<p>Pengumuman jadwal ujian sekolah tahun ajaran baru telah terbit untuk seluruh siswa.</p>'
        .'<p>Program beasiswa daerah dibuka kembali bagi pelajar berprestasi di kabupaten.</p>';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('local');
    }

    // --- Dynamic text only -------------------------------------------------------

    public function test_dynamic_news_dates_and_tokens_alone_record_no_event_by_default(): void
    {
        $website = Website::factory()->create(['url' => self::URL]);

        $this->observe($website, $this->dynamicPage('Wisuda sekolah berlangsung meriah di aula utama.', '21 Oktober 2026 08:15', 'tok-aaa111', 'n1'));
        $this->observe($website, $this->dynamicPage('Lomba kebersihan antar kelurahan dimenangkan warga.', '22 Oktober 2026 09:40', 'tok-bbb222', 'n2'));
        $this->observe($website, $this->dynamicPage('Pasar murah digelar menjelang hari besar keagamaan.', '23 Oktober 2026 10:05', 'tok-ccc333', 'n3'));

        $this->assertSame(0, PageChange::count());
        $this->assertSame(0, DefacementFinding::count());
        $this->assertSame(0, Incident::count());
    }

    public function test_dynamic_text_changes_are_recorded_again_when_the_switch_is_on(): void
    {
        config(['monitoring.record_text_only_changes' => true]);
        $website = Website::factory()->create(['url' => self::URL]);

        $this->observe($website, $this->dynamicPage('Wisuda sekolah berlangsung meriah di aula utama.', '21 Oktober 2026', 'tok-aaa111', 'n1'));
        $this->observe($website, $this->dynamicPage('Lomba kebersihan antar kelurahan dimenangkan warga.', '22 Oktober 2026', 'tok-bbb222', 'n2'));

        $change = PageChange::sole();
        $this->assertSame('text', $change->content_kind);
        $this->assertSame('none', $change->severity);
        $this->assertSame(0, $change->risk_score);
    }

    // --- Hidden judol link ---------------------------------------------------------

    public function test_an_inserted_hidden_judol_link_is_critical_with_domain_and_keywords(): void
    {
        $website = Website::factory()->create(['url' => self::URL]);

        $this->observe($website, $this->html(self::NEWS));
        $this->observe($website, $this->html(self::NEWS.'<div style="display:none"><a href="https://situs-slot-gacor.example/daftar">Info</a></div>'));

        $change = PageChange::sole();
        $this->assertSame('critical', $change->severity);
        $this->assertSame('injection', $change->content_kind);

        $signal = collect($change->risk_signals)->firstWhere('code', 'judol_link');
        $this->assertNotNull($signal, 'judol_link signal missing');
        $this->assertSame('situs-slot-gacor.example', $signal['detail']);
        $this->assertContains('slot gacor', $signal['keywords']);
        $this->assertTrue($signal['hidden']);
    }

    public function test_the_crawl_records_a_hidden_judol_link_with_its_keyword_source_badge(): void
    {
        Http::fake(fn () => Http::response($this->html(self::NEWS
            .'<div style="position:absolute;left:-9999px"><a href="https://gacor-togel.example/slot-gacor-hari-ini">x</a></div>')));
        $website = Website::factory()->create(['url' => 'https://dinas.example']);

        (new JudolScanner)->scan($website);

        $nodes = collect(JudolFinding::query()->get()->flatMap(fn (JudolFinding $f) => $f->evidence_nodes ?? []))
            ->where('domain', 'gacor-togel.example');
        $this->assertNotEmpty($nodes, 'no evidence node for the linked domain');
        $this->assertSame(['keyword'], $nodes->pluck('link_source')->unique()->values()->all());
        $this->assertSame(['gacor-togel.example' => 'keyword'], Incident::where('type', 'judol')->sole()->meta['link_sources']);

        Livewire::actingAs($this->userWith(['deteksi-judol.view']))
            ->test(JudolIndex::class)
            ->assertSee('gacor-togel.example')
            ->assertSee('URL keyword');
    }

    // --- Keyword in text, not in a URL ------------------------------------------

    public function test_a_keyword_in_article_text_or_anchor_text_is_not_a_url_keyword_detection(): void
    {
        $body = '<p>Waspada iklan slot gacor dan bocoran rtp di media sosial, kata kepala dinas.</p>'
            .'<a href="https://berita.example/artikel/123">slot gacor marak</a>';
        $profile = $this->profile($body);

        $this->assertSame([], $this->keys($profile, 'jl|'));
        $this->assertSame([], app(LinkFarmDetector::class)->detect($profile->externalLinks)['domains']);

        $website = Website::factory()->create(['url' => self::URL]);
        $this->observe($website, $this->html(self::NEWS));
        $this->observe($website, $this->html(self::NEWS.$body));

        $signals = PageChange::query()->get()->flatMap(fn (PageChange $c) => $c->risk_signals ?? [])->pluck('code')->all();
        $this->assertNotContains('judol_link', $signals);
    }

    // --- Already infected when seeded ----------------------------------------------

    public static function infectedPages(): array
    {
        return [
            'judol script domain' => ['<script src="https://cdn-slot-gacor.example/load.js"></script>', 'foreign_script'],
            'document.write(atob(' => ['<script>document.write(atob("PGEgaHJlZj0iIj4="))</script>', 'obfuscated_js'],
        ];
    }

    #[DataProvider('infectedPages')]
    public function test_a_page_infected_at_first_sight_is_flagged(string $payload, string $code): void
    {
        $website = Website::factory()->create(['url' => self::URL]);

        $this->observe($website, $this->html(self::NEWS.$payload));

        $finding = DefacementFinding::where('kind', 'injection')->sole();
        $this->assertSame('open', $finding->status);
        $this->assertContains($code, array_column($finding->reasons, 'code'));
        $this->assertSame('open', Incident::sole()->status);
    }

    #[DataProvider('infectedPages')]
    public function test_a_page_infected_when_the_risk_baseline_is_seeded_after_deploy_is_flagged(string $payload, string $code): void
    {
        $website = Website::factory()->create(['url' => self::URL]);
        $infected = $this->html(self::NEWS.$payload);

        $this->observe($website, $infected);
        // Deploy: snapshot predates the risk baseline and no finding exists yet.
        PageSnapshot::query()->update(['risk_hash' => null, 'risk_profile' => null]);
        DefacementFinding::query()->delete();
        Incident::query()->delete();

        $this->observe($website, $infected);

        $finding = DefacementFinding::where('kind', 'injection')->sole();
        $this->assertSame('open', $finding->status);
        $this->assertContains($code, array_column($finding->reasons, 'code'));
    }

    public function test_an_ordinary_display_none_dropdown_at_seed_is_not_flagged(): void
    {
        $menu = '<nav><ul class="menu"><li>Link Terkait<ul style="display:none">'
            .'<li><a href="https://kemdikbud.example/">Kementerian</a></li>'
            .'<li><a href="https://provinsi.example/">Provinsi</a></li>'
            .'<li><a href="https://lpse.example/">LPSE</a></li>'
            .'<li><a href="https://jdih.example/">JDIH</a></li></ul></li></ul></nav>';
        $website = Website::factory()->create(['url' => self::URL]);

        $this->observe($website, $this->html(self::NEWS.$menu));
        PageSnapshot::query()->update(['risk_hash' => null, 'risk_profile' => null]);
        $this->observe($website, $this->html(self::NEWS.$menu));

        $this->assertSame(0, DefacementFinding::count());
        $this->assertSame(0, Incident::count());
        $this->assertSame(0, PageChange::count());
    }

    // --- Livewire severity filter -------------------------------------------------

    public function test_the_list_shows_only_warning_and_critical_by_default(): void
    {
        $this->changesBySeverity();

        $this->assertSame(['critical', 'warning'], $this->listed());
    }

    public function test_the_list_severity_filter_all_single_and_legacy(): void
    {
        $this->changesBySeverity();

        $this->assertSame(['critical', 'info', 'legacy', 'none', 'warning'], $this->listed('all'));
        $this->assertSame(['critical'], $this->listed('critical'));
        $this->assertSame(['info'], $this->listed('info'));
        $this->assertSame(['none'], $this->listed('none'));
        $this->assertSame(['legacy'], $this->listed('legacy'));
    }

    public function test_an_invalid_severity_falls_back_to_important_with_an_error(): void
    {
        $this->changesBySeverity();

        Livewire::actingAs($this->userWith(['perubahan-konten.view']))
            ->test(ContentChangesIndex::class)
            ->set('severity', 'bogus')
            ->assertHasErrors(['severity']);

        $this->assertSame(['critical', 'warning'], $this->listed('bogus'));
    }

    public function test_deleting_with_the_default_filter_keeps_unimportant_and_legacy_events(): void
    {
        $this->changesBySeverity();

        Livewire::actingAs($this->userWith(['perubahan-konten.view', 'perubahan-konten.delete']))
            ->test(ContentChangesIndex::class)
            ->call('deleteFiltered');

        $this->assertEqualsCanonicalizing(['info', 'none', null], PageChange::pluck('severity')->all());
    }

    public function test_the_list_needs_view_permission(): void
    {
        Livewire::actingAs($this->userWith([]))
            ->test(ContentChangesIndex::class)
            ->assertForbidden();
    }

    // --- Helpers --------------------------------------------------------------------

    protected function changesBySeverity(): void
    {
        $website = Website::factory()->create();

        foreach (['critical', 'warning', 'info', 'none', null] as $severity) {
            PageChange::factory()->create([
                'website_id' => $website->id,
                'url' => 'https://dinas.example/'.($severity ?? 'legacy'),
                'severity' => $severity,
                'risk_score' => $severity === null ? null : 0,
                'detected_at' => now(),
            ]);
        }
    }

    /** @return array<int, string> */
    protected function listed(?string $severity = null): array
    {
        $test = Livewire::actingAs($this->userWith(['perubahan-konten.view']))->test(ContentChangesIndex::class);

        if ($severity !== null) {
            $test->set('severity', $severity);
        }

        return $test->viewData('changes')
            ->map(fn (PageChange $c) => basename($c->url))
            ->sort()
            ->values()
            ->all();
    }

    protected function dynamicPage(string $headline, string $date, string $token, string $nonce): string
    {
        return $this->html(
            self::NEWS
            ."<aside><h3>Berita Terkini</h3><p>{$headline}</p><time>{$date}</time></aside>"
            ."<form><input type=\"hidden\" name=\"_token\" value=\"{$token}\"></form>"
            ."<script nonce=\"{$nonce}\">window.csrf = \"{$token}\";</script>"
            ."<script src=\"/assets/app.js?v={$token}\"></script>"
            .'<p>Pengunjung hari ini: '.crc32($token).'</p>',
            "Portal Dinas - {$date}",
        );
    }

    protected function html(string $body, string $title = 'Portal Dinas'): string
    {
        return "<html><head><title>{$title}</title></head><body>{$body}</body></html>";
    }

    protected function profile(string $body): InjectionProfile
    {
        return app(PageTextExtractor::class)->extractAt($this->html($body), self::URL, true, self::URL)->risk;
    }

    /** @return array<int, string> */
    protected function keys(InjectionProfile $profile, string $prefix): array
    {
        return array_values(array_filter(array_keys($profile->items), fn (string $key) => str_starts_with($key, $prefix)));
    }

    protected function observe(Website $website, string $html, string $url = self::URL): void
    {
        $text = app(PageTextExtractor::class)->extractAt($html, $url, true, $url);

        (new PageContentMonitor)->observe($website->fresh(), $url, $text, response: ['body' => $html, 'status' => 200, 'final_url' => $url, 'redirects' => 0]);
        $this->travel(1)->hours();
    }

    protected function userWith(array $permissions): User
    {
        return User::factory()->create(['role_id' => Role::factory()->withPermissions($permissions)->create()->id]);
    }
}
