<?php

namespace Tests\Feature;

use App\Models\JudolKeyword;
use App\Services\Monitoring\JudolAiClassifier;
use App\Services\Monitoring\JudolAiSettings;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiClassifierRobustnessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression: the model wraps its JSON in a ```json fence despite being told
     * not to, and a strict json_decode() of the whole reply then failed - which was
     * reported as "AI unavailable" and silently downgraded every single verdict.
     */
    public function test_a_verdict_wrapped_in_a_code_fence_is_still_read(): void
    {
        $reply = "```json\n{\n  \"is_judol\": true,\n  \"confidence\": 0.95,\n  \"reasoning\": \"Promosi slot.\"\n}\n```";

        $this->assertSame(
            ['is_judol' => true, 'confidence' => 0.95, 'reasoning' => 'Promosi slot.'],
            $this->decode($reply)
        );
    }

    public function test_a_verdict_with_prose_around_it_is_still_read(): void
    {
        $reply = 'Berikut hasilnya: {"is_judol": false, "confidence": 0.7, "reasoning": "Artikel berita."} Semoga membantu.';

        $this->assertSame(
            ['is_judol' => false, 'confidence' => 0.7, 'reasoning' => 'Artikel berita.'],
            $this->decode($reply)
        );
    }

    public function test_a_reply_with_no_json_at_all_is_reported_as_unknown_not_guessed(): void
    {
        $verdict = $this->decode('Maaf, saya tidak bisa menilai halaman ini.');

        $this->assertNull($verdict['is_judol'], 'unknown, so the caller keeps trusting the keyword match');
    }

    /**
     * Regression: an <img src="#"> resolves to the page itself, and sending that
     * HTML to the vision API made it reject the ENTIRE request - one bad image took
     * down the whole classification.
     */
    public function test_only_real_image_urls_are_offered_to_the_classifier(): void
    {
        JudolKeyword::firstOrCreate(['keyword' => 'slot gacor'], ['is_active' => true]);

        $html = '<body>slot gacor'
            .'<img src="/banner.png">'
            .'<img src="/logo.PNG?v=2">'
            .'<img src="#">'
            .'<img src="/pixel">'
            .'<img src="/icon.svg">'
            .'<img src="data:image/png;base64,AAAA">'
            .'</body>';

        $scanner = new class(app(JudolAiSettings::class)) extends JudolScanner
        {
            public function __construct(protected JudolAiSettings $settings) {}

            public function imagesFor(string $html, string $baseUrl): array
            {
                return $this->extractImages($html, $baseUrl);
            }
        };

        $images = $scanner->imagesFor($html, 'http://img.example/page');

        $this->assertSame(
            ['http://img.example/banner.png', 'http://img.example/logo.PNG?v=2'],
            $images
        );
    }

    /**
     * @return array{is_judol: ?bool, confidence: float, reasoning: string}
     */
    protected function decode(string $reply): array
    {
        $classifier = new class(app(JudolAiSettings::class)) extends JudolAiClassifier
        {
            public function readVerdict(string $text): ?array
            {
                return $this->decodeVerdict($text);
            }
        };

        $decoded = $classifier->readVerdict($reply);

        return [
            'is_judol' => $decoded ? (bool) $decoded['is_judol'] : null,
            'confidence' => (float) ($decoded['confidence'] ?? 0),
            'reasoning' => (string) ($decoded['reasoning'] ?? ''),
        ];
    }
}
