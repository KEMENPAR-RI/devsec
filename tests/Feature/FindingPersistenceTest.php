<?php

namespace Tests\Feature;

use App\Models\JudolDetection;
use App\Models\JudolFinding;
use App\Models\JudolKeyword;
use App\Models\User;
use App\Models\Website;
use App\Notifications\JudolContentDetected;
use App\Services\Monitoring\JudolScanner;
use App\Services\Monitoring\PageRegion;
use App\Services\Monitoring\RegionFindings;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use ReflectionClass;
use Tests\TestCase;

/**
 * A finding that cannot be stored is a finding nobody sees.
 *
 * On the first badly compromised site this met, the breadcrumb for a footer widget
 * ten wrappers deep came to 267 characters against a VARCHAR(255) column. MySQL
 * raises SQLSTATE[22001] rather than truncating, the INSERT threw, and persist()
 * died with it - so a site with sixty judol detections showed an empty Deteksi
 * Judol list, crawl after crawl.
 *
 * Note these run on SQLite, which does not enforce VARCHAR lengths at all - so the
 * column width itself cannot be asserted here. What is asserted is everything that
 * keeps the width from ever being reached, and the containment around it.
 */
class FindingPersistenceTest extends TestCase
{
    use RefreshDatabase;

    /** An element buried under many long-classed wrappers, as a real theme produces. */
    protected function deeplyNestedAnchor(int $depth = 40): \DOMElement
    {
        $html = '<html><body>';

        for ($i = 0; $i < $depth; $i++) {
            $html .= '<div class="wrapper-level-'.$i.' another-really-long-class-name-'.$i.'">';
        }

        $html .= '<a href="#">togel</a>'.str_repeat('</div>', $depth).'</body></html>';

        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        return $dom->getElementsByTagName('a')->item(0);
    }

    public function test_a_breadcrumb_stays_within_the_column_it_is_stored_in(): void
    {
        $path = app(PageRegion::class)->path($this->deeplyNestedAnchor());

        $this->assertLessThanOrEqual(1000, mb_strlen($path),
            'dom_path is a bounded column - an unbounded path threw instead of truncating');
    }

    public function test_a_trimmed_breadcrumb_keeps_whole_selectors_and_the_specific_end(): void
    {
        $pageRegion = app(PageRegion::class);
        $trim = (new ReflectionClass($pageRegion))->getMethod('trimPath');
        $trim->setAccessible(true);

        $segments = [];

        for ($i = 0; $i < 12; $i++) {
            $segments[] = 'div.'.str_repeat('very-long-class-name-'.$i.'-', 3);
        }

        $segments[] = 'a';

        $trimmed = $trim->invoke($pageRegion, implode(' > ', $segments));

        $this->assertLessThanOrEqual(500, mb_strlen($trimmed));
        // Trimmed from the left: the final selectors are what gets pasted into
        // devtools, the outer wrappers are the inferable part.
        $this->assertStringEndsWith('> a', $trimmed);
        $this->assertStringStartsWith('... > ', $trimmed);
        // Never cut mid-selector - half a selector is not pasteable.
        $this->assertStringNotContainsString('...div', $trimmed);
    }

    public function test_a_short_breadcrumb_is_left_exactly_as_it_is(): void
    {
        $pageRegion = app(PageRegion::class);
        $trim = (new ReflectionClass($pageRegion))->getMethod('trimPath');
        $trim->setAccessible(true);

        $path = 'body > footer.site-footer > div.textwidget > p:nth-child(4) > a';

        $this->assertSame($path, $trim->invoke($pageRegion, $path));
    }

    public function test_one_unstorable_region_does_not_take_the_others_down_with_it(): void
    {
        $website = Website::factory()->create();

        $regions = new RegionFindings;
        // Two regions, one of which will not store. The failure is forced through
        // the NOT NULL region_label rather than a column width, because SQLite - what
        // these tests run on - enforces nullability but ignores VARCHAR lengths
        // entirely. What is under test is the containment, not which column broke.
        $regions->addKeywords('http://x.example/a', ['togel'], [
            'key' => 'rusak',
            'label' => null,
            'selector' => null,
        ]);
        $regions->addKeywords('http://x.example/b', ['togel singapore'], [
            'key' => 'footer',
            'label' => 'Footer',
            'selector' => 'footer.site-footer',
        ]);

        $regions->persist($website);

        // The healthy region is what matters: before the per-region try/catch, the
        // broken one aborted the whole loop and this was 0.
        $this->assertSame(1, JudolFinding::where('website_id', $website->id)->count());
        $this->assertSame('footer', JudolFinding::where('website_id', $website->id)->value('region_key'));
    }

    public function test_a_first_ever_injection_site_is_announced_even_when_its_pages_were_already_flagged(): void
    {
        NotificationFacade::fake();
        JudolKeyword::firstOrCreate(['keyword' => 'togel singapore'], ['is_active' => true]);
        $user = User::factory()->create();

        $spam = '<html><body><footer class="site-footer"><div class="textwidget">'
            .'<p><a href="http://x.example">togel singapore</a></p></div></footer></body></html>';

        Http::fake([
            'seen.example/sitemap.xml' => Http::response('', 404),
            'seen.example' => Http::response($spam, 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://seen.example']);

        // The page is already on record - as it would be after manual checks, or
        // after crawls whose findings kept failing to store. evaluateAndStore()
        // therefore reports nothing new, and the old code fell silent here.
        JudolDetection::create([
            'website_id' => $website->id,
            'url' => 'http://seen.example',
            'matched_keywords' => ['togel singapore'],
            'status' => 'new',
            'detected_at' => now(),
        ]);

        (new JudolScanner)->scan($website);

        $this->assertGreaterThan(0, JudolFinding::where('website_id', $website->id)->count());
        NotificationFacade::assertSentToTimes($user, JudolContentDetected::class, 1);
    }

    public function test_a_re_crawl_that_discovers_nothing_new_stays_quiet(): void
    {
        NotificationFacade::fake();
        JudolKeyword::firstOrCreate(['keyword' => 'togel singapore'], ['is_active' => true]);
        $user = User::factory()->create();

        $spam = '<html><body><footer class="site-footer"><div class="textwidget">'
            .'<p><a href="http://x.example">togel singapore</a></p></div></footer></body></html>';

        Http::fake([
            'quiet.example/sitemap.xml' => Http::response('', 404),
            'quiet.example' => Http::response($spam, 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://quiet.example']);

        $scanner = new JudolScanner;
        $scanner->scan($website);
        $scanner->scan($website);

        // The finding is only NEW once; re-announcing a standing injection every
        // five minutes is what the batching exists to prevent.
        NotificationFacade::assertSentToTimes($user, JudolContentDetected::class, 1);
    }
}
