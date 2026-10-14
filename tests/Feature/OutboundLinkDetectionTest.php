<?php

namespace Tests\Feature;

use App\Models\JudolDetection;
use App\Models\JudolKeyword;
use App\Models\Setting;
use App\Models\Website;
use App\Services\Monitoring\JudolAiClassifier;
use App\Services\Monitoring\JudolAiSettings;
use App\Services\Monitoring\JudolLinkClassifier;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Backlink injection: the attacker leaves the visible article completely alone and
 * only plants hidden <a href> tags pointing at their gambling site, to borrow the
 * hacked domain's SEO authority. No keyword ever appears in the text, so the
 * keyword filter is structurally blind to it - the destination domain is the whole
 * of the evidence, and judging a brand-new brand name is a job only the AI can do.
 */
class OutboundLinkDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        JudolKeyword::firstOrCreate(['keyword' => 'slot gacor'], ['is_active' => true]);
        Setting::set('judol_ai_enabled', '1');
        Setting::set('anthropic_api_key', Crypt::encryptString('sk-ant-test'));
        config(['judol.ai.links.enabled' => true]);
    }

    public function test_a_clean_page_that_links_to_a_gambling_site_is_still_flagged(): void
    {
        $this->fakeLinkClassifier(['dewa89bet.com']);

        // Nothing here trips the keyword filter - it is an ordinary news page.
        $html = '<body><h1>Kunjungan wisatawan naik 12 persen</h1>'
            .'<p>Kementerian mencatat kenaikan kunjungan sepanjang kuartal ini.</p>'
            .'<a href="https://dewa89bet.com/daftar" style="display:none">a</a>'
            .'</body>';

        $detection = $this->scan($html, 'https://contoh.go.id/berita/kunjungan-naik');

        $this->assertNotNull($detection, 'the injected link is the only evidence, and it must be enough');
        $this->assertSame(['dewa89bet.com'], $detection->linked_domains);
        $this->assertSame([], $detection->matched_keywords, 'no keyword matched, and none should be invented');
        $this->assertSame('confirmed', $detection->ai_verdict);
        $this->assertNull($detection->ai_confidence, 'the link check answers yes/no, not a probability');
        $this->assertStringContainsString('dewa89bet.com', $detection->ai_reasoning);
    }

    public function test_a_page_linking_only_to_ordinary_sites_stays_clean(): void
    {
        $this->fakeLinkClassifier([]);

        $html = '<body><p>Berita biasa.</p><a href="https://kemenpar.go.id">Kemenpar</a></body>';

        $this->assertNull($this->scan($html, 'https://contoh.go.id/berita/biasa'));
    }

    public function test_hosts_on_the_ignore_list_are_never_sent_to_the_ai(): void
    {
        // Every page on the web has a Facebook share button; asking about it on
        // every scan would be pure token burn.
        $classifier = $this->fakeLinkClassifier([]);

        $html = '<body><p>Berita.</p>'
            .'<a href="https://www.facebook.com/sharer/sharer.php?u=x">Bagikan</a>'
            .'<a href="https://youtu.be/abc">Video</a>'
            .'</body>';

        $this->scan($html, 'https://contoh.go.id/berita/bagikan');

        $this->assertSame([], $classifier->asked, 'nothing was left worth asking about');
    }

    public function test_links_to_the_site_itself_are_not_judged_as_outbound(): void
    {
        $classifier = $this->fakeLinkClassifier([]);

        $html = '<body><p>Berita.</p><a href="/tentang-kami">Tentang</a>'
            .'<a href="https://contoh.go.id/kontak">Kontak</a></body>';

        $this->scan($html, 'https://contoh.go.id/berita/internal');

        $this->assertSame([], $classifier->asked, 'the site links to its own pages constantly');
    }

    public function test_subdomains_and_deep_links_collapse_to_one_domain_to_judge(): void
    {
        $classifier = $this->fakeLinkClassifier([]);

        $html = '<body><p>x</p>'
            .'<a href="https://www.olxtoto99.com/a">1</a>'
            .'<a href="https://m.olxtoto99.com/b">2</a>'
            .'<a href="https://olxtoto99.com/c/d/e">3</a>'
            .'<a href="https://berita.tempo.co.id/x">4</a>'
            .'</body>';

        $this->scan($html, 'https://contoh.go.id/berita/banyak-tautan');

        // Four links, two registered domains - and .co.id keeps its third label.
        $this->assertSame(['olxtoto99.com', 'tempo.co.id'], $classifier->asked);
    }

    /**
     * Taken verbatim from a real scan in this app's own site list: a hacked
     * ministry page linked to "wicc2024.com" - a name that reads like an academic
     * conference - under the anchor text "Live Draw Macau". Judging the domain
     * alone, the AI cleared it; the anchor text is what gives it away, so both
     * halves have to reach the classifier.
     */
    public function test_the_anchor_text_travels_with_the_domain(): void
    {
        $classifier = $this->fakeLinkClassifier([]);

        $html = '<body><p>Berita.</p>'
            .'<a href="https://www.wicc2024.com/x">Live Draw Macau</a>'
            .'<a href="https://pantirukmi.id"><img src="/l.png" alt="Panti Rukmi"></a>'
            .'</body>';

        $this->scan($html, 'https://contoh.go.id/berita/anchor');

        $this->assertSame([
            'wicc2024.com' => 'Live Draw Macau',
            // No text inside the tag, so the image's alt stands in for it.
            'pantirukmi.id' => 'Panti Rukmi',
        ], $classifier->anchors);
    }

    /**
     * The page classifier reads the page's TEXT. On a backlink injection that text
     * is a genuine, clean article, so it will confidently say "not gambling" - and
     * before this guard that denial deleted the detection the link check had just
     * correctly raised.
     */
    public function test_the_page_classifier_cannot_deny_away_an_injected_link(): void
    {
        $this->fakeLinkClassifier(['garuda4dwin.com']);
        $this->fakePageClassifier(['is_judol' => false, 'confidence' => 0.99, 'reasoning' => 'Artikel berita biasa.']);

        $html = '<body><p>Artikel soal slot gacor di pemberitaan nasional.</p>'
            .'<a href="https://garuda4dwin.com">x</a></body>';

        $detection = $this->scan($html, 'https://contoh.go.id/berita/campuran');

        $this->assertNotNull($detection, 'the AI denied the text, but it said nothing about the link');
        $this->assertSame(['garuda4dwin.com'], $detection->linked_domains);
    }

    /**
     * Editing the keyword list prunes findings whose keywords are gone. A link
     * finding has no keywords at all, so that sweep must step over it rather than
     * treat it as a finding with zero surviving keywords.
     */
    public function test_pruning_stale_keywords_leaves_link_findings_alone(): void
    {
        $this->fakeLinkClassifier(['mpo808win.com']);

        $detection = $this->scan(
            '<body><p>Berita.</p><a href="https://mpo808win.com">x</a></body>',
            'https://contoh.go.id/berita/tautan'
        );

        JudolKeyword::query()->update(['is_active' => false]);

        app(JudolScanner::class)->pruneStaleDetections();

        $this->assertModelExists($detection);
    }

    // --- helpers ----------------------------------------------------------

    protected function scan(string $html, string $url): ?JudolDetection
    {
        $website = Website::factory()->create(['url' => 'https://contoh.go.id']);

        Http::fake([$url => Http::response($html, 200)]);

        app(JudolScanner::class)->scanUrlNow($website, $url);

        return $website->judolDetections()->where('url', $url)->first();
    }

    /**
     * @param  array<int, string>  $flagged  domains the fake AI calls gambling
     */
    protected function fakeLinkClassifier(array $flagged): JudolLinkClassifier
    {
        $fake = new class($flagged, app(JudolAiSettings::class)) extends JudolLinkClassifier
        {
            /** @var array<int, string> */
            public array $asked = [];

            /** @var array<string, string> */
            public array $anchors = [];

            /** @param array<int, string> $flagged */
            public function __construct(protected array $flagged, JudolAiSettings $settings)
            {
                parent::__construct($settings);
            }

            public function gamblingDomains(array $links): array
            {
                $this->asked = array_keys($links);
                $this->anchors = $links;

                return array_values(array_intersect($this->asked, $this->flagged));
            }
        };

        $this->app->instance(JudolLinkClassifier::class, $fake);

        return $fake;
    }

    /**
     * @param  array{is_judol: ?bool, confidence: float, reasoning: string}  $verdict
     */
    protected function fakePageClassifier(array $verdict): void
    {
        $this->app->instance(JudolAiClassifier::class, new class($verdict, app(JudolAiSettings::class)) extends JudolAiClassifier
        {
            /** @param array{is_judol: ?bool, confidence: float, reasoning: string} $verdict */
            public function __construct(protected array $verdict, JudolAiSettings $settings)
            {
                parent::__construct($settings);
            }

            public function classify(string $url, string $text, array $matchedKeywords, array $imageUrls = []): array
            {
                return $this->verdict;
            }
        });
    }
}
