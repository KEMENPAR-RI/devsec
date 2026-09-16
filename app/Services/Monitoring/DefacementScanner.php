<?php

namespace App\Services\Monitoring;

use App\Models\Website;
use App\Services\Security\OutboundUrlGuard;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Looks for code on a page that the site's own authors did not put there.
 *
 * The judol scanner answers "does this page talk about gambling", which catches
 * SEO spam. This answers a different question: "is something executing here that
 * does not belong", which is what an actual compromise looks like before anyone
 * bothers to add visible spam - a hidden iframe, a miner, a loader pulling its
 * real payload from somewhere else.
 *
 * Only the homepage is examined. Injected code is almost always added to a shared
 * template, so it is on the homepage too, and checking one page a day per site
 * keeps this cheap enough to actually run.
 */
class DefacementScanner
{
    /**
     * Script hosts that are ordinary on an Indonesian institutional site.
     *
     * An allow-list rather than a block-list: the whole point is to surface the
     * host nobody recognises, and a block-list can only ever list the payloads
     * somebody already wrote about.
     */
    public const TRUSTED_HOSTS = [
        'googletagmanager.com', 'google-analytics.com', 'googleapis.com', 'gstatic.com',
        'google.com', 'youtube.com', 'ytimg.com', 'doubleclick.net', 'facebook.net',
        'facebook.com', 'jquery.com', 'jsdelivr.net', 'cloudflare.com', 'cdnjs.com',
        'bootstrapcdn.com', 'unpkg.com', 'fontawesome.com', 'addtoany.com',
        'recaptcha.net', 'cloudflareinsights.com', 'tawk.to', 'whatsapp.com',
    ];

    /** Fragments that are all but diagnostic of injected code. */
    public const SUSPICIOUS_PATTERNS = [
        'coinhive' => 'Skrip penambang kripto (Coinhive)',
        'cryptonight' => 'Skrip penambang kripto (CryptoNight)',
        'coinimp' => 'Skrip penambang kripto (CoinIMP)',
        'webminepool' => 'Skrip penambang kripto (WebMinePool)',
        'eval(atob(' => 'eval() atas data base64 - penyamaran kode',
        'eval(unescape(' => 'eval() atas string ter-escape - penyamaran kode',
        'document.write(unescape(' => 'document.write() atas string ter-escape',
        'fromcharcode(' => 'Perakitan string lewat String.fromCharCode - penyamaran kode',
    ];

    /**
     * Everything unexpected on a site's homepage.
     *
     * @return array<int, array{jenis: string, detail: string}>
     */
    public function scan(Website $website): array
    {
        $html = $this->fetch($website->url);

        if ($html === null) {
            return [];
        }

        $host = parse_url($website->url, PHP_URL_HOST);
        $dom = $this->parse($html);

        if (! $dom) {
            return [];
        }

        return array_merge(
            $this->foreignScripts($dom, $website->url, $host),
            $this->hiddenFrames($dom),
            $this->suspiciousCode($html),
        );
    }

    /**
     * Scripts loaded from a host that is neither the site's own nor recognisable.
     *
     * @return array<int, array{jenis: string, detail: string}>
     */
    protected function foreignScripts(DOMDocument $dom, string $baseUrl, ?string $host): array
    {
        $findings = [];
        $seen = [];

        foreach ($dom->getElementsByTagName('script') as $script) {
            $src = trim((string) $script->getAttribute('src'));

            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }

            $scriptHost = parse_url($this->absolute($src, $baseUrl), PHP_URL_HOST);

            if (! $scriptHost || $this->isOwnOrTrusted($scriptHost, $host)) {
                continue;
            }

            // One line per host, not per tag: a loader usually appears several
            // times, and ten identical rows hide the one other host.
            if (isset($seen[$scriptHost])) {
                continue;
            }

            $seen[$scriptHost] = true;
            $findings[] = ['jenis' => 'Skrip dari domain asing', 'detail' => $scriptHost];
        }

        return $findings;
    }

    /**
     * Frames deliberately made invisible.
     *
     * A legitimate hidden iframe does exist - some payment and analytics widgets
     * use one - but on a government site it is far more often the delivery
     * mechanism for something else, and it is worth a human glance either way.
     *
     * @return array<int, array{jenis: string, detail: string}>
     */
    protected function hiddenFrames(DOMDocument $dom): array
    {
        $findings = [];

        foreach ($dom->getElementsByTagName('iframe') as $frame) {
            if (! $this->looksHidden($frame)) {
                continue;
            }

            $src = trim((string) $frame->getAttribute('src'));

            $findings[] = [
                'jenis' => 'Iframe tersembunyi',
                'detail' => $src !== '' ? Str::limit($src, 120) : 'tanpa src',
            ];
        }

        return $findings;
    }

    protected function looksHidden(DOMElement $frame): bool
    {
        $style = Str::lower(str_replace(' ', '', (string) $frame->getAttribute('style')));

        if (Str::contains($style, ['display:none', 'visibility:hidden', 'opacity:0'])) {
            return true;
        }

        // Zero-sized rather than merely small: a 1x1 tracking pixel is normal, a
        // 0x0 frame is only ever there to run something out of sight.
        $width = (int) $frame->getAttribute('width');
        $height = (int) $frame->getAttribute('height');

        return $frame->hasAttribute('width') && $frame->hasAttribute('height') && $width <= 1 && $height <= 1;
    }

    /**
     * @return array<int, array{jenis: string, detail: string}>
     */
    protected function suspiciousCode(string $html): array
    {
        $haystack = Str::lower($html);
        $findings = [];

        foreach (self::SUSPICIOUS_PATTERNS as $needle => $label) {
            if (str_contains($haystack, $needle)) {
                $findings[] = ['jenis' => 'Pola kode mencurigakan', 'detail' => $label];
            }
        }

        return $findings;
    }

    /** The site's own host, a subdomain of it, or a name everyone loads anyway. */
    protected function isOwnOrTrusted(string $scriptHost, ?string $host): bool
    {
        $scriptHost = Str::lower($scriptHost);

        if ($host && ($scriptHost === Str::lower($host) || Str::endsWith($scriptHost, '.'.Str::lower($host)))) {
            return true;
        }

        foreach (self::TRUSTED_HOSTS as $trusted) {
            if ($scriptHost === $trusted || Str::endsWith($scriptHost, '.'.$trusted)) {
                return true;
            }
        }

        return false;
    }

    protected function absolute(string $src, string $baseUrl): string
    {
        if (Str::startsWith($src, ['http://', 'https://'])) {
            return $src;
        }

        if (Str::startsWith($src, '//')) {
            return 'https:'.$src;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';

        return Str::startsWith($src, '/')
            ? "{$scheme}://{$host}{$src}"
            : "{$scheme}://{$host}/".ltrim($src, './');
    }

    protected function fetch(string $url): ?string
    {
        if (OutboundUrlGuard::reason($url)) {
            return null;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => JudolScanner::BROWSER_USER_AGENT])
                ->timeout(15)
                ->connectTimeout(10)
                ->withOptions(OutboundUrlGuard::httpOptions())
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    protected function parse(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // Real-world markup is malformed constantly; a parse error must not be
        // the reason a compromise goes unseen.
        $ok = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok ? $dom : null;
    }
}
