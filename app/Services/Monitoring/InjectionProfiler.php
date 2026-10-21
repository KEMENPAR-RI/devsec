<?php

namespace App\Services\Monitoring;

use App\Services\AppSettings;
use App\Support\ElementIdentity;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * Builds a page's InjectionProfile from the DOM PageTextExtractor already parsed
 * (no second parse). Keys hold normalized structure only - no content text,
 * nonce or cache-buster - so dynamic content never changes them.
 */
class InjectionProfiler
{
    /** Hiding methods only an attacker uses on links; display/visibility/attr are ordinary menus. */
    protected const STEALTH_METHODS = ['offscreen', 'fontsize0', 'opacity', 'clip', 'size0', 'color=bg'];

    protected const JS_TYPES = ['', 'text/javascript', 'application/javascript', 'module', 'text/ecmascript', 'application/ecmascript'];

    /** @var array<string, mixed> config('defacement.injection') */
    protected array $config = [];

    protected ?string $pageDomain = null;

    protected PageTextExtractor $extractor;

    protected bool $complete = true;

    /** @var array<string, array<string, mixed>> */
    protected array $items = [];

    public function __construct(protected LinkFarmDetector $linkFarm, protected UrlKeywordMatcher $urlKeywords) {}

    /** $oversizeBytes: body length when it is over max_html_bytes - the profile is then partial. */
    public function profile(DOMDocument $dom, ?string $baseUrl, PageTextExtractor $extractor, bool $complete, ?int $oversizeBytes = null): InjectionProfile
    {
        $this->config = (array) config('defacement.injection', []);
        $this->extractor = $extractor;
        $this->pageDomain = $extractor->pageDomain($baseUrl);
        $this->complete = $complete;
        $this->items = [];
        $xpath = new DOMXPath($dom);

        if ($oversizeBytes !== null) {
            // Judged differentially: a page that grew past the limit since its baseline is a warning.
            $this->complete = false;
            $this->add('np|oversize', 'not_profiled', false, sprintf('Halaman %.1f MB melebihi batas profil; dinilai sebagian', $oversizeBytes / 1048576));
        }

        try {
            $inlineFlagged = $this->scripts($xpath, $baseUrl);
            $this->frames($xpath, $baseUrl);
            $this->metaRefresh($xpath, $baseUrl);
            $this->titleKeys($xpath);
            [$links, $stats] = $this->links($xpath, $baseUrl);

            return new InjectionProfile(
                $this->capped(),
                $stats + ['inline_flagged' => $inlineFlagged],
                $links,
                $this->complete,
            );
        } finally {
            $this->items = [];
        }
    }

    /** External <script src> outside the site and allow-list, and inline scripts with suspicious features. */
    protected function scripts(DOMXPath $xpath, ?string $base): int
    {
        $maxInline = (int) ($this->config['max_inline_scripts'] ?? 300);
        $maxBytes = (int) ($this->config['max_inline_script_bytes'] ?? 102400);
        $inline = 0;
        $flagged = 0;

        foreach ($xpath->query('//script') ?: [] as $script) {
            /** @var DOMElement $script */
            // Attacker-controlled length: never matched beyond max_url_length.
            $src = substr(trim($script->getAttribute('src')), 0, (int) ($this->config['max_url_length'] ?? 2048));

            if ($src !== '') {
                $this->miner(Str::lower($src));
                $this->foreignScript($src, $base);

                continue;
            }

            $type = Str::lower(trim($script->getAttribute('type')));

            if (! in_array($type, self::JS_TYPES, true) || trim($script->textContent) === '') {
                continue;
            }

            if (++$inline > $maxInline) {
                $this->truncated('scripts', "Lebih dari {$maxInline} skrip inline: sisanya tidak diperiksa");

                break;
            }

            $code = substr($script->textContent, 0, $maxBytes);
            $this->miner(Str::lower($code));
            $flagged += $this->inlineScript($code) ? 1 : 0;
        }

        return $flagged;
    }

    protected function foreignScript(string $src, ?string $base): void
    {
        if (Str::startsWith(Str::lower($src), 'data:')) {
            return;
        }

        $normalized = ElementIdentity::normalizeUrl($src, $base);

        if ($normalized === null) {
            return;
        }

        $host = Str::before($normalized, '/');
        $domain = $this->extractor->domainOf($host);

        if ($this->own($domain) || $this->trusted($domain, 'trusted_script_domains')) {
            return;
        }

        // A loader whose URL names a judol keyword or a miner is judged even without a baseline.
        $this->add('xs|'.$domain.'/'.Str::after($normalized, '/'), 'foreign_script', false, $normalized, absolute: $this->suspiciousUrl($src));
    }

    protected function suspiciousUrl(string $url): bool
    {
        $lower = Str::lower($url);

        foreach (array_keys((array) ($this->config['miner_patterns'] ?? [])) as $needle) {
            if (str_contains($lower, (string) $needle)) {
                return true;
            }
        }

        $keywords = $this->urlKeywords->match($url);

        return $keywords !== [] && array_sum($keywords) >= app(AppSettings::class)->judolScoreThreshold();
    }

    protected function miner(string $haystack): void
    {
        foreach ((array) ($this->config['miner_patterns'] ?? []) as $needle => $label) {
            if (str_contains($haystack, (string) $needle)) {
                $this->add('mnr|'.$needle, 'cryptominer', true, 'Skrip penambang kripto ('.$label.')', absolute: true);
            }
        }
    }

    /** Whether the inline script got a key. Only the feature set is keyed, never the code itself. */
    protected function inlineScript(string $code): bool
    {
        $features = [];
        $test = fn (string $pattern) => preg_match($pattern, $code) === 1;

        $eval = $test('/\beval\s*\(/');
        $atob = $test('/\batob\s*\(/');
        $unescape = $test('/\bunescape\s*\(/');
        $fromCharCode = $test('/fromcharcode\s*\(/i');
        $docWrite = $test('/document\.write(?:ln)?\s*\(/i');
        $docWriteEscaped = $test('/document\.write(?:ln)?\s*\(\s*(?:unescape|atob|decodeURIComponent|String\.fromCharCode)\s*\(/i');
        $longB64 = $test('/[A-Za-z0-9+\/]{'.max(50, (int) ($this->config['long_base64_chars'] ?? 200)).',}/');
        $hexDense = (int) preg_match_all('/\\\\x[0-9a-f]{2}|\\\\u[0-9a-f]{4}/i', $code) >= (int) ($this->config['hex_escape_min'] ?? 50);
        $foreignHosts = $this->redirectHosts($code);
        $cloak = $test('/navigator\.userAgent|document\.referrer/i')
            && $test('/googlebot|bingbot|slurp|yandex|baiduspider|duckduckbot|google\.|bing\.|yahoo\./i')
            && $test('/location|document\.write|innerHTML/i');

        foreach (compact('eval', 'atob', 'unescape', 'fromCharCode', 'docWriteEscaped', 'longB64', 'hexDense', 'cloak') as $name => $on) {
            if ($on) {
                $features[] = Str::snake($name);
            }
        }

        if ($foreignHosts !== []) {
            $features[] = 'js_redirect_foreign';
        }

        $decode = $atob || $unescape || $fromCharCode || $longB64 || $hexDense;
        $rules = [];

        if ($cloak) {
            $rules[] = 'cloaking';
        }

        if ($foreignHosts !== []) {
            $rules[] = 'js_redirect';
        }

        if (($eval && $decode) || $docWriteEscaped || ($hexDense && ($atob || $unescape || $fromCharCode))) {
            $rules[] = 'obfuscated_js';
        }

        if ($rules === [] && $docWrite) {
            $rules[] = 'document_write';
            $features[] = 'doc_write';
        }

        if ($rules === []) {
            return false;
        }

        sort($features);
        $weights = (array) ($this->config['weights'] ?? []);
        usort($rules, fn (string $a, string $b) => ($weights[$b] ?? 0) <=> ($weights[$a] ?? 0));
        $weight = array_sum(array_map(fn (string $rule) => (int) ($weights[$rule] ?? 0), $rules));
        $key = 'ijs|'.implode(',', $features).'|'.ElementIdentity::shortHash(implode(',', $features).'|'.implode(',', $foreignHosts));
        $detail = 'Skrip inline: '.implode(', ', $features).($foreignHosts !== [] ? ' → '.implode(', ', $foreignHosts) : '');

        $this->add($key, $rules[0], false, $detail, $weight, $cloak || $foreignHosts !== [] || ($eval && $decode) || $docWriteEscaped);

        return true;
    }

    /** @return array<int, string> foreign hosts a script sends the visitor to */
    protected function redirectHosts(string $code): array
    {
        if (! preg_match_all('/\blocation(?:\.href)?\s*(?:=(?!=)|\.(?:replace|assign)\s*\()\s*[\'"`]((?:https?:)?\/\/[^\'"`\s\\\\]+)/i', $code, $m)) {
            return [];
        }

        $hosts = [];

        foreach (array_slice($m[1], 0, 20) as $target) {
            $host = Str::lower((string) parse_url(str_starts_with($target, '//') ? 'https:'.$target : $target, PHP_URL_HOST));

            if ($host === '') {
                continue;
            }

            $domain = $this->extractor->domainOf($host);

            if (! $this->own($domain) && ! $this->trusted($domain, 'trusted_script_domains')) {
                $hosts[$domain] = true;
            }
        }

        $hosts = array_keys($hosts);
        sort($hosts);

        return $hosts;
    }

    protected function frames(DOMXPath $xpath, ?string $base): void
    {
        foreach ($xpath->query('//iframe') ?: [] as $frame) {
            /** @var DOMElement $frame */
            $src = trim($frame->getAttribute('src'));
            $domain = null;

            if ($frame->hasAttribute('srcdoc')) {
                $id = 'srcdoc:'.ElementIdentity::shortHash($frame->getAttribute('srcdoc'));
            } elseif ($src === '' || Str::lower($src) === 'about:blank') {
                $id = 'about:blank';
            } elseif (Str::startsWith(Str::lower($src), 'data:')) {
                $id = 'data:'.ElementIdentity::shortHash($src);
            } else {
                $normalized = ElementIdentity::normalizeUrl($src, $base);

                if ($normalized === null) {
                    continue;
                }

                $domain = $this->extractor->domainOf(Str::before($normalized, '/'));

                if ($this->own($domain) || $this->trusted($domain, 'trusted_frame_domains')) {
                    continue;
                }

                $id = $domain;
            }

            // Zero-sized rather than small: a 1x1 pixel is normal, a 0x0 frame runs out of sight.
            $hidden = $this->extractor->isHidden($frame)
                || ($frame->hasAttribute('width') && $frame->hasAttribute('height') && (int) $frame->getAttribute('width') <= 1 && (int) $frame->getAttribute('height') <= 1);

            if ($hidden) {
                $this->add('ifr|'.$id.'|h', 'hidden_frame', true, $src !== '' ? Str::limit($src, 200, '') : 'tanpa src', absolute: true);
            } elseif ($domain !== null) {
                $this->add('ifr|'.$id.'|v', 'foreign_frame', false, Str::limit($src, 200, ''));
            }
        }
    }

    protected function metaRefresh(DOMXPath $xpath, ?string $base): void
    {
        foreach ($xpath->query('//meta[@http-equiv]') ?: [] as $meta) {
            /** @var DOMElement $meta */
            if (Str::lower(trim($meta->getAttribute('http-equiv'))) !== 'refresh') {
                continue;
            }

            $target = Str::after(ElementIdentity::metaValue('refresh', $meta->getAttribute('content'), $base), ';');

            if ($target === '') {
                continue;
            }

            $domain = $this->extractor->domainOf(Str::before($target, '/'));

            if (! $this->own($domain)) {
                $this->add('mr|'.$domain, 'meta_refresh', false, $target, absolute: true);
            }
        }
    }

    protected function titleKeys(DOMXPath $xpath): void
    {
        $title = TextNormalizer::clean((string) (($xpath->query('//title') ?: null)?->item(0)?->textContent ?? ''));

        if ($title !== '') {
            $this->add('ttl|'.sha1(TextNormalizer::fold(TextNormalizer::maskDynamic($title))), 'title_changed', false, Str::limit($title, 200, ''));
        }

        foreach ($xpath->query('//meta[@name]') ?: [] as $meta) {
            /** @var DOMElement $meta */
            if (Str::lower(trim($meta->getAttribute('name'))) !== 'description') {
                continue;
            }

            $description = TextNormalizer::clean($meta->getAttribute('content'));

            if ($description !== '') {
                $this->add('mdesc|'.sha1(TextNormalizer::fold(TextNormalizer::maskDynamic($description))), 'description_changed', false, Str::limit($description, 200, ''));
            }

            break;
        }
    }

    /**
     * Off-site <a href>: hidden ones per domain and per hiding container,
     * visible ones as weightless domain keys, plus judol keywords in the URL.
     *
     * @return array{0: array<int, array{url: string, domain: string, hidden: bool}>, 1: array<string, int|float>}
     */
    protected function links(DOMXPath $xpath, ?string $base): array
    {
        $max = (int) ($this->config['max_links'] ?? 3000);
        $maxLength = (int) ($this->config['max_url_length'] ?? 2048);
        $ignored = array_map(Str::lower(...), (array) config('judol.ignored_link_hosts', []));
        $links = [];
        $domains = [];
        $hiddenCount = 0;
        $containers = [];
        $seen = 0;

        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            /** @var DOMElement $anchor */
            $href = trim($anchor->getAttribute('href'));

            if ($href === '' || $href[0] === '#' || Str::startsWith(Str::lower($href), ['javascript:', 'mailto:', 'tel:'])) {
                continue;
            }

            $url = ElementIdentity::resolve(trim(str_replace(["\t", "\n", "\r"], '', $href)), $base);
            $host = $url !== null ? Str::lower((string) parse_url($url, PHP_URL_HOST)) : '';

            if ($host === '') {
                continue;
            }

            $domain = $this->extractor->domainOf($host);

            if ($this->own($domain) || $this->listed($domain, $ignored)) {
                continue;
            }

            // Only valid external links count toward the cap, so "#" padding cannot push a payload past it.
            if (++$seen > $max) {
                $this->truncated('links', "Lebih dari {$max} link eksternal: sisanya tidak diperiksa");

                break;
            }

            $hidden = $this->extractor->isHidden($anchor);
            $links[] = ['url' => substr($url, 0, $maxLength), 'domain' => $domain, 'hidden' => $hidden];
            $domains[$domain] = true;

            if (! $hidden) {
                $this->add('xd|'.$domain, 'external_domain', false, $domain, 0);

                continue;
            }

            $hiddenCount++;
            $container = $this->hidingContainer($anchor);
            $stealth = in_array($container[1] ?? null, self::STEALTH_METHODS, true);
            $this->add('hlnk|'.$domain, 'hidden_links', true, $domain, absolute: $stealth);

            if ($stealth) {
                $this->items['hlnk|'.$domain]['absolute'] = true;
            }

            if ($container) {
                $id = spl_object_id($container[0]);
                $containers[$id] ??= ['tag' => Str::lower($container[0]->nodeName), 'method' => $container[1], 'count' => 0, 'domains' => []];
                $containers[$id]['count']++;
                $containers[$id]['domains'][$domain] = true;
            }
        }

        foreach ($containers as $block) {
            if ($block['count'] >= (int) ($this->config['hidden_block_min_links'] ?? 3)) {
                $this->add(
                    'hblk|'.$block['tag'].'|'.$block['method'],
                    'hidden_link_block',
                    true,
                    sprintf('<%s> (%s): %d link → %s', $block['tag'], $block['method'], $block['count'], implode(', ', array_slice(array_keys($block['domains']), 0, 5))),
                    absolute: in_array($block['method'], self::STEALTH_METHODS, true),
                );
            }
        }

        $judol = $this->linkFarm->detect($links)['domains'];

        foreach ($judol as $domain => $match) {
            $this->add('jl|'.$domain, 'judol_link', $match['hidden'], $domain, absolute: true, keywords: $match['keywords']);
        }

        // Hidden links next to a judol link are no "Link Terkait" menu.
        if ($judol !== []) {
            foreach ($this->items as $key => $item) {
                if (str_starts_with($key, 'hlnk|') || str_starts_with($key, 'hblk|')) {
                    $this->items[$key]['absolute'] = true;
                }
            }
        }

        $total = count($links);

        return [$links, [
            'external_links' => $total,
            'external_domains' => count($domains),
            'hidden_external_links' => $hiddenCount,
            'hidden_ratio' => $total > 0 ? round($hiddenCount / $total, 4) : 0.0,
        ]];
    }

    /** @return array{0: DOMElement, 1: string}|null nearest element (self included) that hides the link */
    protected function hidingContainer(DOMElement $element): ?array
    {
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            $method = $this->extractor->hideMethod($node);

            if ($method !== null) {
                return [$node, $method];
            }

            if (Str::lower($node->nodeName) === 'body') {
                break;
            }
        }

        return null;
    }

    /** @param  array<int, string>|null  $keywords */
    protected function add(string $key, string $code, bool $hidden, string $detail, ?int $weight = null, bool $absolute = false, ?array $keywords = null): void
    {
        if (isset($this->items[$key])) {
            return;
        }

        $this->items[$key] = [
            'code' => $code,
            'weight' => $weight ?? (int) ($this->config['weights'][$code] ?? 0),
            'hidden' => $hidden,
            'detail' => Str::limit($detail, 300, ''),
            'absolute' => $absolute,
        ] + ($keywords !== null ? ['keywords' => array_values(array_slice($keywords, 0, 20))] : []);
    }

    /** Weighted keys first; weightless domain keys fill what is left of the cap. */
    protected function capped(): array
    {
        $max = (int) ($this->config['max_profile_items'] ?? 300);
        $weighted = array_filter($this->items, fn (array $item) => $item['weight'] > 0);

        if (count($weighted) > $max) {
            $this->truncated('items', "Lebih dari {$max} elemen berisiko: sebagian tidak disimpan");
            $marker = ['trunc|items' => $this->items['trunc|items']];
            unset($weighted['trunc|items']);
            uasort($weighted, fn (array $a, array $b) => $b['weight'] <=> $a['weight']);

            return $marker + array_slice($weighted, 0, $max - 1, true);
        }

        return $weighted + array_slice(array_diff_key($this->items, $weighted), 0, $max - count($weighted), true);
    }

    /** A cap was hit: the profile is partial, and that itself is a (weighted) signal. */
    protected function truncated(string $what, string $detail): void
    {
        $this->complete = false;
        $this->add('trunc|'.$what, 'profile_truncated', false, $detail, absolute: true);
    }

    protected function own(?string $domain): bool
    {
        return $domain !== null && $this->pageDomain !== null && $domain === $this->pageDomain;
    }

    protected function trusted(string $domain, string $list): bool
    {
        return $this->listed($domain, array_map(Str::lower(...), (array) ($this->config[$list] ?? [])));
    }

    /** @param  array<int, string>  $list */
    protected function listed(string $domain, array $list): bool
    {
        foreach ($list as $entry) {
            if ($domain === $entry || str_ends_with($domain, '.'.$entry)) {
                return true;
            }
        }

        return false;
    }
}
