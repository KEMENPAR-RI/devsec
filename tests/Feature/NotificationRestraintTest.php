<?php

namespace Tests\Feature;

use App\Livewire\Incidents\Index as IncidentsIndex;
use App\Livewire\Websites\Show;
use App\Models\Incident;
use App\Models\JudolDetection;
use App\Models\JudolKeyword;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Notifications\JudolContentDetected;
use App\Services\Monitoring\JudolAiClassifier;
use App\Services\Monitoring\JudolAiSettings;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Guards the "stop mailing me the same thing" rules: only the automatic crawl
 * notifies, and only for findings that are actually new or actually changed.
 */
class NotificationRestraintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        NotificationFacade::fake();
        JudolKeyword::firstOrCreate(['keyword' => 'slot gacor'], ['is_active' => true]);
    }

    public function test_a_manual_url_check_flags_the_page_but_sends_nothing(): void
    {
        $user = User::factory()->create();
        Http::fake(['manual-quiet.example/*' => Http::response('<title>Slot Gacor</title>', 200)]);
        $website = Website::factory()->create(['url' => 'http://manual-quiet.example']);

        $matched = (new JudolScanner)->scanUrlNow($website, 'http://manual-quiet.example/slot.html');

        $this->assertSame(['slot gacor'], $matched, 'the detection is still recorded');
        $this->assertSame(1, JudolDetection::count());
        NotificationFacade::assertNothingSentTo($user);
    }

    public function test_the_automatic_crawl_still_notifies(): void
    {
        $user = User::factory()->create();
        Http::fake([
            'auto-loud.example/sitemap.xml' => Http::response('', 404),
            'auto-loud.example' => Http::response('<title>Slot Gacor</title>', 200),
        ]);
        $website = Website::factory()->create(['url' => 'http://auto-loud.example']);

        (new JudolScanner)->scan($website);

        NotificationFacade::assertSentToTimes($user, JudolContentDetected::class, 1);
    }

    public function test_rescanning_an_unchanged_page_does_not_notify_again(): void
    {
        $user = User::factory()->create();
        Http::fake([
            'unchanged.example/sitemap.xml' => Http::response('', 404),
            'unchanged.example' => Http::response('<title>Slot Gacor</title><p>Isi halaman yang sama persis setiap kali dicek.</p>', 200),
        ]);
        $website = Website::factory()->create(['url' => 'http://unchanged.example']);
        $scanner = new JudolScanner;

        $scanner->scan($website);
        // Force the second crawl to re-evaluate the page it already knows, the way a
        // manual re-check does - the content is identical, so it isn't news.
        $detection = JudolDetection::firstOrFail();
        $scanner->scanUrlNow($website, $detection->url);
        $scanner->scan($website);

        NotificationFacade::assertSentToTimes($user, JudolContentDetected::class, 1);
    }

    public function test_the_incidents_page_leaves_judol_findings_to_their_own_page(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();

        $downtime = Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'Situs tidak dapat diakses', 'status' => 'open', 'detected_at' => now(),
        ]);
        Incident::create([
            'website_id' => $website->id, 'type' => 'judol', 'severity' => 'critical',
            'title' => 'Konten judi online terdeteksi', 'status' => 'open', 'detected_at' => now(),
        ]);

        $ids = Livewire::actingAs($user)
            ->test(IncidentsIndex::class)
            ->viewData('incidentsByGroup')
            ->flatten()
            ->pluck('id')
            ->all();

        $this->assertSame([$downtime->id], $ids);
    }

    public function test_deleting_all_incidents_keeps_the_judol_ones(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();

        Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'Down', 'status' => 'open', 'detected_at' => now(),
        ]);
        Incident::create([
            'website_id' => $website->id, 'type' => 'judol', 'severity' => 'critical',
            'title' => 'Judol', 'status' => 'open', 'detected_at' => now(),
        ]);

        Livewire::actingAs($user)->test(IncidentsIndex::class)->call('deleteAll');

        $this->assertSame(1, Incident::count());
        $this->assertSame('judol', Incident::first()->type);
    }

    public function test_the_manual_url_field_assumes_https_and_reports_the_ai_verdict(): void
    {
        $user = User::factory()->create();
        Setting::set('judol_ai_enabled', '1');
        Http::fake(['https://manual-ai.example/*' => Http::response('<title>Slot Gacor</title>', 200)]);

        $this->app->instance(JudolAiClassifier::class, new class(new JudolAiSettings) extends JudolAiClassifier
        {
            public function classify(string $url, string $text, array $matchedKeywords, array $imageUrls = []): array
            {
                return ['is_judol' => true, 'confidence' => 0.93, 'reasoning' => 'Banner promosi slot di kepala halaman.'];
            }
        });

        $website = Website::factory()->create(['url' => 'https://manual-ai.example']);

        $result = Livewire::actingAs($user)
            ->test(Show::class, ['website' => $website])
            // Typed without a scheme, exactly as somebody would paste it.
            ->set('manualScanUrl', 'manual-ai.example/slot.html')
            ->call('scanSpecificUrl')
            ->assertHasNoErrors()
            ->assertSee('Terverifikasi AI')
            ->assertSee('Banner promosi slot di kepala halaman.')
            ->get('manualScanResult');

        $this->assertSame('https://manual-ai.example/slot.html', $result['url']);
        $this->assertSame('Terverifikasi AI', $result['ai']['label']);
        $this->assertSame(0.93, $result['ai']['confidence']);
    }
}
