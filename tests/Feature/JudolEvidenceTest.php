<?php

namespace Tests\Feature;

use App\Models\JudolFinding;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Monitoring\JudolAiClassifier;
use App\Services\Monitoring\JudolAiSettings;
use App\Services\Monitoring\JudolLinkClassifier;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

/**
 * "Di footer" is the right unit for the FIX, but it is not a location - a themed
 * footer runs to hundreds of lines. These cover the part that answers "letak
 * persisnya": the node the injection sits on, the markup around it, and the promo
 * banners in the same block.
 */
class JudolEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        NotificationFacade::fake();

        Setting::set('judol_ai_enabled', '1');
        Setting::set('anthropic_api_key', Crypt::encryptString('sk-ant-test'));
        config(['judol.ai.links.enabled' => true]);

        // The same stand-in RegionFindingTest uses: the region work is under test,
        // not the classifier's judgement.
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

    /**
     * Promo text used to be filed under one hardcoded "Isi halaman" for every site,
     * which is not a location - it is the absence of one. A text injection is as
     * template-shaped as a backlink, so it gets the same answer: which block.
     */
    public function test_a_keyword_finding_records_the_region_it_was_found_in(): void
    {

        Http::fake([
            'teks-region.example/sitemap.xml' => Http::response('', 404),
            'teks-region.example' => Http::response(
                '<html><body><main><p>Berita biasa.</p></main>'
                .'<aside class="sidebar-widget"><p>Situs slot gacor terpercaya.</p></aside>'
                .'</body></html>',
                200
            ),
        ]);

        $website = Website::factory()->create(['url' => 'http://teks-region.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scan($website);

        $finding = JudolFinding::where('website_id', $website->id)
            ->where('attack_type', 'keyword_injection')
            ->firstOrFail();

        // The <aside> landmark wins over the class hint, as it should - the tag is
        // the stronger statement of what the block is.
        $this->assertSame('aside', $finding->region_key);
        $this->assertSame('Sidebar', $finding->region_label);
        $this->assertContains('slot gacor', $finding->matched_keywords);
    }

    /** A phrase in the meta description has no visible element, and says so. */
    public function test_a_keyword_in_a_meta_tag_is_reported_as_a_meta_tag(): void
    {

        Http::fake([
            'meta-region.example/sitemap.xml' => Http::response('', 404),
            'meta-region.example' => Http::response(
                '<html><head><meta name="description" content="Bandar togel online terpercaya"></head>'
                .'<body><p>Isi biasa.</p></body></html>',
                200
            ),
        ]);

        $website = Website::factory()->create(['url' => 'http://meta-region.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scan($website);

        $finding = JudolFinding::where('website_id', $website->id)->firstOrFail();

        $this->assertSame('meta', $finding->region_key);
        $this->assertSame('Meta tag halaman', $finding->region_label);
    }

    /** The route down to the element, plus the markup around it. */
    public function test_the_finding_records_the_exact_node_and_its_markup(): void
    {
        Http::fake([
            'bukti.example/sitemap.xml' => Http::response('', 404),
            'bukti.example' => Http::response(
                '<html><body><footer class="site-footer"><ul>'
                .'<li><a href="/tentang">Tentang</a></li>'
                .'<li><a href="https://gacor88.com">Slot Gacor Maxwin</a></li>'
                .'</ul></footer></body></html>',
                200
            ),
        ]);

        $website = Website::factory()->create(['url' => 'http://bukti.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scan($website);

        $finding = JudolFinding::where('website_id', $website->id)->firstOrFail();

        $this->assertStringContainsString('footer.site-footer', $finding->dom_path);
        $this->assertStringEndsWith('a', $finding->dom_path);
        // The container's markup, so the injected link is shown in context.
        $this->assertStringContainsString('gacor88.com', $finding->evidence_html);
        $this->assertStringContainsString('site-footer', $finding->evidence_html);
    }

    /**
     * Gambling spam routinely carries its pitch as an image, which the text keyword
     * filter cannot see at all. Only images from a region that was actually flagged
     * are attached - the site's own logo in the header is not evidence.
     */
    public function test_images_in_the_injected_region_are_recorded(): void
    {
        Http::fake([
            'gambar.example/sitemap.xml' => Http::response('', 404),
            'gambar.example' => Http::response(
                '<html><body>'
                .'<header class="masthead"><img src="/logo-resmi.png"></header>'
                .'<footer class="site-footer">'
                .'<img src="/uploads/banner-slot.jpg">'
                .'<a href="https://gacor88.com">Daftar</a>'
                .'</footer></body></html>',
                200
            ),
        ]);

        $website = Website::factory()->create(['url' => 'http://gambar.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scan($website);

        $finding = JudolFinding::where('website_id', $website->id)->firstOrFail();

        $this->assertContains('http://gambar.example/uploads/banner-slot.jpg', $finding->image_urls);
        $this->assertNotContains('http://gambar.example/logo-resmi.png', $finding->image_urls);
    }

    /**
     * evidence_html is markup lifted off a compromised site. It is shown as text,
     * never parsed - otherwise the results page would run whatever the attacker
     * put in the footer.
     */
    public function test_the_injected_markup_is_rendered_escaped(): void
    {
        $website = Website::factory()->create(['name' => 'Portal Uji']);

        JudolFinding::create([
            'website_id' => $website->id,
            'region_key' => 'footer',
            'region_label' => 'Footer',
            'attack_type' => 'backlink_injection',
            'linked_domains' => ['gacor88.com'],
            'evidence_html' => '<footer><script>alert(1)</script><a href="https://gacor88.com">Slot</a></footer>',
            'dom_path' => 'body > footer > a',
            'affected_urls' => ['http://portal-uji.example/'],
            'affected_count' => 1,
            'status' => 'new',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs(User::factory()->create())->get(route('deteksi-judol'));

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        // And the incriminating part is picked out of the surrounding markup.
        $response->assertSee('<mark', false);
    }

    public function test_the_page_accounts_for_every_injection_point_not_just_one(): void
    {
        // The whole point of the list: an operator has to read the page and know
        // each place to go. Showing one element of three is a fix list that leaves
        // two behind.
        //
        // The DevTools breadcrumbs themselves are no longer rendered - twenty lines
        // of them per finding buried the markup that identifies the injection. What
        // still has to hold is the accounting: the element count, and one snippet
        // per distinct piece of markup.
        $website = Website::factory()->create(['name' => 'Portal Banyak']);
        $footer = '<footer class="site-footer"><a href="https://gacor88.com">Slot</a></footer>';

        JudolFinding::create([
            'website_id' => $website->id,
            'region_key' => 'footer',
            'region_label' => 'Footer',
            'attack_type' => 'backlink_injection',
            'linked_domains' => ['gacor88.com'],
            'evidence_html' => $footer,
            'dom_path' => 'body > footer.site-footer > ul > li:nth-child(1) > a',
            'evidence_nodes' => [
                ['path' => 'body > footer.site-footer > ul > li:nth-child(1) > a', 'html' => $footer],
                ['path' => 'body > footer.site-footer > ul > li:nth-child(2) > a', 'html' => $footer],
                ['path' => 'body > aside.widget > p > a', 'html' => '<aside class="widget"><p><a href="https://gacor88.com">Gacor</a></p></aside>'],
            ],
            'affected_urls' => ['http://portal-banyak.example/'],
            'affected_count' => 1,
            'status' => 'new',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs(User::factory()->create())->get(route('deteksi-judol'));

        $response->assertOk();
        // The count says how many places there are before anything is read - the
        // two footer siblings plus the sidebar.
        $response->assertSee('3 elemen');
        // Both distinct pieces of markup are shown; the two siblings sharing a
        // container are grouped into one snippet rather than repeating it.
        $response->assertSee('&lt;footer class=&quot;site-footer&quot;&gt;', false);
        $response->assertSee('&lt;aside class=&quot;widget&quot;&gt;', false);
        // Two occurrences means the toggle carries a count of its own.
        $response->assertSee('cuplikan HTML');
    }

    public function test_the_markup_is_collapsed_once_a_finding_has_been_triaged(): void
    {
        // A finding still waiting on a decision opens with its markup visible; one
        // already reviewed is settled, and its two thousand characters only get in
        // the way of the findings that are not.
        $website = Website::factory()->create(['name' => 'Portal Beres']);

        $finding = JudolFinding::create([
            'website_id' => $website->id,
            'region_key' => 'footer',
            'region_label' => 'Footer',
            'attack_type' => 'backlink_injection',
            'linked_domains' => ['gacor88.com'],
            'evidence_html' => '<footer class="site-footer"><a href="https://gacor88.com">Slot</a></footer>',
            'affected_urls' => ['http://portal-beres.example/'],
            'affected_count' => 1,
            'status' => 'new',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->get(route('deteksi-judol'))
            ->assertOk()
            ->assertSee('x-data="{ open: true }"', false);

        $finding->update(['status' => 'reviewed']);

        $this->actingAs($user)->get(route('deteksi-judol'))
            ->assertOk()
            ->assertDontSee('x-data="{ open: true }"', false);
    }

    /** The proxy addresses an image by index, so an out-of-range one is a 404. */
    public function test_the_image_proxy_refuses_an_index_the_crawler_never_recorded(): void
    {
        $finding = $this->findingWithImage('http://gambar.example/uploads/banner-slot.jpg');

        $this->actingAs(User::factory()->create())
            ->get(route('deteksi-judol.gambar', ['finding' => $finding, 'index' => 5]))
            ->assertNotFound();
    }

    /**
     * Plain http is fine, and has to be: plenty of the sites this app watches are
     * still served over http, and the browser only ever talks to this origin
     * anyway - which is what keeps the thumbnails clear of both the CSP and mixed
     * content.
     *
     * Addressed by IP literal rather than a hostname because ".example" does not
     * resolve, so isPubliclyRoutable() would reject the URL before the fetch is
     * ever attempted and the test would pass without testing anything.
     */
    public function test_the_image_proxy_serves_a_plain_http_image(): void
    {
        Http::fake(['*' => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png'])]);

        $finding = $this->findingWithImage('http://93.184.216.34/uploads/banner-slot.png');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('deteksi-judol.gambar', ['finding' => $finding, 'index' => 0]));

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /** What lives at a recorded address still belongs to the attacker. */
    public function test_the_image_proxy_refuses_a_response_that_is_not_an_image(): void
    {
        Http::fake(['*' => Http::response('rahasia', 200, ['Content-Type' => 'text/plain'])]);

        $finding = $this->findingWithImage('http://93.184.216.34/uploads/bukan-gambar.jpg');

        $this->actingAs(User::factory()->create())
            ->get(route('deteksi-judol.gambar', ['finding' => $finding, 'index' => 0]))
            ->assertNotFound();
    }

    /**
     * The addresses came out of a hacked site's markup, so one of them pointing at
     * localhost or a cloud metadata endpoint is a scenario to close, not to reason
     * about. The request must never leave the building at all.
     */
    public function test_the_image_proxy_never_requests_a_private_address(): void
    {
        Http::fake(['*' => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png'])]);

        $finding = $this->findingWithImage('http://127.0.0.1/uploads/banner.png');

        $this->actingAs(User::factory()->create())
            ->get(route('deteksi-judol.gambar', ['finding' => $finding, 'index' => 0]))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    /** Nobody without the permission gets to pull bytes off a compromised site. */
    public function test_the_image_proxy_is_permission_gated(): void
    {
        $finding = $this->findingWithImage('http://gambar.example/uploads/banner-slot.jpg');

        $this->get(route('deteksi-judol.gambar', ['finding' => $finding, 'index' => 0]))
            ->assertRedirect(route('login'));
    }

    private function findingWithImage(string $url): JudolFinding
    {
        $website = Website::factory()->create();

        return JudolFinding::create([
            'website_id' => $website->id,
            'region_key' => 'footer',
            'region_label' => 'Footer',
            'attack_type' => 'backlink_injection',
            'linked_domains' => ['gacor88.com'],
            'image_urls' => [$url],
            'affected_urls' => ['http://gambar.example/'],
            'affected_count' => 1,
            'status' => 'new',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
