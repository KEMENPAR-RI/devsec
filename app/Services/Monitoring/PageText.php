<?php

namespace App\Services\Monitoring;

/**
 * A page reduced to text segments, parsed once and shared by the judol matcher,
 * the content-change monitor and the defacement detector.
 *
 * Each segment: text (clean), source (title|meta|body|attr|noscript|url|script,
 * or joined = a block's visible text re-joined across style/link runs, used for
 * matching only),
 * hidden (CSS/attribute hidden, accessibility classes included), a11y (hidden
 * only by an accessibility class - labelled, but no score bonus), link_host
 * (host of the enclosing <a>, null otherwise), link_hosts (joined only), block
 * (body/joined: block number).
 */
class PageText
{
    /**
     * @param  array<int, array{text: string, source: string, hidden: bool, a11y: bool, link_host: ?string}>  $segments
     */
    public function __construct(
        public readonly array $segments,
        public readonly string $title = '',
        public readonly string $description = '',
        public readonly int $textLength = 0,
        public readonly int $blockCount = 0,
        // False when part of the page could not be read - never clear a finding on it.
        public readonly bool $complete = true,
        // Non-text inventory: list of {kind, key, value, hidden} - see ElementIdentity.
        public readonly array $elements = [],
    ) {}

    /** Wraps already-extracted plain text, e.g. a stored snapshot during prune. */
    public static function fromPlain(string $text): self
    {
        $text = TextNormalizer::clean($text);

        return new self(
            [['text' => $text, 'source' => 'body', 'hidden' => false, 'a11y' => false, 'link_host' => null]],
            textLength: mb_strlen($text),
            blockCount: $text === '' ? 0 : 1,
        );
    }

    /** Visible body text, one block per line - what a visitor reads. */
    public function visibleText(): string
    {
        $lines = [];

        foreach ($this->segments as $segment) {
            if ($segment['source'] === 'body' && ! $segment['hidden']) {
                $lines[] = $segment['text'];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Every segment except the URL slug and the anchor text of links pointing at
     * the given registrable domains (backlinks already reported as such).
     *
     * @param  array<int, string>  $excludeLinkDomains
     */
    public function plainText(array $excludeLinkDomains = []): string
    {
        $parts = [];

        foreach ($this->segments as $segment) {
            if (in_array($segment['source'], ['url', 'joined'], true) || $this->linksTo($segment, $excludeLinkDomains)) {
                continue;
            }

            $parts[] = $segment['text'];
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array{link_host: ?string}  $segment
     * @param  array<int, string>  $domains
     */
    public function linksTo(array $segment, array $domains): bool
    {
        if ($domains === []) {
            return false;
        }

        foreach (array_filter([$segment['link_host'] ?? null, ...($segment['link_hosts'] ?? [])]) as $host) {
            foreach ($domains as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return true;
                }
            }
        }

        return false;
    }
}
