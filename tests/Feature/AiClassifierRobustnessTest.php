<?php

namespace Tests\Feature;

use Anthropic\Messages\ImageBlockParam;
use App\Models\JudolKeyword;
use App\Services\Monitoring\JudolAiClassifier;
use App\Services\Monitoring\JudolAiSettings;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
     * Regression: images used to be passed to the API as URLs for Anthropic to
     * download itself, and any host that refused that fetch (hotlink protection,
     * a WAF, a bad certificate, an extension lying about the content type) got the
     * whole request rejected with 400 "Unable to download the file" - so hacked
     * sites, the ones we most need a verdict on, came back "AI unavailable".
     * Images are now fetched here and sent as bytes, and an unusable one is
     * dropped on its own instead of taking the classification with it.
     */
    public function test_unusable_images_are_dropped_instead_of_being_sent_to_the_api(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        Http::fake([
            'img.example/ok.png' => Http::response($png, 200),
            // Hotlink protection / WAF - the exact case that used to 400 the request.
            'img.example/blocked.png' => Http::response('forbidden', 403),
            // A "png" that is really an HTML error page.
            'img.example/liar.png' => Http::response('<!doctype html><h1>404</h1>', 200),
        ]);

        $blocks = $this->classifier()->blocksFor([
            'http://img.example/ok.png',
            'http://img.example/blocked.png',
            'http://img.example/liar.png',
        ], 'http://img.example/page');

        $this->assertCount(1, $blocks, 'only the image that really is an image survives');
        $this->assertSame('base64', $blocks[0]->source->type);
        $this->assertSame('image/png', $blocks[0]->source->mediaType);
        $this->assertSame(base64_encode($png), $blocks[0]->source->data);
    }

    /**
     * The fallback text lands in the same "Temuan AI" panel as the model's own
     * answer, so an English sentence there reads as if the AI had replied in
     * English - when in fact it never replied at all. It also used to carry the
     * SDK's multi-line JSON dump verbatim, which buried the explanation.
     */
    public function test_the_failure_notice_is_indonesian_and_fits_on_one_line(): void
    {
        $dump = "Anthropic Bad Request Exception {\n  \"status\": 400,\n  \"body\": {\n    \"message\": \"Unable to download the file.\"\n  }\n}";

        $reasoning = $this->classifier()->failureNotice(new \RuntimeException($dump));

        $this->assertStringStartsWith('AI tidak bisa dihubungi', $reasoning);
        $this->assertStringNotContainsString("\n", $reasoning, 'a JSON dump must not break the panel');
        $this->assertStringContainsString('Unable to download the file.', $reasoning, 'the operator still needs the cause');
    }

    /**
     * @return array{is_judol: ?bool, confidence: float, reasoning: string}
     */
    protected function decode(string $reply): array
    {
        $decoded = $this->classifier()->readVerdict($reply);

        return [
            'is_judol' => $decoded ? (bool) $decoded['is_judol'] : null,
            'confidence' => (float) ($decoded['confidence'] ?? 0),
            'reasoning' => (string) ($decoded['reasoning'] ?? ''),
        ];
    }

    /**
     * The classifier with its two internal steps (verdict parsing, image
     * gathering) exposed, so each can be exercised without an API key.
     */
    protected function classifier(): JudolAiClassifier
    {
        return new class(app(JudolAiSettings::class)) extends JudolAiClassifier
        {
            public function readVerdict(string $text): ?array
            {
                return $this->decodeVerdict($text);
            }

            /** @return array<int, ImageBlockParam> */
            public function blocksFor(array $imageUrls, string $pageUrl): array
            {
                return $this->imageBlocks($imageUrls, $pageUrl);
            }

            public function failureNotice(\Throwable $e): string
            {
                return $this->unavailable('http://example.test/page', $e)['reasoning'];
            }
        };
    }
}
