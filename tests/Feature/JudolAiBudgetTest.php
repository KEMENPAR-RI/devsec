<?php

namespace Tests\Feature;

use App\Models\JudolDetection;
use App\Models\Setting;
use App\Models\Website;
use App\Services\Monitoring\JudolAiClassifier;
use App\Services\Monitoring\JudolAiSettings;
use App\Services\Monitoring\JudolLinkClassifier;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

class JudolAiBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_exhausted_ai_budget_skips_further_ai_calls_and_the_scan_completes(): void
    {
        NotificationFacade::fake();
        Log::spy();
        Setting::set('judol_ai_enabled', '1');
        Setting::set('anthropic_api_key', Crypt::encryptString('sk-ant-test'));
        config(['judol.ai.budget_seconds' => 0.01]);

        $this->app->instance(JudolLinkClassifier::class, new class extends JudolLinkClassifier
        {
            public function __construct() {}

            public function gamblingDomains(array $links): array
            {
                return [];
            }
        });

        // Each call "takes" 20 ms, so the first one spends the whole 10 ms budget.
        $classifier = new class(new JudolAiSettings) extends JudolAiClassifier
        {
            public int $calls = 0;

            public function classify(string $url, string $text, array $matchedKeywords, array $imageUrls = []): array
            {
                $this->calls++;
                usleep(20000);

                return ['is_judol' => null, 'confidence' => 0.0, 'reasoning' => 'faked'];
            }
        };
        $this->app->instance(JudolAiClassifier::class, $classifier);

        $promo = '<p>Situs slot gacor terpercaya.</p>';
        Http::fake([
            'budget.example/sitemap.xml' => Http::response('', 404),
            'budget.example' => Http::response('<html><body><a href="/a">A</a><a href="/b">B</a>'.$promo.'</body></html>', 200),
            'budget.example/*' => Http::response('<html><body>'.$promo.'</body></html>', 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://budget.example', 'last_status' => 'up']);

        app(JudolScanner::class)->scan($website);

        $this->assertSame(1, $classifier->calls);
        // Pages past the budget are still judged by keywords alone.
        $this->assertSame(3, JudolDetection::where('website_id', $website->id)->count());
        $this->assertNotNull($website->fresh()->judol_scanned_at);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'anggaran waktu AI'))
            ->once();
    }
}
