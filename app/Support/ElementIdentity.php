<?php

namespace App\Support;

use App\Services\Monitoring\TextNormalizer;

/**
 * Deterministic identity of the non-text elements a page carries (external
 * script, iframe, off-site link, watched meta), so that cache-busters, nonces
 * and attribute order never read as a change. Identity only - never fetched.
 *
 * Keys: script|{url}, iframe|{url}, a|{url}, link|{rel}|{url}, meta|{name}.
 */
class ElementIdentity
{
    public const WATCHED_META = ['refresh', 'robots', 'description', 'keywords', 'og:title', 'og:description'];

    /** Order used to cap an inventory: meta, script, iframe, link, a. */
    protected const ORDER = ['meta' => 0, 'script' => 1, 'iframe' => 2, 'link' => 3, 'a' => 4];

    /** Meta whose value is free text: compared with dates/counters masked. */
    protected const TEXT_META = ['meta|description', 'meta|og:title', 'meta|og:description'];

    /**
     * host[:port]/path of an http(s) URL, resolved against $base, with scheme,
     * userinfo, default port, query, fragment and version/hash segments dropped.
     */
    public static function normalizeUrl(string $raw, ?string $base): ?string
    {
        $raw = trim(str_replace(["\t", "\n", "\r"], '', $raw));

        if ($raw === '' || ($absolute = self::resolve($raw, $base)) === null) {
            return null;
        }

        $parts = parse_url($absolute);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            return null;
        }

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host)) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = isset($parts['port']) && ! ($parts['port'] === 80 && $scheme === 'http') && ! ($parts['port'] === 443 && $scheme === 'https')
            ? ':'.$parts['port']
            : '';

        $segments = [];

        foreach (explode('/', (string) ($parts['path'] ?? '')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = self::maskSegment($segment);
        }

        return $host.$port.'/'.implode('/', $segments);
    }

    /** @return string|null absolute http(s) URL, null for any other scheme */
    public static function resolve(string $raw, ?string $base): ?string
    {
        $baseParts = $base !== null ? parse_url($base) : false;
        $baseScheme = is_array($baseParts) ? strtolower((string) ($baseParts['scheme'] ?? 'https')) : 'https';

        if (str_starts_with($raw, '//')) {
            return $baseScheme.':'.$raw;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $raw, $m)) {
            return in_array(strtolower($m[1]), ['http', 'https'], true) ? $raw : null;
        }

        if (! is_array($baseParts) || empty($baseParts['host'])) {
            return null;
        }

        $origin = $baseScheme.'://'.$baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');
        $basePath = (string) ($baseParts['path'] ?? '/');

        if (str_starts_with($raw, '/')) {
            return $origin.$raw;
        }

        if ($raw[0] === '?' || $raw[0] === '#') {
            return $origin.$basePath;
        }

        $directory = str_ends_with($basePath, '/') ? $basePath : (dirname($basePath) === '/' || dirname($basePath) === '\\' ? '/' : dirname($basePath).'/');

        return $origin.$directory.$raw;
    }

    protected static function maskSegment(string $segment): string
    {
        if (preg_match('/^v?\d+(\.\d+){1,3}([-.][a-z0-9]+)?$/i', $segment)) {
            return '{v}';
        }

        $segment = preg_replace('/[.-][0-9a-f]{8,}(?=\.(min\.)?(js|css|mjs)$)/i', '.{h}', $segment) ?? $segment;

        return preg_replace('/(?=[A-Za-z0-9]*\d)(?=[A-Za-z0-9]*[A-Za-z])[A-Za-z0-9]{16,}/', '{h}', $segment) ?? $segment;
    }

    /** First 12 hex chars of sha1 - identity of inline sources (data:, srcdoc). */
    public static function shortHash(string $value): string
    {
        return substr(sha1($value), 0, 12);
    }

    /** Displayed value of a watched meta; refresh keeps only delay and normalized target. */
    public static function metaValue(string $name, string $content, ?string $base = null): string
    {
        $name = strtolower($name);

        if ($name === 'refresh') {
            $delay = (int) trim((string) strtok($content, ';,'));
            $target = preg_match('/(?:^|[;,])\s*(?:url\s*=\s*)?[\'"]?([^\'";,]+[^\'";,\s])/i', preg_replace('/^\s*\d+(\.\d+)?\s*/', '', $content) ?? '', $m)
                ? self::normalizeUrl($m[1], $base)
                : null;

            return $delay.';'.($target ?? '');
        }

        if ($name === 'robots' || $name === 'keywords') {
            $tokens = array_filter(array_map(fn (string $t) => trim($t), explode(',', mb_strtolower(TextNormalizer::clean($content)))), 'strlen');
            $tokens = array_values(array_unique($tokens));
            sort($tokens);

            return implode(',', $tokens);
        }

        return TextNormalizer::clean($content);
    }

    /** What a value is compared by: free-text meta has dates/counters masked. */
    public static function compareValue(string $key, string $value): string
    {
        return in_array($key, self::TEXT_META, true) ? TextNormalizer::maskDynamic($value) : $value;
    }

    /** script | iframe | link | meta */
    public static function kindOf(string $key): string
    {
        $prefix = strstr($key, '|', true) ?: $key;

        return $prefix === 'a' ? 'link' : $prefix;
    }

    /** The key without its element prefix: normalized URL, "rel|url" or meta name. */
    public static function identity(string $key): string
    {
        return (string) substr($key, strpos($key, '|') + 1);
    }

    /**
     * Orders and caps raw entries (one per element, duplicates allowed) into the
     * inventory: duplicate keys merge (meta values joined " | ", sorted).
     *
     * @param  array<int, array{kind: string, key: string, value: string, hidden: bool}>  $entries
     * @return array<int, array{kind: string, key: string, value: string, hidden: bool}>
     */
    public static function inventory(array $entries, int $max): array
    {
        $merged = [];

        foreach ($entries as $entry) {
            $key = $entry['key'];

            if (! isset($merged[$key])) {
                $merged[$key] = $entry + ['values' => []];
            }

            if ($entry['value'] !== '') {
                $merged[$key]['values'][$entry['value']] = true;
            }
        }

        uksort($merged, function (string $a, string $b) {
            $order = (self::ORDER[strstr($a, '|', true)] ?? 9) <=> (self::ORDER[strstr($b, '|', true)] ?? 9);

            return $order !== 0 ? $order : strcmp($a, $b);
        });

        $inventory = [];

        foreach (array_slice($merged, 0, max(0, $max), true) as $key => $entry) {
            $values = array_keys($entry['values']);
            sort($values);
            $inventory[] = ['kind' => $entry['kind'], 'key' => (string) $key, 'value' => implode(' | ', $values), 'hidden' => $entry['hidden']];
        }

        return $inventory;
    }

    /**
     * @param  array<string, string>  $elements  key => value
     */
    public static function hash(array $elements): string
    {
        $lines = [];

        foreach ($elements as $key => $value) {
            $lines[] = $key."\t".self::compareValue((string) $key, (string) $value);
        }

        sort($lines);

        return sha1(implode("\n", $lines));
    }

    /**
     * @param  array<string, string>  $before  key => value
     * @param  array<string, string>  $after  key => value
     * @return array{added: array<string, string>, removed: array<string, string>, modified: array<string, array{before: string, after: string}>}
     */
    public static function diff(array $before, array $after): array
    {
        $modified = [];

        foreach (array_intersect_key($after, $before) as $key => $value) {
            if (self::compareValue((string) $key, (string) $before[$key]) !== self::compareValue((string) $key, (string) $value)) {
                $modified[$key] = ['before' => (string) $before[$key], 'after' => (string) $value];
            }
        }

        return [
            'added' => array_diff_key($after, $before),
            'removed' => array_diff_key($before, $after),
            'modified' => $modified,
        ];
    }
}
