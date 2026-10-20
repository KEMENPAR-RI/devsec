<?php

namespace App\Services\Monitoring;

use App\Support\ElementIdentity;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Str;
use WeakReference;

/**
 * Parses a page once into PageText segments.
 *
 * Inline elements are joined without a separator (so <span>sl</span><b>ot</b>
 * reads "slot"), block elements start a new segment (so a phrase never spans
 * two blocks). Hidden state is inherited down the tree.
 */
class PageTextExtractor
{
    protected const INLINE = [
        'a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'font', 'i', 'ins',
        'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var',
    ];

    protected const SKIPPED = ['script', 'style', 'template', 'svg', 'head'];

    protected const A11Y_CLASSES = ['sr-only', 'visually-hidden', 'screen-reader-text'];

    /** @var array<string, true> classes hidden by a simple rule in the page's own <style> */
    protected array $hiddenClasses = [];

    /** @var WeakReference<DOMDocument>|null the document was read from */
    protected ?WeakReference $hiddenClassesDocument = null;

    /** @var array<int, array{text: string, source: string, hidden: bool, a11y: bool, link_host: ?string}> */
    protected array $segments = [];

    protected string $buffer = '';

    /** @var array{0: string, 1: ?string, 2: ?string}|null [source, hidden, link_host] of the buffer */
    protected ?array $bufferState = null;

    /** Visible text of the current block across style/link runs: s<b hidden>x</b>lot -> "slot". */
    protected string $joined = '';

    /** @var array<string, true> link hosts inside the current block's joined text */
    protected array $joinedHosts = [];

    /** Visible body runs flushed in the current block. */
    protected int $blockRuns = 0;

    /** Current block number, shared by its runs and its joined segment. */
    protected int $block = 0;

    /** False once part of the page could not be read (e.g. a PCRE failure). */
    protected bool $complete = true;

    /** @var list<array{text: string, node: DOMElement}>|null runs collected by trace(); null when not tracing */
    protected ?array $trace = null;

    /** @var array<int, DOMNode> elements holding the current buffer's text nodes (tracing only) */
    protected array $bufferNodes = [];

    protected ?DOMNode $traceParent = null;

    protected ?DomainExpiry $domains = null;

    /** Set only for the duration of extractAt(). */
    protected ?string $baseUrl = null;

    /** Inline JS that writes markup - judol injected this way never reaches the DOM text. */
    protected const SCRIPT_WRITERS = '/document\.write|innerHTML|outerHTML|insertAdjacentHTML/i';

    /** $complete = false when the body is already known to be cut (size limit). */
    public function extract(string $html, ?string $url = null, bool $complete = true): PageText
    {
        $this->complete = $complete;
        $dom = $this->parse($html);
        $this->segments = [];
        $this->buffer = '';
        $this->bufferState = null;
        $this->resetBlock();
        $title = '';
        $description = '';
        $elements = [];

        if ($dom) {
            $this->loadHiddenClasses($dom);

            if ($node = $dom->getElementsByTagName('title')->item(0)) {
                $title = TextNormalizer::clean($node->textContent);
                $this->segment($title, 'title', null, null);
            }

            foreach ($dom->getElementsByTagName('meta') as $meta) {
                $name = Str::lower(trim($meta->getAttribute('name')));
                $property = Str::lower(trim($meta->getAttribute('property')));

                if (! in_array($name, ['description', 'keywords'], true) && ! str_starts_with($property, 'og:') && ! str_starts_with($name, 'twitter:')) {
                    continue;
                }

                $content = TextNormalizer::clean($meta->getAttribute('content'));

                if ($name === 'description' || ($description === '' && $property === 'og:description')) {
                    $description = $content;
                }

                $this->segment($content, 'meta', null, null);
            }

            $body = $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;

            if ($body) {
                $this->walk($body, 'body', $body instanceof DOMElement ? $this->selfHidden($body) : null, null);
                $this->endBlock();
            }

            foreach ($dom->getElementsByTagName('script') as $script) {
                $this->scriptSegment($script);
            }

            $elements = $this->elements($dom, $this->baseUrl ?? $url);
        }

        if ($url !== null) {
            $parts = parse_url($url);
            $slug = urldecode(($parts['path'] ?? '').(isset($parts['query']) ? ' '.$parts['query'] : ''));
            $this->segment(str_replace(['-', '_', '/'], ' ', $slug), 'url', null, null);
        }

        $segments = $this->segments;
        $this->segments = [];

        $visible = array_filter($segments, fn (array $s) => $s['source'] === 'body' && ! $s['hidden']);
        $length = array_sum(array_map(fn (array $s) => mb_strlen($s['text']), $visible)) + max(0, count($visible) - 1);

        return new PageText(
            array_values($segments),
            Str::limit($title, 255, ''),
            Str::limit($description, 500, ''),
            $length,
            count($visible),
            $this->complete,
            $elements,
        );
    }

    /** extract(), with relative element URLs resolved against $baseUrl (the final URL after redirects). */
    public function extractAt(string $html, ?string $url, bool $complete, ?string $baseUrl): PageText
    {
        $this->baseUrl = $baseUrl;

        try {
            return $this->extract($html, $url, $complete);
        } finally {
            $this->baseUrl = null;
        }
    }

    /** Parses a page for the forensic locator - same parser, same (line-neutral) prefix. */
    public function document(string $html): ?DOMDocument
    {
        return $this->parse($html);
    }

    /**
     * Visible body runs exactly as extract() reads them, each with the closest
     * element holding all of its text nodes. Used by the forensic locator only.
     *
     * @return list<array{text: string, node: DOMElement}>
     */
    public function trace(DOMDocument $dom): array
    {
        $this->segments = [];
        $this->buffer = '';
        $this->bufferState = null;
        $this->bufferNodes = [];
        $this->resetBlock();
        $this->trace = [];

        try {
            $this->loadHiddenClasses($dom);
            $body = $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;

            if ($body) {
                $this->walk($body, 'body', $body instanceof DOMElement ? $this->selfHidden($body) : null, null);
                $this->endBlock();
            }

            return $this->trace;
        } finally {
            $this->trace = null;
            $this->segments = [];
            $this->bufferNodes = [];
            $this->traceParent = null;
        }
    }

    /**
     * Inventory of non-text elements (see ElementIdentity), capped and ordered.
     *
     * @return array<int, array{kind: string, key: string, value: string, hidden: bool}>
     */
    protected function elements(DOMDocument $dom, ?string $base): array
    {
        $domain = $this->pageDomain($base);
        $entries = [];

        foreach (['meta', 'script', 'iframe', 'link', 'a'] as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $element) {
                if ($entry = $this->elementEntry($element, $base, $domain)) {
                    $entries[] = $entry + ['hidden' => $this->isHidden($element)];
                }
            }
        }

        return ElementIdentity::inventory($entries, (int) config('monitoring.forensics_max_elements', 500));
    }

    /**
     * Identity of one watched element, or null when it is not watched (inline
     * script, same-site link, unwatched meta...).
     *
     * @return array{kind: string, key: string, value: string}|null
     */
    public function elementEntry(DOMElement $element, ?string $base, ?string $pageDomain): ?array
    {
        $tag = Str::lower($element->nodeName);

        if ($tag === 'script' || $tag === 'iframe') {
            if ($tag === 'iframe' && $element->hasAttribute('srcdoc')) {
                return ['kind' => 'iframe', 'key' => 'iframe|srcdoc:'.ElementIdentity::shortHash($element->getAttribute('srcdoc')), 'value' => ''];
            }

            $src = trim($element->getAttribute('src'));

            if ($src === '' || Str::lower($src) === 'about:blank') {
                return $tag === 'iframe' ? ['kind' => 'iframe', 'key' => 'iframe|about:blank', 'value' => ''] : null;
            }

            $identity = Str::startsWith(Str::lower($src), 'data:')
                ? 'data:'.ElementIdentity::shortHash($src)
                : ElementIdentity::normalizeUrl($src, $base);

            return $identity === null ? null : ['kind' => $tag, 'key' => $tag.'|'.$identity, 'value' => ''];
        }

        if ($tag === 'a' || $tag === 'link') {
            $href = trim($element->getAttribute('href'));

            if ($href === '' || $href[0] === '#' || Str::startsWith(Str::lower($href), ['javascript:', 'mailto:', 'tel:'])) {
                return null;
            }

            $url = ElementIdentity::normalizeUrl($href, $base);

            if ($url === null || $this->domainOf(Str::before($url, '/')) === $pageDomain) {
                return null;
            }

            if ($tag === 'a') {
                return ['kind' => 'link', 'key' => 'a|'.$url, 'value' => ''];
            }

            $rel = preg_split('/\s+/', Str::lower(trim($element->getAttribute('rel'))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            sort($rel);

            return ['kind' => 'link', 'key' => 'link|'.implode(' ', array_unique($rel)).'|'.$url, 'value' => ''];
        }

        if ($tag === 'meta') {
            $name = Str::lower(trim($element->getAttribute('http-equiv'))) === 'refresh'
                ? 'refresh'
                : (Str::lower(trim($element->getAttribute('name'))) ?: Str::lower(trim($element->getAttribute('property'))));

            if ($name === 'refresh' || in_array($name, ElementIdentity::WATCHED_META, true)) {
                return ['kind' => 'meta', 'key' => 'meta|'.$name, 'value' => ElementIdentity::metaValue($name, $element->getAttribute('content'), $base)];
            }
        }

        return null;
    }

    /** Registrable domain of a page URL (host when it has none, e.g. an IP). */
    public function pageDomain(?string $url): ?string
    {
        $host = $url !== null ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? $this->domainOf($host) : null;
    }

    protected function domainOf(string $host): string
    {
        $host = Str::lower(Str::before($host, ':'));

        return ($this->domains ??= app(DomainExpiry::class))->registrableDomain('https://'.$host) ?? $host;
    }

    /** Whether an element (or any ancestor up to <body>) is hidden by CSS/attribute. */
    public function isHidden(DOMElement $element): bool
    {
        if ($element->ownerDocument) {
            $this->loadHiddenClasses($element->ownerDocument);
        }

        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($this->selfHidden($node) !== null) {
                return true;
            }

            if (Str::lower($node->nodeName) === 'body') {
                break;
            }
        }

        return false;
    }

    protected function walk(DOMNode $node, string $source, ?string $hidden, ?string $linkHost): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $this->traceParent = $node;
                $this->append($child->data, $source, $hidden, $linkHost);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $name = Str::lower($child->nodeName);

            if (in_array($name, self::SKIPPED, true)) {
                continue;
            }

            $childHidden = $this->combine($hidden, $this->selfHidden($child));
            $childLink = $name === 'a' ? ($this->linkHost($child->getAttribute('href')) ?? $linkHost) : $linkHost;

            foreach (['alt', 'title'] as $attribute) {
                if ($child->hasAttribute($attribute)) {
                    $this->segment($child->getAttribute($attribute), 'attr', $childHidden, $childLink);
                }
            }

            if ($name === 'br') {
                $this->append(' ', $source, $hidden, $linkHost);

                continue;
            }

            if ($name === 'noscript') {
                $this->endBlock();
                $this->segment(strip_tags(str_replace('<', ' <', $child->textContent)), 'noscript', $childHidden, $childLink);

                continue;
            }

            if (in_array($name, self::INLINE, true)) {
                $this->walk($child, $source, $childHidden, $childLink);

                continue;
            }

            $this->endBlock();
            $this->walk($child, $source, $childHidden, $childLink);
            $this->endBlock();
        }
    }

    /** Ends a block: flushes the run, then adds the joined visible text when runs were split. */
    protected function endBlock(): void
    {
        $this->flush();

        if ($this->blockRuns >= 2) {
            $this->segment($this->joined, 'joined', null, null, array_keys($this->joinedHosts));
        }

        $this->resetBlock();
    }

    protected function resetBlock(): void
    {
        $this->joined = '';
        $this->joinedHosts = [];
        $this->blockRuns = 0;
        $this->block++;
    }

    /** String literals of an inline markup-writing script, tags stripped, as a hidden segment. */
    protected function scriptSegment(DOMElement $script): void
    {
        $code = $script->textContent;

        if ($script->hasAttribute('src') || $code === '') {
            return;
        }

        $writes = preg_match(self::SCRIPT_WRITERS, $code);

        if ($writes === false || preg_last_error() !== PREG_NO_ERROR) {
            $this->complete = false;

            return;
        }

        if (! $writes) {
            return;
        }

        $text = implode(' ', array_map(fn (string $literal) => stripcslashes($literal), $this->stringLiterals($code)));

        // Labelled hidden (not in the visible DOM text) but no concealment bonus:
        // JS-written text is visible once rendered.
        $this->segment(strip_tags(str_replace('<', ' <', $text)), 'script', 'a11y', null);
    }

    /**
     * Quoted literals of a script, scanned linearly - a backtracking regex hits the
     * PCRE JIT stack limit on a literal of ~10 KB and silently returns nothing.
     *
     * @return array<int, string>
     */
    protected function stringLiterals(string $code): array
    {
        $literals = [];
        $length = strlen($code);
        // Quote types with no closing quote left; JS would not parse them anyway.
        $open = ['"' => true, "'" => true, '`' => true];
        $i = 0;

        while ($open !== [] && ($i += strcspn($code, implode('', array_keys($open)).'/', $i)) < $length) {
            $quote = $code[$i];

            // Comments are skipped so a quote inside one cannot flip the pairing.
            if ($quote === '/') {
                $next = $code[$i + 1] ?? '';
                $end = match ($next) {
                    '/' => strpos($code, "\n", $i + 2),
                    '*' => strpos($code, '*/', $i + 2),
                    default => $i,
                };
                $i = $end === false ? $length : $end + ($next === '*' ? 2 : 1);

                continue;
            }

            $j = $i + 1;

            while ($j < $length && ($j += strcspn($code, $quote.'\\', $j)) < $length && $code[$j] === '\\') {
                $j += 2;
            }

            if ($j >= $length) {
                unset($open[$quote]);
                $i++;

                continue;
            }

            $literals[] = substr($code, $i + 1, $j - $i - 1);
            $i = $j + 1;
        }

        return $literals;
    }

    protected function append(string $text, string $source, ?string $hidden, ?string $linkHost): void
    {
        $state = [$source, $hidden, $linkHost];

        if ($source === 'body' && $hidden === null) {
            $this->joined .= $text;

            if ($linkHost !== null) {
                $this->joinedHosts[$linkHost] = true;
            }
        }

        if ($this->bufferState !== $state) {
            // Whitespace between differently-styled inline nodes stays with the current run.
            if ($this->bufferState !== null && trim($text) === '') {
                $this->buffer .= ' ';

                return;
            }

            $this->flush();
            $this->bufferState = $state;
        }

        $this->buffer .= $text;

        if ($this->trace !== null && $this->traceParent !== null) {
            $this->bufferNodes[] = $this->traceParent;
        }
    }

    protected function flush(): void
    {
        if ($this->bufferState !== null) {
            [$source, $hidden, $linkHost] = $this->bufferState;

            if ($this->segment($this->buffer, $source, $hidden, $linkHost) && $source === 'body' && $hidden === null) {
                $this->blockRuns++;

                if ($this->trace !== null && ($node = $this->commonAncestor($this->bufferNodes))) {
                    $this->trace[] = ['text' => TextNormalizer::clean($this->buffer), 'node' => $node];
                }
            }
        }

        $this->bufferNodes = [];
        $this->buffer = '';
        $this->bufferState = null;
    }

    /** @param  array<int, DOMNode>  $nodes */
    protected function commonAncestor(array $nodes): ?DOMElement
    {
        $first = array_shift($nodes);
        $chain = [];

        for ($n = $first; $n instanceof DOMElement; $n = $n->parentNode) {
            $chain[] = $n;
        }

        foreach ($nodes as $node) {
            if ($node === $first) {
                continue;
            }

            for ($n = $node; $n instanceof DOMElement; $n = $n->parentNode) {
                foreach ($chain as $i => $candidate) {
                    if ($candidate->isSameNode($n)) {
                        $chain = array_slice($chain, $i);

                        continue 3;
                    }
                }
            }
        }

        return $chain[0] ?? null;
    }

    /** @param  array<int, string>  $linkHosts  joined segments: every link host inside */
    protected function segment(string $text, string $source, ?string $hidden, ?string $linkHost, array $linkHosts = []): bool
    {
        $text = TextNormalizer::clean($text);

        if ($text === '') {
            return false;
        }

        $this->segments[] = [
            'text' => $text,
            'source' => $source,
            'hidden' => $hidden !== null,
            'a11y' => $hidden === 'a11y',
            'link_host' => $linkHost,
        ] + ($linkHosts === [] ? [] : ['link_hosts' => $linkHosts])
            + (in_array($source, ['body', 'joined'], true) ? ['block' => $this->block] : []);

        return true;
    }

    /** 'css' when hidden by attribute/style/class rule, 'a11y' for screen-reader-only classes. */
    protected function selfHidden(DOMElement $element): ?string
    {
        if ($element->hasAttribute('hidden')) {
            return 'css';
        }

        $style = Str::lower(preg_replace('/\s+/', '', $element->getAttribute('style')) ?? '');

        if ($style !== '' && $this->styleHides($style)) {
            return 'css';
        }

        $class = trim($element->getAttribute('class'));

        if ($class === '') {
            return null;
        }

        $a11y = false;

        foreach (preg_split('/\s+/', Str::lower($class)) as $name) {
            if (isset($this->hiddenClasses[$name])) {
                return 'css';
            }

            $a11y = $a11y || in_array($name, self::A11Y_CLASSES, true);
        }

        return $a11y ? 'a11y' : null;
    }

    protected function styleHides(string $style): bool
    {
        if (preg_match('/display:none|visibility:hidden|font-size:0(?:px|em|rem|pt|%)?(?:;|!|$)|opacity:0(?:\.0+)?(?:;|!|$)|(?:^|;)(?:left|top|text-indent|margin-left):-\d{3,}/', $style)) {
            return true;
        }

        if (preg_match('/(?:^|;)(?:height|width):0(?:px)?(?:;|!|$)/', $style) && str_contains($style, 'overflow:hidden')) {
            return true;
        }

        return preg_match('/(?:^|;)color:([^;!]+)/', $style, $color)
            && preg_match('/(?:^|;)background(?:-color)?:([^;!]+)/', $style, $background)
            && $color[1] === $background[1];
    }

    protected function combine(?string $parent, ?string $own): ?string
    {
        if ($parent === 'css' || $own === 'css') {
            return 'css';
        }

        return $parent ?? $own;
    }

    protected function loadHiddenClasses(DOMDocument $dom): void
    {
        if ($this->hiddenClassesDocument?->get() === $dom) {
            return;
        }

        $this->hiddenClassesDocument = WeakReference::create($dom);
        $this->hiddenClasses = [];

        foreach ($dom->getElementsByTagName('style') as $style) {
            if (preg_match_all('/\.([\w-]+)\s*\{[^}]*(?:display\s*:\s*none|visibility\s*:\s*hidden)/i', $style->textContent, $m) === false) {
                $this->complete = false;

                continue;
            }

            foreach ($m[1] as $name) {
                $name = Str::lower($name);

                if (! in_array($name, self::A11Y_CLASSES, true)) {
                    $this->hiddenClasses[$name] = true;
                }
            }
        }
    }

    protected function linkHost(string $href): ?string
    {
        $href = trim($href);

        if (! Str::startsWith(Str::lower($href), ['http://', 'https://', '//'])) {
            return null;
        }

        $host = parse_url(str_starts_with($href, '//') ? 'https:'.$href : $href, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? Str::lower($host) : null;
    }

    protected function parse(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);

        if (self::parseStopped()) {
            $this->complete = false;
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    /**
     * Whether the last loadHTML() gave up part way (fatal error, e.g. nesting past
     * libxml's 256-level limit) - everything after that point is missing from the DOM.
     * Needs libxml_use_internal_errors(true); call before libxml_clear_errors().
     */
    public static function parseStopped(): bool
    {
        foreach (libxml_get_errors() as $error) {
            if ($error->level === LIBXML_ERR_FATAL || str_contains($error->message, 'Excessive depth')) {
                return true;
            }
        }

        return false;
    }
}
