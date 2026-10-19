<?php

namespace Tests\Feature;

use App\Models\JudolDetection;
use App\Models\JudolFinding;
use App\Models\JudolKeyword;
use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A compromised site whose WAF answers 403 to the crawler while its articles still
 * serve normally. This used to produce judol incidents with an empty Deteksi Judol
 * list: manual URL checks kept writing detections, but the crawl that builds
 * findings gave up at the front door.
 */
class BlockedHomepageScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_crawl_continues_past_a_homepage_that_answers_403(): void
    {
        JudolKeyword::firstOrCreate(['keyword' => 'togel singapore'], ['is_active' => true]);

        Http::fake([
            'blocked.example/sitemap.xml' => Http::response(
                '<urlset><url><loc>http://blocked.example/artikel.html</loc></url></urlset>',
                200
            ),
            'blocked.example/artikel.html' => Http::response(
                '<html><head><meta name="description" content="togel singapore hari ini"></head><body>artikel</body></html>',
                200
            ),
            'blocked.example' => Http::response('Forbidden', 403),
        ]);

        $website = Website::factory()->create(['url' => 'http://blocked.example']);

        $created = (new JudolScanner)->scan($website);

        $this->assertSame(1, $created, 'the article behind the blocked homepage was still scanned');
        $this->assertSame(1, JudolFinding::where('website_id', $website->id)->count(),
            'a finding is what the Deteksi Judol list reads - it must exist too');
    }

    public function test_a_crawl_still_stops_when_the_server_never_answers(): void
    {
        JudolKeyword::firstOrCreate(['keyword' => 'togel singapore'], ['is_active' => true]);

        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $website = Website::factory()->create(['url' => 'http://dead.example']);

        $this->assertSame(0, (new JudolScanner)->scan($website));
        $this->assertSame(0, JudolFinding::where('website_id', $website->id)->count());
    }

    public function test_a_previously_detected_url_is_recrawled_when_the_sitemap_is_gone_too(): void
    {
        JudolKeyword::firstOrCreate(['keyword' => 'togel singapore'], ['is_active' => true]);

        Http::fake([
            'walled.example/sitemap.xml' => Http::response('', 403),
            'walled.example/lama.html' => Http::response(
                '<html><head><meta name="description" content="togel singapore hari ini"></head><body>x</body></html>',
                200
            ),
            'walled.example' => Http::response('Forbidden', 403),
        ]);

        $website = Website::factory()->create(['url' => 'http://walled.example']);

        // The trail a manual "Cek URL Ini" leaves behind - the only address still
        // known to exist once the homepage and sitemap are both walled off.
        JudolDetection::create([
            'website_id' => $website->id,
            'url' => 'http://walled.example/lama.html',
            'matched_keywords' => ['togel singapore'],
            'status' => 'new',
            'detected_at' => now(),
        ]);

        (new JudolScanner)->scan($website);

        $this->assertSame(1, JudolFinding::where('website_id', $website->id)->count(),
            'the known-dirty page seeds the crawl, so its finding gets built');
    }

    public function test_a_site_refusing_with_403_is_still_scannable(): void
    {
        $website = Website::factory()->create(['last_status' => 'down']);

        WebsiteCheck::create([
            'website_id' => $website->id,
            'is_up' => false,
            'status_code' => 403,
            'error_message' => 'HTTP 403',
            'checked_at' => now(),
        ]);

        $this->assertTrue($website->isScannable(), 'a server that answers 403 is alive and worth crawling');
    }

    public function test_a_site_that_never_answers_is_not_scannable(): void
    {
        $website = Website::factory()->create(['last_status' => 'down']);

        WebsiteCheck::create([
            'website_id' => $website->id,
            'is_up' => false,
            'status_code' => null,
            'error_message' => 'cURL error 28: Operation timed out',
            'checked_at' => now(),
        ]);

        $this->assertFalse($website->isScannable());
    }

    public function test_a_server_erroring_on_its_own_homepage_is_not_scannable(): void
    {
        // 500 is the server failing, not refusing - its other pages will fail the
        // same way, so crawling on would spend the job's whole budget on nothing.
        $website = Website::factory()->create(['last_status' => 'down']);

        WebsiteCheck::create([
            'website_id' => $website->id,
            'is_up' => false,
            'status_code' => 500,
            'error_message' => 'HTTP 500',
            'checked_at' => now(),
        ]);

        $this->assertFalse($website->isScannable());
    }
}
