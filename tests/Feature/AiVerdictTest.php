<?php

namespace Tests\Feature;

use App\Models\JudolDetection;
use App\Models\JudolKeyword;
use App\Models\Setting;
use App\Models\Website;
use App\Services\Monitoring\JudolAiClassifier;
use App\Services\Monitoring\JudolAiSettings;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

class AiVerdictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        NotificationFacade::fake();
        JudolKeyword::firstOrCreate(['keyword' => 'slot gacor'], ['is_active' => true]);
        Http::fake(['verdict.example' => Http::response('<title>Slot Gacor</title>', 200)]);
    }

    public function test_a_detection_records_that_the_ai_confirmed_it(): void
    {
        Setting::set('judol_ai_enabled', '1');
        Setting::set('anthropic_api_key', Crypt::encryptString('sk-ant-test'));
        $this->fakeClassifier(['is_judol' => true, 'confidence' => 0.95, 'reasoning' => 'Jelas promosi slot.']);

        $detection = $this->scan();

        $this->assertSame('confirmed', $detection->ai_verdict);
        $this->assertSame(0.95, $detection->ai_confidence);
        $this->assertSame('Jelas promosi slot.', $detection->ai_reasoning);
        $this->assertNotNull($detection->ai_checked_at);
        $this->assertSame('Terverifikasi AI', $detection->aiBadge()['label']);
    }

    public function test_a_denial_below_the_threshold_is_kept_but_marked_disputed(): void
    {
        // The keyword match still stands - the AI just wasn't sure enough to drop it.
        Setting::set('judol_ai_enabled', '1');
        Setting::set('anthropic_api_key', Crypt::encryptString('sk-ant-test'));
        config(['judol.ai.confidence_threshold' => 0.8]);
        $this->fakeClassifier(['is_judol' => false, 'confidence' => 0.4, 'reasoning' => 'Mungkin artikel berita.']);

        $detection = $this->scan();

        $this->assertSame('disputed', $detection->ai_verdict);
        $this->assertSame('AI ragu', $detection->aiBadge()['label']);
    }

    public function test_a_failed_call_is_recorded_as_unavailable_not_as_a_verdict(): void
    {
        Setting::set('judol_ai_enabled', '1');
        Setting::set('anthropic_api_key', Crypt::encryptString('sk-ant-test'));
        $this->fakeClassifier(['is_judol' => null, 'confidence' => 0.0, 'reasoning' => 'AI unavailable: timeout']);

        $detection = $this->scan();

        $this->assertSame('unavailable', $detection->ai_verdict);
        $this->assertSame('AI gagal dicek', $detection->aiBadge()['label']);
    }

    public function test_with_the_classifier_off_the_detection_is_marked_keyword_only(): void
    {
        Setting::set('judol_ai_enabled', '0');

        $detection = $this->scan();

        $this->assertNull($detection->ai_verdict);
        $this->assertNull($detection->ai_checked_at);
        $this->assertSame('Keyword saja', $detection->aiBadge()['label']);
    }

    public function test_the_ai_is_never_called_for_a_page_the_keyword_filter_cleared(): void
    {
        // This is what keeps the bill near zero: the classifier only ever vets pages
        // the free keyword filter already flagged, never every crawled page.
        Setting::set('judol_ai_enabled', '1');
        Setting::set('anthropic_api_key', Crypt::encryptString('sk-ant-test'));
        Http::fake(['clean.example' => Http::response('<title>Halaman biasa tanpa apa pun</title>', 200)]);

        $calls = 0;
        $this->app->instance(JudolAiClassifier::class, new class(new JudolAiSettings, $calls) extends JudolAiClassifier
        {
            public function __construct(JudolAiSettings $settings, public int &$calls)
            {
                parent::__construct($settings);
            }

            public function classify(string $url, string $text, array $matchedKeywords, array $imageUrls = []): array
            {
                $this->calls++;

                return ['is_judol' => true, 'confidence' => 1.0, 'reasoning' => 'x'];
            }
        });

        $website = Website::factory()->create(['url' => 'http://clean.example']);
        (new JudolScanner)->scanUrlNow($website, 'http://clean.example');

        $this->assertSame(0, $calls, 'no keyword match means no API call');
        $this->assertSame(0, JudolDetection::count());
    }

    /**
     * The classifier's verdict is still recorded in full; what changed is that the
     * Deteksi Judol page no longer lists pages, so there is no per-detection AI
     * panel and no "Belum diverifikasi AI" filter left to assert against. The badge
     * is the surviving reading of that verdict, so that is what is checked here.
     */
    public function test_the_verdict_is_summarised_for_display(): void
    {
        $website = Website::factory()->create();

        $detection = JudolDetection::create([
            'website_id' => $website->id,
            'url' => 'http://x.example/ai',
            'matched_keywords' => ['slot gacor'],
            'status' => 'new',
            'detected_at' => now(),
            'ai_verdict' => 'confirmed',
            'ai_confidence' => 0.95,
            'ai_reasoning' => "Gambar menampilkan logo 'Slot Lions 88' di atas latar kasino.",
            'ai_checked_at' => now(),
        ]);

        $badge = $detection->aiBadge();

        $this->assertSame('Terverifikasi AI', $badge['label']);
        $this->assertSame('accent', $badge['tone']);
        $this->assertStringContainsString('Slot Lions 88', $badge['detail']);
        $this->assertStringContainsString('keyakinan 95%', $badge['detail']);
    }

    /**
     * @param  array{is_judol: ?bool, confidence: float, reasoning: string}  $verdict
     */
    protected function fakeClassifier(array $verdict): void
    {
        $this->app->instance(JudolAiClassifier::class, new class(new JudolAiSettings, $verdict) extends JudolAiClassifier
        {
            public function __construct(JudolAiSettings $settings, protected array $verdict)
            {
                parent::__construct($settings);
            }

            public function classify(string $url, string $text, array $matchedKeywords, array $imageUrls = []): array
            {
                return $this->verdict;
            }
        });
    }

    protected function scan(): JudolDetection
    {
        $website = Website::factory()->create(['url' => 'http://verdict.example']);
        (new JudolScanner)->scanUrlNow($website, 'http://verdict.example');

        return JudolDetection::where('website_id', $website->id)->firstOrFail();
    }
}
