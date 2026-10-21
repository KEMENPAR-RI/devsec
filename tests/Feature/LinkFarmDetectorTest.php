<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\JudolDetection;
use App\Models\JudolFinding;
use App\Models\Website;
use App\Services\AppSettings;
use App\Services\Monitoring\JudolScanner;
use App\Services\Monitoring\LinkFarmDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Judol link farming from the link URL alone - deterministic, no AI needed. */
class LinkFarmDetectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_keywords_are_aggregated_per_destination_domain(): void
    {
        $result = app(LinkFarmDetector::class)->detect([
            ['url' => 'https://judi.example/slot-gacor', 'domain' => 'judi.example', 'hidden' => true],
            ['url' => 'https://judi.example/rtp-live', 'domain' => 'judi.example', 'hidden' => false],
            ['url' => 'https://berita.example/artikel', 'domain' => 'berita.example', 'hidden' => false],
        ]);

        $this->assertSame(['judi.example'], array_keys($result['domains']));
        $this->assertEqualsCanonicalizing(['slot gacor', 'rtp live'], $result['domains']['judi.example']['keywords']);
        $this->assertTrue($result['domains']['judi.example']['hidden']);
        $this->assertSame(3, $result['stats']['external_links']);
        $this->assertSame(1, $result['stats']['hidden_external_links']);
    }

    public function test_a_domain_below_the_score_threshold_is_not_flagged(): void
    {
        $this->mock(AppSettings::class, fn ($mock) => $mock->shouldReceive('judolScoreThreshold')->andReturn(6));

        $one = app(LinkFarmDetector::class)->detect([['url' => 'https://a.example/slot-gacor', 'domain' => 'a.example', 'hidden' => false]]);
        $two = app(LinkFarmDetector::class)->detect([
            ['url' => 'https://a.example/slot-gacor', 'domain' => 'a.example', 'hidden' => false],
            ['url' => 'https://a.example/bocoran-rtp', 'domain' => 'a.example', 'hidden' => false],
        ]);

        $this->assertSame([], $one['domains']);
        $this->assertArrayHasKey('a.example', $two['domains']);
    }

    public function test_ignored_hosts_are_never_flagged(): void
    {
        config(['judol.url_match.ignored_hosts' => ['a.example']]);

        $result = app(LinkFarmDetector::class)->detect([
            ['url' => 'https://sub.a.example/slot-gacor', 'domain' => 'a.example', 'hidden' => false],
            ['url' => 'https://b.example/', 'domain' => 'b.example', 'hidden' => false],
        ]);

        $this->assertSame([], $result['domains']);
        $this->assertSame(2, $result['stats']['external_links']);
    }

    public function test_the_crawl_flags_a_keyword_link_without_ai_and_records_its_source(): void
    {
        Notification::fake();
        Http::fake(fn () => Http::response('<html><head><title>Dinas</title></head><body><p>Selamat datang di portal resmi dinas.</p>'
            .'<footer><a href="https://situs-slot-gacor.example/daftar">Info</a></footer></body></html>'));
        $website = Website::factory()->create(['url' => 'https://dinas.example']);

        (new JudolScanner)->scan($website);

        $detection = JudolDetection::sole();
        $this->assertSame(['situs-slot-gacor.example'], $detection->linked_domains);
        $this->assertNull($detection->ai_verdict);
        $this->assertSame(['situs-slot-gacor.example' => 'keyword'], Incident::where('type', 'judol')->sole()->meta['link_sources']);

        $node = JudolFinding::sole()->evidence_nodes[0];
        $this->assertSame('situs-slot-gacor.example', $node['domain']);
        $this->assertSame('keyword', $node['link_source']);
    }
}
