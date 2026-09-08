<?php

namespace Tests\Feature;

use App\Models\JudolFinding;
use App\Models\Website;
use App\Services\Monitoring\JudolLinkClassifier;
use App\Services\Monitoring\JudolScanner;
use App\Services\Monitoring\PageRegion;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        config(['judol.ai.enabled' => true, 'judol.ai.links.enabled' => true]);

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
