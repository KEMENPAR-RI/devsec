<?php

namespace Tests\Feature;

use App\Models\DefacementFinding;
use App\Models\Incident;
use App\Models\PageChange;
use App\Models\PageSnapshot;
use App\Models\User;
use App\Models\Website;
use App\Services\Monitoring\DefacementMonitor;
use App\Services\Monitoring\InjectionProfile;
use App\Services\Monitoring\InjectionRiskEvaluator;
use App\Services\Monitoring\PageContentMonitor;
use App\Services\Monitoring\PageTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Structural risk fingerprint (ADR 0002): keys, differential scoring, events and baseline. */
class InjectionProfilerTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = 'https://www.situs.go.id/';

    protected const NEWS = '<p>Berita hari ini tentang pelayanan publik yang terus ditingkatkan oleh pemerintah daerah.</p>';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('local');
    }

    // --- Profile keys ---------------------------------------------------------

    public function test_own_subdomains_and_trusted_hosts_are_not_foreign_scripts(): void
    {
        $profile = $this->profile(
            '<script src="https://cdn.situs.go.id/app.js"></script>'
            .'<script src="https://www.googletagmanager.com/gtag/js?id=1"></script>'
            .'<script src="https://jahat.example/load.js?v=123"></script>'
        );

        $this->assertSame(['xs|jahat.example/load.js'], $this->keys($profile, 'xs|'));
    }

    public function test_nonces_cache_busters_and_dynamic_titles_do_not_change_the_hash(): void
    {
        $a = $this->profile('<script nonce="aaa">eval(atob("x"))</script><script src="https://jahat.example/a.js?v=1"></script>', 'Portal 12 Oktober 2026');
        $b = $this->profile('<script nonce="bbb">eval(atob("y"));</script><script src="https://jahat.example/a.js?v=2"></script>', 'Portal 13 Oktober 2026');

        $this->assertSame($a->hash(), $b->hash());
    }

    public function test_inline_obfuscation_redirect_and_cloaking_are_keyed_by_features(): void
    {
        $profile = $this->profile(
            '<script>eval(atob("ZG9jdW1lbnQ="))</script>'
            .'<script>if (/googlebot/i.test(navigator.userAgent)) { location.href = "https://judi.example/x"; }</script>'
            .'<script>document.write("<b>" + new Date().getFullYear() + "</b>")</script>'
        );

        $codes = array_column($profile->items, 'code');

        $this->assertContains('obfuscated_js', $codes);
        $this->assertContains('cloaking', $codes);
        $this->assertContains('document_write', $codes);
    }

    public function test_links_hidden_by_a_class_rule_form_a_hidden_block(): void
    {
        $profile = $this->profile(
            '<style>.promo { position:absolute; left:-9999px }</style><div class="promo">'
            .'<a href="https://a.example/">a</a><a href="https://b.example/">b</a><a href="https://c.example/">c</a></div>'
            .'<a href="https://berita.example/x">sumber</a>'
        );

        $this->assertSame(['hblk|div|offscreen'], $this->keys($profile, 'hblk|'));
        $this->assertCount(3, $this->keys($profile, 'hlnk|'));
        $this->assertSame(['xd|berita.example'], $this->keys($profile, 'xd|'));
        $this->assertSame(3, $profile->stats['hidden_external_links']);
    }

    public function test_a_judol_keyword_in_a_link_url_is_an_absolute_key(): void
    {
        $profile = $this->profile('<a href="https://situs-slot-gacor.example/daftar">x</a>');

        $this->assertSame('judol_link', $profile->items['jl|situs-slot-gacor.example']['code']);
        $this->assertContains('slot gacor', $profile->items['jl|situs-slot-gacor.example']['keywords']);
    }

    public function test_a_body_over_the_size_limit_is_still_profiled_partially(): void
    {
        config(['defacement.injection.max_html_bytes' => 100]);

        // Padding past the limit must not hide a judol link.
        $risk = $this->profile(str_repeat(self::NEWS, 5).'<a href="https://slot-gacor.example/">s</a>');

        $this->assertFalse($risk->complete);
        $this->assertArrayHasKey('np|oversize', $risk->items);
        $this->assertArrayHasKey('jl|slot-gacor.example', $risk->items);

        // Grown past the limit since the baseline: a warning on its own.
        config(['defacement.injection.max_html_bytes' => 1572864]);
        $baseline = $this->profile('<a href="https://berita.example/">x</a>')->toBaseline();
        config(['defacement.injection.max_html_bytes' => 100]);
        $verdict = app(InjectionRiskEvaluator::class)->evaluate($baseline, $this->profile(str_repeat(self::NEWS, 5).'<a href="https://berita.example/">x</a>'));

        $this->assertSame('warning', $verdict['incident_severity']);
        $this->assertContains('not_profiled', array_column($verdict['signals'], 'code'));
    }

    public function test_cap_padding_cannot_push_a_payload_out_of_sight(): void
    {
        config(['defacement.injection.max_links' => 5, 'defacement.injection.max_inline_scripts' => 5]);

        // Internal / "#" links do not count toward the cap.
        $risk = $this->profile(str_repeat('<a href="#">x</a><a href="/lokal">y</a>', 50).'<a href="https://slot-gacor.example/">s</a>');
        $this->assertArrayHasKey('jl|slot-gacor.example', $risk->items);

        // Past the cap the profile says so, with weight.
        $scripts = $this->profile(str_repeat('<script>var a = 1;</script>', 10).'<script>eval(atob("eA=="))</script>');
        $verdict = app(InjectionRiskEvaluator::class)->evaluate(null, $scripts);

        $this->assertFalse($scripts->complete);
        $this->assertContains('profile_truncated', array_column($verdict['signals'], 'code'));
        $this->assertSame('warning', $verdict['incident_severity']);
    }

    public function test_a_huge_attacker_made_script_url_is_matched_in_bounded_time(): void
    {
        $src = 'https://evil.example/'.str_repeat('gacor-', 175000);
        $start = microtime(true);

        $this->profile('<script src="'.$src.'"></script><a href="'.$src.'">x</a>');

        $this->assertLessThan(1.0, microtime(true) - $start);
    }

    // --- Evaluator --------------------------------------------------------------

    public function test_without_baseline_only_absolute_keys_score(): void
    {
        $verdict = app(InjectionRiskEvaluator::class)->evaluate(null, $this->profile('<script src="https://jahat.example/a.js"></script>'));

        $this->assertSame('none', $verdict['severity']);
        $this->assertTrue($verdict['absorb']);

        $verdict = app(InjectionRiskEvaluator::class)->evaluate(null, $this->profile('<iframe src="https://jahat.example/f" style="display:none"></iframe>'));

        $this->assertSame('warning', $verdict['severity']);
        $this->assertFalse($verdict['absorb']);
    }

    public function test_new_keys_score_against_the_baseline_and_removed_ones_do_not(): void
    {
        $evaluator = app(InjectionRiskEvaluator::class);
        $baseline = $this->profile('<a href="https://berita.example/">x</a>')->toBaseline();

        $added = $evaluator->evaluate($baseline, $this->profile('<a href="https://berita.example/">x</a><script src="https://jahat.example/a.js"></script>'));
        $this->assertSame('warning', $added['severity']);
        $this->assertSame(30, $added['score']);

        $newsLink = $evaluator->evaluate($baseline, $this->profile('<a href="https://lain.example/">y</a>'));
        $this->assertSame('none', $newsLink['severity']);
        $this->assertArrayHasKey('xd|berita.example', $newsLink['removed']);
    }

    public function test_a_surge_of_new_external_domains_is_a_signal(): void
    {
        $baseline = $this->profile('<a href="https://satu.example/">x</a>')->toBaseline();
        $links = collect(range(1, 12))->map(fn (int $i) => "<a href=\"https://domain{$i}.example/\">{$i}</a>")->implode('');

        $verdict = app(InjectionRiskEvaluator::class)->evaluate($baseline, $this->profile($links));

        $this->assertContains('link_surge', array_column($verdict['signals'], 'code'));
    }

    public function test_a_judol_or_miner_script_url_and_escaped_document_write_are_absolute(): void
    {
        $evaluator = app(InjectionRiskEvaluator::class);

        $judol = $evaluator->evaluate(null, $this->profile('<script src="https://cdn.example/slot-gacor/load.js"></script>'));
        $miner = $evaluator->evaluate(null, $this->profile('<script src="https://cdn.example/coinhive.min.js"></script>'));
        $write = $evaluator->evaluate(null, $this->profile('<script>document.write(unescape("%3Cb%3E"))</script>'));
        $plain = $evaluator->evaluate(null, $this->profile('<script>var s = String.fromCharCode(72, 105);</script>'));

        $this->assertContains('foreign_script', array_column($judol['signals'], 'code'));
        $this->assertContains('cryptominer', array_column($miner['signals'], 'code'));
        $this->assertSame('obfuscated_js', $write['signals'][0]['code']);
        $this->assertSame('none', $plain['severity']);
    }

    public function test_a_display_none_menu_is_judged_only_against_a_baseline(): void
    {
        $evaluator = app(InjectionRiskEvaluator::class);
        $links = '<a href="https://a.example/">a</a><a href="https://b.example/">b</a><a href="https://c.example/">c</a>';

        $menu = $evaluator->evaluate(null, $this->profile('<ul style="display:none">'.$links.'</ul>'));
        $stealth = $evaluator->evaluate(null, $this->profile('<div style="position:absolute;left:-9999px">'.$links.'</div>'));
        $withJudol = $evaluator->evaluate(null, $this->profile('<ul style="display:none">'.$links.'<a href="https://slot-gacor.example/">s</a></ul>'));

        $this->assertSame('none', $menu['severity']);
        $this->assertSame('critical', $stealth['severity']);
        $this->assertContains('hidden_link_block', array_column($withJudol['signals'], 'code'));
        $this->assertContains('hidden_links', array_column($withJudol['signals'], 'code'));
    }

    public function test_a_judol_link_alone_does_not_freeze_the_baseline(): void
    {
        $verdict = app(InjectionRiskEvaluator::class)->evaluate(null, $this->profile('<a href="https://slot-gacor.example/">s</a>'));

        $this->assertSame('critical', $verdict['severity']);
        $this->assertSame('none', $verdict['incident_severity']);
        $this->assertTrue($verdict['absorb']);
    }

    // --- Content monitor ----------------------------------------------------------

    public function test_marking_legitimate_refuses_a_profile_that_moved_since_it_was_seen(): void
    {
        $website = Website::factory()->create(['url' => self::URL]);

        $this->observe($website, $this->html(self::NEWS));
        $this->observe($website, $this->html(self::NEWS.'<script src="https://widget.example/w.js"></script>'));
        $seen = PageSnapshot::sole()->risk_hash;

        // A miner lands before the operator clicks.
        $this->observe($website, $this->html(self::NEWS.'<script src="https://widget.example/w.js"></script><script src="https://cdn.example/coinhive.js"></script>'));
        $finding = DefacementFinding::where('kind', 'injection')->sole();

        $this->assertFalse(app(DefacementMonitor::class)->markLegitimate($finding, User::factory()->create(), $seen));
        $this->assertSame('open', $finding->fresh()->status);
        $this->assertArrayNotHasKey('xs|cdn.example/coinhive.js', PageSnapshot::sole()->risk_profile['items']);
    }

    public function test_seeding_never_clears_an_open_injection_nor_absorbs_its_code(): void
    {
        $website = Website::factory()->create(['url' => self::URL]);
        $infected = $this->html(self::NEWS.'<script src="https://jahat.example/load.js"></script>');

        $this->observe($website, $infected);
        // Deploy: a snapshot without risk baseline and a finding from the old homepage scanner.
        PageSnapshot::query()->update(['risk_hash' => null, 'risk_profile' => null]);
        $finding = DefacementFinding::create([
            'website_id' => $website->id, 'url_hash' => sha1(self::URL), 'kind' => 'injection', 'url' => self::URL,
            'reasons' => [['code' => 'foreign_script', 'detail' => 'Skrip dari domain asing: jahat.example']],
            'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->observe($website, $infected);

        $this->assertSame('open', $finding->fresh()->status);
        $this->assertContains('foreign_script', array_column($finding->fresh()->reasons, 'code'));
        $this->assertArrayNotHasKey('xs|jahat.example/load.js', PageSnapshot::sole()->risk_profile['items']);
    }

    public function test_seeding_a_clean_page_does_not_recover_a_finding(): void
    {
        $website = Website::factory()->create(['url' => self::URL]);
        $finding = DefacementFinding::create([
            'website_id' => $website->id, 'url_hash' => sha1(self::URL), 'kind' => 'injection', 'url' => self::URL,
            'reasons' => [['code' => 'foreign_script', 'detail' => 'x']], 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        // First sight: no snapshot, no baseline - absolute keys only.
        $this->observe($website, $this->html(self::NEWS.'<script src="https://jahat.example/load.js"></script>'));

        $this->assertSame('open', $finding->fresh()->status);
    }

    public function test_a_second_page_joins_the_open_injection_incident(): void
    {
        $website = Website::factory()->create(['url' => self::URL]);
        $hidden = '<div style="font-size:0"><a href="https://a.example/">a</a><a href="https://b.example/">b</a><a href="https://c.example/">c</a></div>';

        $this->observe($website, $this->html(self::NEWS.$hidden));
        $this->observe($website, $this->html(self::NEWS.$hidden), self::URL.'profil');

        $incident = Incident::sole();
        $this->assertSame([self::URL, self::URL.'profil'], $incident->meta['urls']);
        $this->assertSame('Kode asing di halaman situs', $incident->detailLines()['Indikasi']);
    }

    public function test_a_text_only_change_records_no_event_by_default(): void
    {
        $website = Website::factory()->create(['url' => self::URL]);

        $this->observe($website, $this->html(self::NEWS));
        $this->observe($website, $this->html('<p>Berita lain yang sama sekali berbeda dari sebelumnya untuk diuji.</p>'));

        $this->assertSame(0, PageChange::count());
    }

    public function test_an_injection_is_one_event_one_finding_and_recovers_once_clean(): void
    {
        $website = Website::factory()->create(['url' => self::URL]);
        $clean = $this->html(self::NEWS);
        $infected = $this->html(self::NEWS.'<div style="display:none"><a href="https://a.example/">a</a><a href="https://b.example/">b</a><a href="https://c.example/">c</a></div>');

        $this->observe($website, $clean);
        $this->observe($website, $infected);
        $this->observe($website, $infected);

        $change = PageChange::sole();
        $this->assertSame('injection', $change->content_kind);
        $this->assertSame('critical', $change->severity);
        $this->assertGreaterThanOrEqual(60, $change->risk_score);
        $this->assertContains('hidden_link_block', array_column($change->risk_signals, 'code'));

        $finding = DefacementFinding::where('kind', 'injection')->sole();
        $this->assertSame('open', $finding->status);
        $this->assertNotNull($finding->page_snapshot_id);
        $this->assertSame('open', Incident::sole()->status);

        $this->observe($website, $clean);

        $this->assertSame('recovered', $finding->fresh()->status);
        $this->assertSame('resolved', Incident::sole()->status);
    }

    public function test_marking_an_injection_legitimate_absorbs_the_current_profile(): void
    {
        $website = Website::factory()->create(['url' => self::URL]);
        $widget = $this->html(self::NEWS.'<script src="https://widget.example/w.js"></script>');

        $this->observe($website, $this->html(self::NEWS));
        $this->observe($website, $widget);
        $finding = DefacementFinding::where('kind', 'injection')->sole();

        $user = User::factory()->create();
        $this->assertFalse(app(DefacementMonitor::class)->markLegitimate($finding, $user));
        $this->assertTrue(app(DefacementMonitor::class)->markLegitimate($finding, $user, PageSnapshot::sole()->risk_hash));
        $this->assertArrayHasKey('xs|widget.example/w.js', PageSnapshot::sole()->risk_profile['items']);
        $this->assertArrayNotHasKey('pending', PageSnapshot::sole()->risk_profile);

        // Something else changes later: the widget is no longer new.
        $this->observe($website, $this->html(self::NEWS.'<script src="https://widget.example/w.js"></script><a href="https://lain.example/">x</a>'));

        $this->assertSame('legitimate', $finding->fresh()->status);
        $this->assertSame(1, PageChange::count());
    }

    // --- Helpers --------------------------------------------------------------------

    protected function html(string $body, string $title = 'Portal Resmi'): string
    {
        return "<html><head><title>{$title}</title></head><body>{$body}</body></html>";
    }

    protected function profile(string $body, string $title = 'Portal Resmi'): InjectionProfile
    {
        return app(PageTextExtractor::class)->extractAt($this->html($body, $title), self::URL, true, self::URL)->risk;
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
}
