<?php

namespace App\Services\Monitoring;

use App\Support\AhoCorasick;

/**
 * Judol keywords in a link's destination URL (host + path + query only).
 *
 * Two Aho-Corasick passes: token-bounded (" slot gacor " catches slot-gacor,
 * slot_gacor, togel4d) and compact ("situsslotgacor88") for long keywords only,
 * so short ones ("toto") never match inside an unrelated word.
 */
class UrlKeywordMatcher
{
    /** @var array{0: string, 1: AhoCorasick, 2: AhoCorasick}|null [rules key, token, compact] - shared per process */
    protected static ?array $automata = null;

    protected ?string $key = null;

    /** Keyword hits kept per URL - bounds the work an attacker-made URL can cause. */
    protected const MAX_HITS = 200;

    public function __construct(protected JudolMatcher $matcher) {}

    /** @return array<string, int> keyword => weight */
    public function match(string $url): array
    {
        if (! config('judol.url_match.enabled', true)) {
            return [];
        }

        [, $token, $compact] = $this->automata();
        $normalized = $this->normalize(substr($url, 0, max(1, (int) config('defacement.injection.max_url_length', 2048))));
        $found = [];

        foreach ([[$token, $normalized['tokens']], [$compact, $normalized['compact']]] as [$automaton, $text]) {
            foreach ($this->hits($automaton, $text) as $keyword => $weight) {
                $found[$keyword] = $weight;
            }
        }

        return $found;
    }

    /** @return array{tokens: string, compact: string} */
    public function normalize(string $url): array
    {
        $url = trim($url);
        $parts = parse_url(str_starts_with($url, '//') ? 'https:'.$url : $url);

        if (! is_array($parts)) {
            $parts = ['path' => $url];
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host !== '' && str_contains($host, 'xn--') && function_exists('idn_to_utf8')) {
            $host = idn_to_utf8($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
        }

        $rest = (string) ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');

        // Double encoding (%2533) is unwrapped at most twice.
        for ($i = 0; $i < 2 && str_contains($rest, '%'); $i++) {
            $rest = rawurldecode($rest);
        }

        $text = mb_strtolower($host.' '.$rest, 'UTF-8');

        return ['tokens' => self::tokenize($text), 'compact' => self::compact($text)];
    }

    /** " slot gacor 88 xyz ": split on non-alnum and on letter/digit transitions. */
    public static function tokenize(string $text): string
    {
        $text = preg_replace('/([a-z])(?=\d)|(\d)(?=[a-z])/', '$1$2 ', strtolower($text)) ?? $text;
        $tokens = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return ' '.implode(' ', $tokens).' ';
    }

    public static function compact(string $text): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($text)) ?? '';
    }

    /**
     * Keyword hits not covered by an exclusion hit ("bandar" in "bandar lampung")
     * nor, for a single word, by a longer keyword hit.
     *
     * @return array<string, int>
     */
    protected function hits(AhoCorasick $automaton, string $text): array
    {
        $keywords = [];
        $exclusions = [];

        foreach ($automaton->search($text) as [$end, $entries, $length]) {
            $start = $end - $length;

            foreach ($entries as $entry) {
                if ($entry[0] === 'x') {
                    $exclusions[] = [$start, $end];
                } elseif (count($keywords) < self::MAX_HITS) {
                    $keywords[$start.':'.$end.':'.$entry[1]] ??= [$start, $end, $entry[1], $entry[2]];
                }
            }
        }

        // Sorted sweeps keep this O(k log k): crawled URLs are attacker-controlled.
        usort($exclusions, fn (array $a, array $b) => $a[0] <=> $b[0]);
        usort($keywords, fn (array $a, array $b) => [$a[0], $b[1]] <=> [$b[0], $a[1]]);

        $found = [];
        $x = 0;
        $xEnd = -1;
        $maxEnd = -1;
        $group = null;
        $groupContained = false;

        foreach ($keywords as [$start, $end, $keyword, $weight]) {
            // Covered by an exclusion: max end of exclusions starting at or before this hit.
            while ($x < count($exclusions) && $exclusions[$x][0] <= $start) {
                $xEnd = max($xEnd, $exclusions[$x][1]);
                $x++;
            }

            if ($xEnd >= $end) {
                continue;
            }

            // Longest match, as JudolMatcher: "slot gacor" does not also count "gacor".
            if ([$start, $end] !== $group) {
                $maxEnd = $group === null ? $maxEnd : max($maxEnd, $group[1]);
                $group = [$start, $end];
                $groupContained = $maxEnd >= $end;
            }

            if ($groupContained && ! str_contains(trim($keyword), ' ')) {
                continue;
            }

            $found[$keyword] = $weight;
        }

        return $found;
    }

    /** @return array{0: string, 1: AhoCorasick, 2: AhoCorasick} */
    protected function automata(): array
    {
        // Rules are fixed for this (scoped) instance: hash them once, not per URL.
        $key = $this->key ??= md5(json_encode([$this->matcher->rules(), max(1, (int) config('judol.url_match.min_compact_length', 6))]));

        if (self::$automata !== null && self::$automata[0] === $key) {
            return self::$automata;
        }

        $rules = $this->matcher->rules();
        $minCompact = max(1, (int) config('judol.url_match.min_compact_length', 6));

        $token = [];
        $compact = [];

        foreach ($rules['keywords'] as $keyword => $weight) {
            $entry = ['k', (string) $keyword, (int) $weight];
            $tokens = self::tokenize((string) $keyword);

            if (trim($tokens) !== '') {
                $token[$tokens][] = $entry;
            }

            $packed = self::compact((string) $keyword);

            if (strlen($packed) >= $minCompact) {
                $compact[$packed][] = $entry;
            }
        }

        foreach ($rules['exclusions'] as $phrase) {
            $tokens = self::tokenize((string) $phrase);

            if (trim($tokens) !== '') {
                $token[$tokens][] = ['x'];
            }

            $packed = self::compact((string) $phrase);

            if (strlen($packed) >= $minCompact) {
                $compact[$packed][] = ['x'];
            }
        }

        return self::$automata = [$key, new AhoCorasick($token), new AhoCorasick($compact)];
    }
}
