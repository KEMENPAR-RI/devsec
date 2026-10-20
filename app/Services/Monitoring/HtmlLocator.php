<?php

namespace App\Services\Monitoring;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * Finds where a changed text chunk or element sits in ONE raw HTML document:
 * a unique XPath, a best-effort CSS path, the 1-based line in the raw body and
 * a bounded raw snippet. One instance per document; call free() before parsing
 * the next one (only one DOM is ever held at a time).
 *
 * Lines come from byte offsets in the raw body, never from libxml's 16-bit
 * getLineNo() (capped at 65535, and pointing at the END of a start tag), which
 * is used only as a fallback starting point.
 */
class HtmlLocator
{
    protected const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    protected const ATTRS = ['id', 'class', 'name', 'property', 'http-equiv', 'rel', 'src', 'href'];

    protected const TEXT_WINDOW = 262144;

    protected const CLOSE_WINDOW = 1048576;

    /** Tags, invisible characters and their entities that may sit between two characters of a text. */
    protected const SEP = '(?:<[^>]*+>|&(?:shy|zwj|zwnj|nbsp|#0*+(?:160|173|820[3-7]|8288|65279)|#[xX]0*+(?:[aA]0|[aA][dD]|200[b-fB-F]|2060|[fF][eE][fF][fF]));'
        .'|\xC2\xA0|\xC2\xAD|\xE2\x80[\x8B-\x8F]|\xE2\x81[\xA0-\xA4]|\xEF\xBB\xBF)';

    protected ?DOMDocument $dom;

    protected ?DOMXPath $xpath = null;

    /** The raw body with comment/script/style/textarea/CDATA content blanked (offsets and newlines kept). */
    protected string $masked = '';

    /** @var array<string, list<int>> byte offsets of every "<tag" in the masked body */
    protected array $tagOffsets = [];

    /** @var list<array{text: string, node: DOMElement}>|null */
    protected ?array $runs = null;

    /** @var array{0: int, 1: int} last [offset, line] computed - lines are counted incrementally */
    protected array $lineCache = [0, 1];

    protected ?string $pageDomain = null;

    protected int $snippetChars;

    /** @var array<string, string>|null */
    protected static ?array $entities = null;

    public function __construct(protected string $html, protected PageTextExtractor $extractor, protected ?string $base = null)
    {
        $this->dom = $extractor->document($html);
        $this->xpath = $this->dom ? new DOMXPath($this->dom) : null;
        $this->masked = $this->mask($html);
        $this->pageDomain = $extractor->pageDomain($base);
        $this->snippetChars = max(1, (int) config('monitoring.forensics_snippet_chars', 1000));
    }

    /**
     * Location of the closest element holding the whole chunk, or null when the
     * chunk is not in this document's visible text.
     *
     * @return array<string, mixed>|null
     */
    public function locateText(string $chunk): ?array
    {
        if (! $this->dom || ($search = $this->searchable($chunk)) === '') {
            return null;
        }

        $this->runs ??= $this->extractor->trace($this->dom);
        $element = null;

        foreach ($this->runs as $run) {
            if (str_contains($run['text'], $search)) {
                $element = $run['node'];

                break;
            }
        }

        if (! $element) {
            return null;
        }

        // Down to the closest element still holding the whole chunk.
        do {
            $descended = false;

            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement && str_contains(TextNormalizer::clean($child->textContent), $search)) {
                    $element = $child;
                    $descended = true;

                    break;
                }
            }
        } while ($descended);

        [$start, $verified] = $this->startOffset($element);
        $textOffset = $this->findText($search, $start ?? 0);

        if ($textOffset !== null) {
            return $this->describe($element, $start, $this->lineAt($textOffset), 'exact', $textOffset);
        }

        return $this->describeAtElement($element, $start, $verified);
    }

    /**
     * Location of the first element carrying an inventory key (see ElementIdentity).
     *
     * @return array<string, mixed>|null
     */
    public function locateElement(string $key): ?array
    {
        if (! $this->dom) {
            return null;
        }

        $tag = match (strstr($key, '|', true)) {
            'script' => 'script',
            'iframe' => 'iframe',
            'a' => 'a',
            'link' => 'link',
            'meta' => 'meta',
            default => null,
        };

        if ($tag === null) {
            return null;
        }

        foreach ($this->dom->getElementsByTagName($tag) as $element) {
            if (($this->extractor->elementEntry($element, $this->base, $this->pageDomain)['key'] ?? null) === $key) {
                [$start, $verified] = $this->startOffset($element);

                return $this->describeAtElement($element, $start, $verified);
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null the first element of that tag, e.g. <title> */
    public function locateTag(string $tag): ?array
    {
        $element = $this->dom?->getElementsByTagName($tag)->item(0);

        if (! $element instanceof DOMElement) {
            return null;
        }

        [$start, $verified] = $this->startOffset($element);

        return $this->describeAtElement($element, $start, $verified);
    }

    public function free(): void
    {
        $this->runs = null;
        $this->xpath = null;
        $this->dom = null;
        $this->html = '';
        $this->masked = '';
        $this->tagOffsets = [];
    }

    /** @return array<string, mixed> */
    protected function describeAtElement(DOMElement $element, ?int $start, bool $verified): array
    {
        if ($start !== null) {
            return $this->describe($element, $start, $this->lineAt($start), $verified ? 'exact' : 'element');
        }

        $line = $element->getLineNo();

        return $line > 0 && $line < 65535
            ? $this->describe($element, null, $line, 'element')
            : $this->describe($element, null, null, 'unknown');
    }

    /** @return array<string, mixed> */
    protected function describe(DOMElement $element, ?int $start, ?int $line, string $precision, ?int $focus = null): array
    {
        $tag = Str::lower($element->nodeName);
        $end = $start !== null ? $this->elementEnd($tag, $start) : null;

        if ($start !== null && $end !== null) {
            $range = substr($this->html, $start, $end - $start);
            $source = 'raw';
        } else {
            $range = (string) $this->dom->saveHTML($element);
            $source = 'dom';
            $focus = null;
        }

        $length = mb_strlen($range, 'UTF-8');
        $inside = false;

        if ($length > $this->snippetChars && $focus !== null && $focus - 200 > $start) {
            $range = substr($range, $focus - 200 - $start);
            $inside = true;
        }

        $snippet = mb_substr(mb_scrub(mb_strcut($range, 0, $this->snippetChars * 4, 'UTF-8'), 'UTF-8'), 0, $this->snippetChars, 'UTF-8');
        $attrs = [];

        foreach (self::ATTRS as $attribute) {
            if ($element->hasAttribute($attribute)) {
                $attrs[$attribute] = mb_substr(mb_scrub($element->getAttribute($attribute), 'UTF-8'), 0, 200, 'UTF-8');
            }
        }

        return [
            'xpath' => $this->xpathOf($element),
            'css' => $this->cssOf($element),
            'tag' => $tag,
            'attrs' => $attrs,
            'line' => $line,
            'line_precision' => $line === null ? 'unknown' : $precision,
            'hidden' => $this->extractor->isHidden($element),
            'snippet' => $snippet,
            'snippet_length' => $length,
            'snippet_truncated' => $inside || mb_strlen($snippet, 'UTF-8') < $length,
            'snippet_starts_inside' => $inside,
            'snippet_source' => $source,
        ];
    }

    /**
     * Byte offset of the element's start tag: the (k+1)-th "<tag" where k counts
     * same-named elements before it - trusted only when the raw and DOM counts
     * agree (libxml adds implied html/head/body/p) and the tag carries the
     * element's id/src/href. Otherwise a search from getLineNo().
     *
     * @return array{0: ?int, 1: bool} [offset, verified by ordinal]
     */
    protected function startOffset(DOMElement $element): array
    {
        $tag = Str::lower($element->nodeName);

        if (! preg_match('/^[a-z][a-z0-9._-]*$/', $tag)) {
            return [null, false];
        }

        $offsets = $this->tagOffsets[$tag] ??= $this->findTags($tag);

        if (count($offsets) === $this->dom->getElementsByTagName($tag)->length) {
            $k = (int) $this->xpath->evaluate("count(preceding::*[name()='{$tag}']) + count(ancestor::*[name()='{$tag}'])", $element);

            if (isset($offsets[$k]) && $this->startTagMatches($offsets[$k], $element)) {
                return [$offsets[$k], true];
            }
        }

        $line = $element->getLineNo();

        if ($line > 0 && $line < 65535 && $this->hasIdentity($element)) {
            $from = $this->offsetOfLine(max(1, $line - 10));

            foreach ($offsets as $offset) {
                if ($offset >= $from && $this->startTagMatches($offset, $element)) {
                    return [$offset, false];
                }
            }
        }

        return [null, false];
    }

    /** @return list<int> */
    protected function findTags(string $tag): array
    {
        if (! preg_match_all('/<'.preg_quote($tag, '/').'(?=[\s>\/])/i', $this->masked, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        return array_column($m[0], 1);
    }

    protected function hasIdentity(DOMElement $element): bool
    {
        foreach (['id', 'src', 'href'] as $attribute) {
            if ($element->getAttribute($attribute) !== '') {
                return true;
            }
        }

        return false;
    }

    protected function startTagMatches(int $offset, DOMElement $element): bool
    {
        $startTag = $this->startTag($offset);

        foreach (['id', 'src', 'href'] as $attribute) {
            $value = mb_substr($element->getAttribute($attribute), 0, 200, 'UTF-8');

            if ($value === '') {
                continue;
            }

            if (! str_contains($startTag, $value)
                && ! str_contains($startTag, str_replace('&', '&amp;', $value))
                && ! str_contains($startTag, str_replace('&', '&#38;', $value))) {
                return false;
            }
        }

        return true;
    }

    protected function startTag(int $offset): string
    {
        $head = substr($this->html, $offset, 16384);

        return preg_match('/^<[^\s>\/]++(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>?/', $head, $m) ? $m[0] : $head;
    }

    /** Byte offset just past the element's closing tag, or null when it cannot be paired. */
    protected function elementEnd(string $tag, int $start): ?int
    {
        $startTagEnd = $start + strlen($this->startTag($start));

        if (in_array($tag, self::VOID, true)) {
            return $startTagEnd;
        }

        $window = substr($this->masked, $start, self::CLOSE_WINDOW);

        if (! preg_match_all('/<(\/?)'.preg_quote($tag, '/').'(?=[\s>\/])/i', $window, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $depth = 0;

        foreach ($m as $match) {
            $depth += $match[1][0] === '' ? 1 : -1;

            if ($depth === 0) {
                $close = strpos($this->masked, '>', $start + $match[0][1]);

                return $close === false ? null : $close + 1;
            }
        }

        return null;
    }

    /** Byte offset where the chunk starts in the raw body, tolerant to tags/entities in between. */
    protected function findText(string $search, int $from): ?int
    {
        $pattern = $this->textPattern($search);
        $window = substr($this->masked, $from, self::TEXT_WINDOW);

        $found = @preg_match($pattern, $window, $m, PREG_OFFSET_CAPTURE);

        if ($found !== 1 || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }

        return $from + $m[0][1];
    }

    /** Byte-mode pattern for the first 40 characters; every quantifier possessive. */
    protected function textPattern(string $search): string
    {
        self::$entities ??= get_html_translation_table(HTML_ENTITIES, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pattern = '';
        $previousChar = false;

        foreach (mb_str_split(mb_substr($search, 0, 40, 'UTF-8'), 1, 'UTF-8') as $char) {
            if ($char === ' ') {
                $pattern .= '(?:\s|'.self::SEP.')++';
                $previousChar = false;

                continue;
            }

            $code = mb_ord($char, 'UTF-8');
            $alternatives = [preg_quote($char, '/'), '&#0*+'.$code.';', '&#[xX]0*+(?i:'.dechex((int) $code).');'];

            if (isset(self::$entities[$char])) {
                $alternatives[] = preg_quote(self::$entities[$char], '/');
            }

            $pattern .= ($previousChar ? self::SEP.'*+' : '').'(?:'.implode('|', $alternatives).')';
            $previousChar = true;
        }

        return '/'.$pattern.'/';
    }

    /** The diff trims long chunks with "…"; only the kept prefix can be searched. */
    protected function searchable(string $chunk): string
    {
        return mb_strlen($chunk) >= 500 && str_ends_with($chunk, '…') ? rtrim(mb_substr($chunk, 0, -1)) : $chunk;
    }

    /** 1-based editor line of a byte offset: \n, \r\n and a lone \r each end a line. */
    protected function lineAt(int $offset): int
    {
        [$from, $line] = $offset >= $this->lineCache[0] ? $this->lineCache : [0, 1];
        $length = $offset - $from;

        if ($length > 0) {
            $line += substr_count($this->html, "\n", $from, $length)
                + substr_count($this->html, "\r", $from, $length)
                - substr_count($this->html, "\r\n", $from, $length);
        }

        $this->lineCache = [$offset, $line];

        return $line;
    }

    protected function offsetOfLine(int $line): int
    {
        $offset = 0;

        for ($i = 1; $i < $line; $i++) {
            $next = strpos($this->html, "\n", $offset);

            if ($next === false) {
                break;
            }

            $offset = $next + 1;
        }

        return $offset;
    }

    protected function xpathOf(DOMElement $element): string
    {
        $parts = [];

        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            $parts[] = $node->nodeName.'['.$this->position($node).']';
        }

        return '/'.implode('/', array_reverse($parts));
    }

    /** Best effort: browsers (HTML5 parser) may differ from libxml, e.g. on tbody. */
    protected function cssOf(DOMElement $element): string
    {
        $parts = [];

        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            $id = $node->getAttribute('id');

            if ($id !== '' && preg_match('/^[A-Za-z][\w-]*$/', $id) && (int) $this->xpath->evaluate("count(//*[@id='{$id}'])") === 1) {
                $parts[] = '#'.$id;

                break;
            }

            $name = Str::lower($node->nodeName);
            $parts[] = in_array($name, ['html', 'head', 'body'], true) ? $name : $name.':nth-of-type('.$this->position($node).')';
        }

        return implode(' > ', array_reverse($parts));
    }

    protected function position(DOMElement $element): int
    {
        $position = 1;

        for ($sibling = $element->previousSibling; $sibling; $sibling = $sibling->previousSibling) {
            if ($sibling instanceof DOMElement && $sibling->nodeName === $element->nodeName) {
                $position++;
            }
        }

        return $position;
    }

    /** Blanks the content of comments, script, style, textarea and CDATA - linear scan, no regex. */
    protected function mask(string $html): string
    {
        $parts = [];
        $copied = 0;
        $length = strlen($html);
        $pos = 0;

        while (($pos = strpos($html, '<', $pos)) !== false) {
            [$open, $close] = [null, null];

            if (substr_compare($html, '<!--', $pos, 4) === 0) {
                $open = $pos + 4;
                $close = strpos($html, '-->', $open);
            } elseif (substr_compare($html, '<![CDATA[', $pos, 9, true) === 0) {
                $open = $pos + 9;
                $close = strpos($html, ']]>', $open);
            } else {
                foreach (['script', 'style', 'textarea'] as $tag) {
                    $next = $html[$pos + strlen($tag) + 1] ?? '';

                    if (substr_compare($html, '<'.$tag, $pos, strlen($tag) + 1, true) === 0 && ($next === '>' || $next === '/' || ctype_space($next))) {
                        $tagEnd = strpos($html, '>', $pos);
                        $open = $tagEnd === false ? $length : $tagEnd + 1;
                        $close = stripos($html, '</'.$tag, $open);

                        break;
                    }
                }
            }

            if ($open === null) {
                $pos++;

                continue;
            }

            $close = $close === false ? $length : $close;
            $parts[] = substr($html, $copied, $open - $copied);
            $parts[] = preg_replace('/[^\r\n]/', ' ', substr($html, $open, $close - $open)) ?? str_repeat(' ', $close - $open);
            $copied = $close;
            $pos = max($close, $pos + 1);
        }

        $parts[] = substr($html, $copied);

        return implode('', $parts);
    }
}
