<?php

namespace App\Services\Monitoring;

/**
 * Structural risk fingerprint of one page (ADR 0002): a set of normalized keys
 * for risky elements, never their text. externalLinks is transient - used by
 * the judol link check, never stored.
 */
class InjectionProfile
{
    /**
     * @param  array<string, array{code: string, weight: int, hidden: bool, detail: string, absolute: bool, keywords?: array<int, string>}>  $items
     * @param  array{external_links: int, external_domains: int, hidden_external_links: int, hidden_ratio: float, inline_flagged: int}  $stats
     * @param  array<int, array{url: string, domain: string, hidden: bool}>  $externalLinks
     */
    public function __construct(
        public readonly array $items,
        public readonly array $stats,
        public readonly array $externalLinks = [],
        public readonly bool $complete = true,
    ) {}

    public function hash(): string
    {
        return self::hashItems($this->items);
    }

    /** @param  array<string, mixed>  $items  keyed by risk key */
    public static function hashItems(array $items): string
    {
        $keys = array_map('strval', array_keys($items));
        sort($keys);

        return sha1(implode("\n", $keys));
    }

    /** @return array{items: array<string, array<string, mixed>>, stats: array<string, mixed>} */
    public function toBaseline(): array
    {
        $items = [];

        foreach ($this->items as $key => $item) {
            $items[$key] = array_intersect_key($item, array_flip(['code', 'weight', 'hidden', 'detail', 'keywords']));
        }

        return ['items' => $items, 'stats' => $this->stats];
    }
}
