<?php

namespace App\Services\Monitoring;

use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\URLImageSource;
use App\Support\AiText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Second-opinion layer on top of JudolScanner's keyword filter: Claude confirms
 * or denies a keyword match by reading the page's own text AND any promotional
 * images on it (banners like "SLOT GACOR" often carry their pitch as an image,
 * not HTML text, which is invisible to a pure keyword filter). Only called for
 * pages that already cleared the free keyword filter, so cost stays near-zero
 * on clean sites - see config('judol.ai').
 */
class JudolAiClassifier
{
    public function __construct(protected JudolAiSettings $settings) {}

    /**
     * @param  array<int, string>  $matchedKeywords
     * @param  array<int, string>  $imageUrls  absolute URLs of images found on the page
     * @return array{is_judol: ?bool, confidence: float, reasoning: string} is_judol is null
     *                                                                      when the AI couldn't be reached or its answer couldn't be parsed - the caller
     *                                                                      must treat that as "unknown", NOT as a denial, and fall back to the keyword
     *                                                                      match result instead of silently dropping the detection.
     */
    public function classify(string $url, string $text, array $matchedKeywords, array $imageUrls = []): array
    {
        $snippet = Str::limit(trim($text), 4000, '');
        $imageUrls = array_slice($imageUrls, 0, $this->settings->maxImages());

        $content = [];

        foreach ($imageUrls as $imageUrl) {
            // Typed objects, not a plain array: the SDK's source union resolves an
            // array to its first variant (base64), which the API then rejects as
            // "file format is invalid" - every image-bearing page silently failed.
            $content[] = ImageBlockParam::with(source: URLImageSource::with(url: $imageUrl));
        }

        $content[] = [
            'type' => 'text',
            'text' => "URL: {$url}\nKata kunci yang cocok: ".implode(', ', $matchedKeywords)."\n\nIsi teks halaman:\n{$snippet}",
        ];

        try {
            $message = $this->settings->client()->messages->create(
                model: $this->settings->model(),
                maxTokens: 300,
                system: <<<'SYS'
                Kamu pengklasifikasi konten. Tentukan apakah halaman web ini benar-benar
                SPAM SEO judi online ("judol") - biasanya hasil peretasan yang menyisipkan
                promosi slot/togel/casino ke situs yang tidak terkait - atau BUKAN (mis.
                berita yang membahas judi online, halaman kebijakan yang menyebut kata itu
                sekilas). Kalau ada gambar disertakan, baca juga teks di dalamnya (banner
                promosi sering menaruh teksnya di gambar, bukan di HTML) dan nilai apakah
                gambar itu memang promosi judi.

                Balas HANYA JSON valid, tanpa teks lain, skema persis:
                {"is_judol": boolean, "confidence": number 0-1, "reasoning": string singkat}
                SYS,
                messages: [['role' => 'user', 'content' => $content]],
            );
        } catch (Throwable $e) {
            Log::warning("JudolAiClassifier: gagal memanggil AI untuk {$url}: {$e->getMessage()}");

            // AI tidak tersedia -> is_judol null berarti "tidak tahu", BUKAN "bukan judol".
            // JudolScanner harus fallback ke hasil keyword match, bukan menganggap ini bantahan.
            return ['is_judol' => null, 'confidence' => 0.0, 'reasoning' => 'AI unavailable: '.$e->getMessage()];
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text' && $decoded = $this->decodeVerdict($block->text)) {
                return [
                    'is_judol' => (bool) $decoded['is_judol'],
                    'confidence' => (float) ($decoded['confidence'] ?? 0),
                    // Cleaned like the incident analysis: the reasoning is shown
                    // verbatim in the UI, so Markdown must not leak into it.
                    'reasoning' => AiText::clean((string) ($decoded['reasoning'] ?? '')),
                ];
            }
        }

        Log::warning("JudolAiClassifier: respons AI tidak bisa di-parse untuk {$url}");

        return ['is_judol' => null, 'confidence' => 0.0, 'reasoning' => 'AI response unparseable'];
    }

    /**
     * Pulls the verdict object out of the model's reply.
     *
     * "Balas HANYA JSON valid" in the prompt is not enough on its own - models
     * habitually wrap the object in a ```json fence, or add a sentence before it -
     * and a strict json_decode() of the whole reply then fails, which used to be
     * reported as "AI unavailable" and quietly downgraded every verdict.
     *
     * @return array<string, mixed>|null
     */
    protected function decodeVerdict(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) && array_key_exists('is_judol', $decoded) ? $decoded : null;
    }
}
