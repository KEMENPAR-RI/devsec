<?php

namespace App\Support;

/**
 * Lays injected markup out one tag per line, so its structure can be read.
 *
 * PageRegion::snippet() stores evidence squished onto a single line, which is the
 * right thing for a database column but unreadable on screen: a spammed footer
 * arrives as two thousand unbroken characters, and the nesting that shows what was
 * injected - forty <p><a><span> triples pasted into one widget - is invisible in it.
 *
 * Operates on text that has ALREADY been escaped, and never parses it as HTML. The
 * string came off a compromised site, so it is treated as characters to lay out,
 * not as a document to load: the tags matched below are the escaped forms
 * (&lt;p&gt;), which cannot be anything but literal text by the time they get here.
 * Formatting before the highlight pass also keeps our own <mark> tags - real HTML,
 * added afterwards - out of reach of the indenter.
 */
class EvidenceMarkup
{
    /** Spaces per nesting level. Two, because deep markup indents off-screen fast. */
    protected const INDENT = 2;

    /**
     * Nesting levels honoured before indentation stops growing. Injected spam
     * routinely sits ten wrappers down, and past this the indent costs more
     * readable width than the structure it conveys.
     */
    protected const MAX_DEPTH = 12;

    /**
     * Tags that never wrap anything, so they must not open a nesting level. Written
     * without the escaped brackets; matched case-insensitively.
     */
    protected const VOID_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * Escaped markup laid out one tag per line.
     *
     * A tag whose entire content is a short piece of text is kept on one line -
     * <a href="...">togel</a> split across three lines reads worse than it does
     * whole, and that anchor is usually the whole finding.
     */
    public static function format(string $escaped): string
    {
        if (trim($escaped) === '') {
            return '';
        }

        // Split into tags and the text between them, keeping both. Only the escaped
        // forms are recognised, so a literal "<" that survived escaping as &lt; on
        // its own - not part of a tag - is treated as text, which is what it is.
        //
        // The body is "anything that is not the start of &gt;", spelled as a
        // negative lookahead rather than a character class: an attribute value
        // legitimately contains & (a query string's &amp;), so excluding & outright
        // made a tag run past its own closing bracket and swallow the next one.
        $tokens = preg_split(
            '/(&lt;\/?[a-zA-Z](?:(?!&gt;).)*&gt;)/s',
            $escaped,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        if ($tokens === false) {
            return $escaped;
        }

        $lines = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! str_starts_with($token, '&lt;')) {
                $text = trim($token);

                if ($text !== '') {
                    $lines[] = [$depth, $text];
                }

                continue;
            }

            if (str_starts_with($token, '&lt;/')) {
                $depth = max(0, $depth - 1);
                $lines[] = [$depth, $token];

                continue;
            }

            // Joined here rather than in a later pass, because depth moves as these
            // tokens are read: an inline run recognised afterwards would already
            // have pushed the lines around it in by a level it never really opened.
            if (($inline = self::inlineRun($tokens, $i)) !== null) {
                $lines[] = [$depth, $inline['text']];
                $i = $inline['next'] - 1;

                continue;
            }

            $lines[] = [$depth, $token];

            if (! self::isSelfContained($token)) {
                $depth = min(self::MAX_DEPTH, $depth + 1);
            }
        }

        return implode("\n", array_map(
            fn (array $line) => str_repeat(' ', $line[0] * self::INDENT).$line[1],
            $lines
        ));
    }

    /**
     * An element whose whole content fits on one line, starting at $index - either
     * a short text body (&lt;a ...&gt;togel&lt;/a&gt;) or nothing at all.
     *
     * Kept whole because that anchor is usually the entire finding: split over three
     * lines it triples in height and reads worse, not better. Nested elements are
     * deliberately NOT folded - a &lt;span&gt; inside the anchor is structure worth
     * seeing, and folding it is what produced misaligned closing tags before.
     *
     * @param  array<int, string>  $tokens
     * @return array{text: string, next: int}|null
     */
    protected static function inlineRun(array $tokens, int $index): ?array
    {
        $open = $tokens[$index];

        if (self::isSelfContained($open)) {
            return null;
        }

        $next = $tokens[$index + 1] ?? null;

        if ($next === null) {
            return null;
        }

        // <tag></tag> - nothing inside.
        if (str_starts_with($next, '&lt;/')) {
            return ['text' => $open.$next, 'next' => $index + 2];
        }

        // <tag>text</tag> - one text node, and the very next token closes it.
        $after = $tokens[$index + 2] ?? null;

        if (! str_starts_with($next, '&lt;') && $after !== null && str_starts_with($after, '&lt;/')) {
            return ['text' => $open.trim($next).$after, 'next' => $index + 3];
        }

        return null;
    }

    /**
     * Whether an opening tag closes itself - a void element (&lt;br&gt;) or an
     * explicit XHTML-style self-close (&lt;img /&gt;). Either way it wraps nothing,
     * so the lines after it must not be pushed in.
     */
    protected static function isSelfContained(string $tag): bool
    {
        if (str_ends_with($tag, '/&gt;')) {
            return true;
        }

        if (! preg_match('/^&lt;([a-zA-Z][a-zA-Z0-9-]*)/', $tag, $m)) {
            return false;
        }

        return in_array(strtolower($m[1]), self::VOID_TAGS, true);
    }
}
