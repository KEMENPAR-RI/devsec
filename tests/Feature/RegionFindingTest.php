<?php

namespace Tests\Feature;

use App\Models\JudolFinding;
use App\Models\Setting;
use App\Models\Website;
use App\Services\Monitoring\JudolAiClassifier;
use App\Services\Monitoring\JudolAiSettings;
use App\Services\Monitoring\JudolLinkClassifier;
use App\Services\Monitoring\JudolScanner;
use App\Services\Monitoring\PageRegion;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

/**
 * Injected backlinks almost always live in shared markup - a footer, a sidebar
 * widget, the nav - so every page rendered from that template carries them.
 * Reporting one row per URL turned a single edit to footer.php into forty rows
 * that all said the same thing and buried the fact that matters: fixing the
 * footer fixes all forty.
 */
class RegionFindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        NotificationFacade::fake();

        // The outbound-link check is gated behind the AI toggle, which ships off.
        // Without this every test here would pass vacuously - no links examined,
        // no findings, and nothing to group.
        Setting::set('judol_ai_enabled', '1');
        Setting::set('anthropic_api_key', Crypt::encryptString('sk-ant-test'));
        config(['judol.ai.links.enabled' => true]);

        // The region grouping is what is under test, not the classifier's judgement:
        // this one flags anything whose domain looks like the fixtures' spam.
        $this->app->instance(JudolLinkClassifier::class, new class extends JudolLinkClassifier
        {
            public function __construct() {}

            public function gamblingDomains(array $links): array
            {
                return array_values(array_filter(
                    array_keys($links),
                    fn (string $domain) => str_contains($domain, 'gacor') || str_contains($domain, 'slot')
                ));
            }

            public function summarize(array $domains): string
            {
                return 'Menaut ke '.implode(', ', $domains);
            }
        });

        // The page classifier too: unfaked, a keyword hit calls api.anthropic.com for real.
        $this->app->instance(JudolAiClassifier::class, new class(new JudolAiSettings) extends JudolAiClassifier
        {
            public function classify(string $url, string $text, array $matchedKeywords, array $imageUrls = []): array
            {
                return ['is_judol' => null, 'confidence' => 0.0, 'reasoning' => 'AI unavailable: faked in test'];
            }
        });
    }

    public function test_a_footer_backlink_on_many_pages_is_one_finding(): void
    {
        $footer = '<footer class="site-footer"><a href="https://gacor88.com">Slot Gacor</a></footer>';

        Http::fake([
            'sitewide.example/sitemap.xml' => Http::response('', 404),
            'sitewide.example' => Http::response(
                '<html><body><a href="/berita">Berita</a><a href="/kontak">Kontak</a>'.$footer.'</body></html>',
                200
            ),
            'sitewide.example/*' => Http::response('<html><body><p>Isi biasa.</p>'.$footer.'</body></html>', 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://sitewide.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scan($website);

        $findings = JudolFinding::where('website_id', $website->id)->get();

        $this->assertCount(1, $findings, 'one edit to the footer must not produce one row per page');

        $finding = $findings->first();
        $this->assertSame('footer', $finding->region_key);
        $this->assertSame('backlink_injection', $finding->attack_type);
        $this->assertContains('gacor88.com', $finding->linked_domains);
        // Homepage plus the two pages it links to.
        $this->assertGreaterThan(1, $finding->affected_count);
        $this->assertTrue($finding->isSitewide());
    }

    public function test_the_finding_records_where_and_what_was_found(): void
    {
        Http::fake([
            'evidence.example/sitemap.xml' => Http::response('', 404),
            'evidence.example' => Http::response(
                '<html><body><footer class="site-footer">'
                .'<a href="https://gacor88.com">Slot Gacor Maxwin</a>'
                .'<a href="https://slot4d.net">Live Draw</a>'
                .'</footer></body></html>',
                200
            ),
        ]);

        $website = Website::factory()->create(['url' => 'http://evidence.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scan($website);

        $finding = JudolFinding::where('website_id', $website->id)->firstOrFail();

        // Where: the region, and a selector an operator can search the source for.
        $this->assertSame('Footer', $finding->region_label);
        $this->assertSame('footer.site-footer', $finding->region_selector);

        // What: the domains and the anchor text that gives the pitch away.
        $this->assertEqualsCanonicalizing(['gacor88.com', 'slot4d.net'], $finding->linked_domains);
        $this->assertContains('Slot Gacor Maxwin', $finding->anchor_texts);

        // And the type of attack, in words.
        $this->assertSame('Penyisipan backlink', $finding->attackLabel());
    }

    public function test_the_finding_points_at_every_injected_element_not_just_the_first(): void
    {
        // Regression: the scanner anchored on the FIRST flagged link in a region and
        // rememberEvidence() kept the first sighting, so a footer carrying four
        // injected links reported one. An operator cleaning from that list deletes
        // one, and the next crawl flags the site again.
        Http::fake([
            'manylinks.example/sitemap.xml' => Http::response('', 404),
            'manylinks.example' => Http::response(
                '<html><body><footer class="site-footer"><ul>'
                .'<li id="a"><a href="https://gacor88.com">Slot Gacor</a></li>'
                .'<li id="b"><a href="https://slot4d.net">Live Draw</a></li>'
                .'<li id="c"><a href="https://gacor77.org">Maxwin</a></li>'
                .'</ul></footer></body></html>',
                200
            ),
        ]);

        $website = Website::factory()->create(['url' => 'http://manylinks.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scan($website);

        $finding = JudolFinding::where('website_id', $website->id)->firstOrFail();

        $paths = collect($finding->evidence_nodes)->pluck('path');

        $this->assertCount(3, $paths, 'every flagged link is its own place to go and clean');
        $this->assertSame(3, $paths->unique()->count(), 'and each points somewhere different');
        $this->assertSame(3, $finding->affectedElementCount());

        // The older single-value columns still hold the first sighting, so a row
        // written before evidence_nodes existed keeps rendering.
        $this->assertNotNull($finding->evidence_html);
        $this->assertSame($paths->first(), $finding->dom_path);
    }

    public function test_checking_one_url_also_files_a_finding(): void
    {
        // Regression: scanUrlNow() only wrote a per-page JudolDetection, so a site
        // checked that way showed as dirty on its own page - which reads detections -
        // while Deteksi Judol, which lists FINDINGS, stayed empty for it. The
        // operator saw the problem and had nowhere to act on it.
        Http::fake([
            'manual.example/kontak' => Http::response(
                '<html><body><footer class="site-footer"><a href="https://gacor88.com">Slot Gacor</a></footer></body></html>',
                200
            ),
        ]);

        $website = Website::factory()->create(['url' => 'http://manual.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scanUrlNow($website, 'http://manual.example/kontak');

        $finding = JudolFinding::where('website_id', $website->id)->firstOrFail();

        $this->assertSame('footer', $finding->region_key);
        $this->assertContains('gacor88.com', $finding->linked_domains);
        $this->assertContains('http://manual.example/kontak', $finding->affected_urls);
    }

    public function test_checking_one_url_cannot_shrink_a_sitewide_finding(): void
    {
        // One page knows nothing about the other thirty-nine, so re-checking it may
        // only ever ADD. Replacing would drop "muncul di 40 halaman" to 1 the moment
        // somebody verified a single URL - the headline number, quietly wrong.
        $footer = '<footer class="site-footer"><a href="https://gacor88.com">Slot Gacor</a></footer>';

        Http::fake([
            'shrink.example/sitemap.xml' => Http::response('', 404),
            'shrink.example' => Http::response(
                '<html><body><a href="/berita">Berita</a><a href="/kontak">Kontak</a>'.$footer.'</body></html>',
                200
            ),
            'shrink.example/*' => Http::response('<html><body><p>Isi biasa.</p>'.$footer.'</body></html>', 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://shrink.example', 'last_status' => 'up']);
        $scanner = app(JudolScanner::class);

        $scanner->scan($website);
        $before = JudolFinding::where('website_id', $website->id)->firstOrFail()->affected_count;
        $this->assertGreaterThan(1, $before);

        $scanner->scanUrlNow($website, 'http://shrink.example/kontak');

        $after = JudolFinding::where('website_id', $website->id)->firstOrFail();

        $this->assertSame($before, $after->affected_count);
        $this->assertContains('gacor88.com', $after->linked_domains);
    }

    public function test_elements_sharing_one_block_are_listed_under_a_single_snippet(): void
    {
        // PageRegion::snippet() deliberately returns the CONTAINER, so siblings in
        // one footer all carry identical markup. Printing it once per element would
        // bury the paths, which are the only thing that differs between them.
        Http::fake([
            'grouped.example/sitemap.xml' => Http::response('', 404),
            'grouped.example' => Http::response(
                '<html><body><footer class="site-footer">'
                .'<a href="https://gacor88.com">Slot Gacor</a>'
                .'<a href="https://slot4d.net">Live Draw</a>'
                .'</footer></body></html>',
                200
            ),
        ]);

        $website = Website::factory()->create(['url' => 'http://grouped.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scan($website);

        $occurrences = JudolFinding::where('website_id', $website->id)->firstOrFail()->evidenceOccurrences();

        $this->assertCount(1, $occurrences, 'one shared container, one snippet');
        $this->assertCount(2, $occurrences[0]['paths'], 'both elements still listed');
    }

    public function test_a_finding_recorded_before_evidence_nodes_existed_still_renders(): void
    {
        $website = Website::factory()->create();

        $finding = JudolFinding::create([
            'website_id' => $website->id,
            'region_key' => 'footer',
            'region_label' => 'Footer',
            'attack_type' => 'backlink_injection',
            'evidence_html' => '<footer><a href="https://gacor88.com">Slot</a></footer>',
            'dom_path' => 'body > footer > a',
            'affected_urls' => ['http://old.example'],
            'affected_count' => 1,
            'status' => 'new',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $occurrences = $finding->evidenceOccurrences();

        $this->assertCount(1, $occurrences);
        $this->assertSame('body > footer > a', $occurrences[0]['paths'][0]);
        $this->assertSame(1, $finding->affectedElementCount());
    }

    /**
     * The other half of the same idea: promo TEXT stuffed into a shared template
     * is one edit too, and reads as one finding filed under the page body.
     */
    public function test_injected_promo_text_on_many_pages_is_one_keyword_finding(): void
    {
        $promo = '<p>Situs slot gacor terpercaya, maxwin setiap hari.</p>';

        Http::fake([
            'teks.example/sitemap.xml' => Http::response('', 404),
            'teks.example' => Http::response(
                '<html><body><a href="/berita">Berita</a><a href="/kontak">Kontak</a>'.$promo.'</body></html>',
                200
            ),
            'teks.example/*' => Http::response('<html><body><p>Isi biasa.</p>'.$promo.'</body></html>', 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://teks.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scan($website);

        $findings = JudolFinding::where('website_id', $website->id)->get();

        // No outbound link anywhere on this fixture, so the text is the only finding.
        $this->assertCount(1, $findings);

        $finding = $findings->first();
        $this->assertSame('keyword_injection', $finding->attack_type);
        $this->assertSame('page_body', $finding->region_key);
        $this->assertSame('Isi halaman', $finding->region_label);
        $this->assertContains('slot gacor', $finding->matched_keywords);
        // Homepage plus the two pages it links to.
        $this->assertGreaterThan(1, $finding->affected_count);
        $this->assertSame('Penyisipan teks promosi', $finding->attackLabel());
    }

    /**
     * One edit, one finding. A gambling link whose anchor text reads "Slot Gacor
     * Maxwin" is a backlink injection - reported with the region it sits in and
     * where it points. Counting that same anchor text again as injected page text
     * filed a second finding for the same edit and pointed at the wrong place to
     * fix it.
     */
    public function test_the_anchor_text_of_a_flagged_link_is_not_also_promo_text(): void
    {
        Http::fake([
            'anchor.example/sitemap.xml' => Http::response('', 404),
            'anchor.example' => Http::response(
                '<html><body><p>Pengumuman layanan publik.</p>'
                .'<footer class="site-footer"><a href="https://gacor88.com">Slot Gacor Maxwin</a></footer>'
                .'</body></html>',
                200
            ),
        ]);

        $website = Website::factory()->create(['url' => 'http://anchor.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scan($website);

        $findings = JudolFinding::where('website_id', $website->id)->get();

        $this->assertCount(1, $findings);
        $this->assertSame('backlink_injection', $findings->first()->attack_type);

        // The page itself is still flagged - as a link-only detection, with no
        // keywords to its name, because the only ones on the page were the link's.
        $detection = $website->judolDetections()->firstOrFail();
        $this->assertSame([], $detection->matched_keywords);
    }

    /**
     * The exclusion is scoped to the flagged link, not to links in general: promo
     * text that happens to sit next to one is still text somebody injected.
     */
    public function test_promo_text_beside_a_flagged_link_is_still_reported(): void
    {
        Http::fake([
            'campur.example/sitemap.xml' => Http::response('', 404),
            'campur.example' => Http::response(
                '<html><body><p>Situs slot gacor terpercaya, maxwin setiap hari.</p>'
                .'<footer class="site-footer"><a href="https://gacor88.com">Kunjungi</a></footer>'
                .'</body></html>',
                200
            ),
        ]);

        $website = Website::factory()->create(['url' => 'http://campur.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scan($website);

        $this->assertEqualsCanonicalizing(
            ['backlink_injection', 'keyword_injection'],
            JudolFinding::where('website_id', $website->id)->pluck('attack_type')->all()
        );
    }

    public function test_a_site_with_no_keywords_files_no_keyword_finding(): void
    {
        Http::fake([
            'bersih.example/sitemap.xml' => Http::response('', 404),
            'bersih.example' => Http::response('<html><body><p>Pengumuman layanan publik.</p></body></html>', 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://bersih.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scan($website);

        // addKeywords() is called for every crawled page, so an empty match must not
        // open a region: a finding that reports no keywords is worse than none.
        $this->assertSame(0, JudolFinding::where('website_id', $website->id)->count());
    }

    /**
     * The second crawl is where this used to go wrong. evaluateAndStore() skips a
     * page it has already flagged, so the keywords for those pages have to come
     * from the stored detection - otherwise a sitewide text injection would shrink
     * to "1 halaman" the moment the site was crawled twice.
     */
    public function test_a_rescan_does_not_shrink_the_keyword_finding(): void
    {
        $promo = '<p>Situs slot gacor terpercaya, maxwin setiap hari.</p>';

        Http::fake([
            'ulang.example/sitemap.xml' => Http::response('', 404),
            'ulang.example' => Http::response(
                '<html><body><a href="/berita">Berita</a><a href="/kontak">Kontak</a>'.$promo.'</body></html>',
                200
            ),
            'ulang.example/*' => Http::response('<html><body><p>Isi biasa.</p>'.$promo.'</body></html>', 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://ulang.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scan($website);
        $first = JudolFinding::where('website_id', $website->id)->firstOrFail();

        app(JudolScanner::class)->scan($website->fresh());

        $this->assertSame(1, JudolFinding::where('website_id', $website->id)->count());
        $this->assertSame($first->affected_count, $first->fresh()->affected_count);
        $this->assertContains('slot gacor', $first->fresh()->matched_keywords);
    }

    public function test_two_different_regions_are_two_findings(): void
    {
        Http::fake([
            'tworegions.example/sitemap.xml' => Http::response('', 404),
            'tworegions.example' => Http::response(
                '<html><body>'
                .'<header class="masthead"><a href="https://gacor88.com">A</a></header>'
                .'<footer class="site-footer"><a href="https://slot4d.net">B</a></footer>'
                .'</body></html>',
                200
            ),
        ]);

        $website = Website::factory()->create(['url' => 'http://tworegions.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scan($website);

        // Two separate edits in two separate templates: two things to fix.
        $this->assertEqualsCanonicalizing(
            ['header', 'footer'],
            JudolFinding::where('website_id', $website->id)->pluck('region_key')->all()
        );
    }

    public function test_rescanning_updates_the_finding_instead_of_duplicating_it(): void
    {
        Http::fake([
            'repeat.example/sitemap.xml' => Http::response('', 404),
            'repeat.example' => Http::response(
                '<html><body><footer class="f"><a href="https://gacor88.com">X</a></footer></body></html>',
                200
            ),
        ]);

        $website = Website::factory()->create(['url' => 'http://repeat.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scan($website);
        $first = JudolFinding::where('website_id', $website->id)->firstOrFail();

        // An operator triages it; a later crawl must not undo that by filing a
        // second copy or resetting the status.
        $first->update(['status' => 'reviewed']);

        app(JudolScanner::class)->scan($website);

        $this->assertSame(1, JudolFinding::where('website_id', $website->id)->count());
        $this->assertSame('reviewed', $first->fresh()->status);
        $this->assertTrue(
            $first->fresh()->first_seen_at->equalTo($first->first_seen_at),
            'first_seen_at is how long the injection has been up - it must not move'
        );
    }

    public function test_a_link_in_unrecognised_markup_is_reported_honestly(): void
    {
        // Guessing a region would send someone editing the wrong template, so an
        // unidentifiable location says so rather than inventing one.
        $dom = new DOMDocument;
        @$dom->loadHTML('<html><body><div><a href="https://x.com">x</a></div></body></html>');
        $anchor = $dom->getElementsByTagName('a')->item(0);

        $region = app(PageRegion::class)->locate($anchor);

        $this->assertSame('unknown', $region['key']);
        $this->assertNull($region['selector']);
    }
}
