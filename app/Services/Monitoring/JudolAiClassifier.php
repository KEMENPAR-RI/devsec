<?php

namespace App\Services\Monitoring;

use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\Message;
use App\Support\AiText;
use Illuminate\Support\Facades\Http;
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
    /** Media types the Messages API accepts for images. */
    protected const SUPPORTED_MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Raw bytes accepted per image. The API caps an image at 5 MB and base64
     * inflates by ~4/3, so 3.5 MB of source bytes stays comfortably under it.
     */
    protected const MAX_IMAGE_BYTES = 3500000;

    /** The same browser UA the scanner uses - hotlink/WAF rules block plain bots. */
    protected const BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

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

        $prompt = [
            'type' => 'text',
            'text' => "URL: {$url}\nKata kunci yang cocok: ".implode(', ', $matchedKeywords)."\n\nIsi teks halaman:\n{$snippet}",
        ];

        $images = $this->imageBlocks($imageUrls, $url);

        try {
            $message = $this->send([...$images, $prompt]);
        } catch (Throwable $e) {
            // An image the API dislikes fails the WHOLE request, so one bad banner
            // would cost us the verdict on a page we already know matched keywords.
            // The text alone is still worth a second opinion: retry without the
            // pictures before reporting the AI as unavailable.
            if ($images === []) {
                return $this->unavailable($url, $e);
            }

            Log::warning("JudolAiClassifier: panggilan bergambar gagal untuk {$url}, mencoba ulang tanpa gambar: {$e->getMessage()}");

            try {
                $message = $this->send([$prompt]);
            } catch (Throwable $retry) {
                return $this->unavailable($url, $retry);
            }
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

        return [
            'is_judol' => null,
            'confidence' => 0.0,
            'reasoning' => 'AI menjawab, tetapi jawabannya tidak mengikuti format yang diminta '
                .'sehingga tidak bisa dibaca. Temuan ini masih bersandar pada kecocokan kata kunci saja.',
        ];
    }

    /**
     * @param  array<int, mixed>  $content
     */
    protected function send(array $content): Message
    {
        return $this->settings->client()->messages->create(
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
    }

    /**
     * Downloads each image and hands Claude the bytes, instead of passing the URL
     * for Anthropic to fetch itself.
     *
     * A URL source made the API responsible for the download, and it answers with
     * a flat 400 "Unable to download the file" whenever that fetch is refused -
     * which is the normal case on exactly the sites we scan: hotlink protection,
     * Cloudflare/WAF bot rules, an expired or mismatched TLS certificate, an
     * extension that lies about the real content type, a 404 page served as HTML.
     * One such image failed the entire classification, so the verdict came back
     * "AI unavailable" for the very pages most likely to be hacked. We can reach
     * these images (we just crawled the page they sit on, with browser headers),
     * so we fetch them here, verify the bytes really are a supported image, and
     * skip the ones that aren't - a bad image now costs one picture, not the
     * whole answer.
     *
     * @param  array<int, string>  $imageUrls
     * @return array<int, ImageBlockParam>
     */
    protected function imageBlocks(array $imageUrls, string $pageUrl): array
    {
        $blocks = [];

        foreach ($imageUrls as $imageUrl) {
            $body = $this->download($imageUrl, $pageUrl);

            if ($body === null) {
                continue;
            }

            // Sniffed from the bytes, never from the URL's extension or the
            // Content-Type header: a "banner.png" that is really an SVG, an HTML
            // error page, or a tracking pixel is exactly what the API rejects.
            $mediaType = $this->sniffMediaType($body);

            if (! $mediaType) {
                Log::debug("JudolAiClassifier: gambar {$imageUrl} dilewati (bukan format gambar yang didukung)");

                continue;
            }

            $blocks[] = ImageBlockParam::with(
                source: Base64ImageSource::with(data: base64_encode($body), mediaType: $mediaType)
            );
        }

        return $blocks;
    }

    /**
     * Raw bytes of one image, or null when it can't be fetched or is too big to
     * send. Never throws: a skipped image must not take the classification down.
     */
    protected function download(string $imageUrl, string $pageUrl): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::BROWSER_USER_AGENT,
                'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                // Hotlink protection serves a placeholder (or a 403) unless the
                // request looks like it came from the page the image sits on.
                'Referer' => $pageUrl,
            ])
                // Hacked sites routinely have broken or expired certificates; the
                // page itself was fetched the same way, and these bytes are only
                // ever read as an image, never trusted.
                ->withOptions(['verify' => false])
                ->timeout(10)
                ->connectTimeout(5)
                ->get($imageUrl);
        } catch (Throwable $e) {
            Log::debug("JudolAiClassifier: gambar {$imageUrl} gagal diunduh: {$e->getMessage()}");

            return null;
        }

        if (! $response->successful()) {
            Log::debug("JudolAiClassifier: gambar {$imageUrl} dilewati (HTTP {$response->status()})");

            return null;
        }

        $body = $response->body();
        $size = strlen($body);

        if ($size === 0 || $size > self::MAX_IMAGE_BYTES) {
            Log::debug("JudolAiClassifier: gambar {$imageUrl} dilewati (ukuran {$size} byte)");

            return null;
        }

        return $body;
    }

    /**
     * The real media type of the downloaded bytes, or null when they aren't an
     * image the Messages API can read.
     */
    protected function sniffMediaType(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? ($info['mime'] ?? null) : null;

        if (! $mime && function_exists('finfo_buffer')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_buffer($finfo, $bytes) : null;
        }

        return in_array($mime, self::SUPPORTED_MEDIA_TYPES, true) ? $mime : null;
    }

    /**
     * @return array{is_judol: ?bool, confidence: float, reasoning: string}
     */
    protected function unavailable(string $url, Throwable $e): array
    {
        Log::warning("JudolAiClassifier: gagal memanggil AI untuk {$url}: {$e->getMessage()}");

        // AI tidak tersedia -> is_judol null berarti "tidak tahu", BUKAN "bukan judol".
        // JudolScanner harus fallback ke hasil keyword match, bukan menganggap ini bantahan.
        return [
            'is_judol' => null,
            'confidence' => 0.0,
            // Ditulis dalam bahasa Indonesia seperti sisa aplikasi: teks ini tampil
            // apa adanya di panel "Temuan AI", di tempat yang sama dengan jawaban
            // model - jadi kalimat berbahasa Inggris di sini terbaca seolah AI yang
            // menjawab begitu, padahal AI justru tidak pernah menjawab.
            'reasoning' => 'AI tidak bisa dihubungi saat halaman ini dipindai, jadi temuan ini '
                .'masih bersandar pada kecocokan kata kunci saja. Penyebab teknis: '
                .$this->technicalCause($e),
        ];
    }

    /**
     * One readable line out of an exception, for the operator reading the panel.
     *
     * The SDK reports an API rejection as a pretty-printed JSON dump several lines
     * long; pasted straight into the UI it buried the message it was meant to
     * explain, so it is collapsed to a single line and cut short - the full text is
     * in the log either way.
     */
    protected function technicalCause(Throwable $e): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $e->getMessage()));

        return Str::limit($message !== '' ? $message : $e::class, 200);
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
