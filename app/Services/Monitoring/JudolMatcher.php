<?php

namespace App\Services\Monitoring;

use App\Models\JudolExclusion;
use App\Models\JudolKeyword;
use App\Support\AhoCorasick;

/**
 * Weighted, whole-word judol keyword matching over folded text.
 *
 * One Aho-Corasick pass finds every keyword and exclusion hit; a keyword hit
 * sharing a token with an exclusion hit ("bandar" in "bandar lampung") is dropped.
 * Score = sum of unique keyword weights + hidden/SEO bonuses.
 */
class JudolMatcher
{
    /** Separates segments in the haystack - never part of a pattern. */
    protected const SEGMENT_SEPARATOR = "\x01";

    protected const SEO_SOURCES = ['title', 'meta', 'url'];

    protected ?AhoCorasick $automaton = null;

    /** @var array{keywords: array<string, int>, exclusions: array<int, string>}|null */
    protected ?array $rules = null;

    /** @var array<string, true>|null */
    protected ?array $foldedExclusions = null;

    /**
     * Active keywords (keyword => weight) and exclusion phrases.
     *
     * @return array{keywords: array<string, int>, exclusions: array<int, string>}
     */
    public function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $keywords = JudolKeyword::activeRules();

        // Same safety net as before: an empty table must not silently match nothing.
        if ($keywords === []) {
            $default = (int) config('judol.weights.strong', 3);
            $keywords = array_fill_keys((array) config('judol.keywords', []), $default);
        }

        return $this->rules = ['keywords' => $keywords, 'exclusions' => JudolExclusion::activeList()];
    }

    /**
     * @param  array<int, string>  $excludeLinkDomains  anchor text of links to these domains is skipped
     * @return array{score: int, keywords: array<int, string>, details: array<int, array{keyword: string, weight: int, sources: array<int, string>, hidden: bool, a11y: bool}>, first_segment: ?int}
     */
    public function match(PageText $page, array $excludeLinkDomains = []): array
    {
        $haystack = '';
        $starts = [];
        $indexes = [];

        foreach ($page->segments as $index => $segment) {
            if ($page->linksTo($segment, $excludeLinkDomains)) {
                continue;
            }

            $starts[] = strlen($haystack);
            $indexes[] = $index;
            $haystack .= TextNormalizer::fold($segment['text']).self::SEGMENT_SEPARATOR;
        }

        $details = [];
        $firstHit = null;
        $concealed = false;
        $seo = false;

        $hits = $this->keywordHits($haystack, $swallowed);

        // Exclusions apply across runs: a keyword an exclusion swallowed in a block's
        // joined text ("Kota <a>Bandar</a> Lampung") does not count in that block's visible runs.
        $covered = [];

        foreach ($swallowed as [$start, $keyword]) {
            $segment = $page->segments[$indexes[$this->segmentAt($starts, $start)]];

            if ($segment['source'] === 'joined') {
                $covered[$segment['block'] ?? -1][$keyword] = true;
            }
        }

        foreach ($hits as [$start, $keyword, $weight]) {
            $segment = $page->segments[$indexes[$this->segmentAt($starts, $start)]];

            if ($segment['source'] === 'body' && ! $segment['hidden'] && isset($covered[$segment['block'] ?? -1][$keyword])) {
                continue;
            }

            $details[$keyword] ??= ['keyword' => $keyword, 'weight' => $weight, 'sources' => [], 'hidden' => false, 'concealed' => false, 'offset' => $start];
            $source = $segment['source'] === 'joined' ? 'body' : $segment['source'];
            $details[$keyword]['sources'][$source] = $source;

            if ($segment['hidden']) {
                $details[$keyword]['hidden'] = true;
                $details[$keyword]['concealed'] = $details[$keyword]['concealed'] || ! $segment['a11y'];
                $concealed = $concealed || ! $segment['a11y'];
            }

            $seo = $seo || in_array($segment['source'], self::SEO_SOURCES, true);

            if ($firstHit === null || $start < $firstHit[0]) {
                $firstHit = [$start, $indexes[$this->segmentAt($starts, $start)]];
            }
        }

        uasort($details, fn (array $a, array $b) => $a['offset'] <=> $b['offset']);

        $score = 0;
        $out = [];

        foreach ($details as $detail) {
            $score += $detail['weight'];
            $out[] = [
                'keyword' => $detail['keyword'],
                'weight' => $detail['weight'],
                'sources' => array_values($detail['sources']),
                'hidden' => $detail['hidden'],
                // a11y = only ever hidden by a screen-reader class (labelled, no bonus).
                'a11y' => $detail['hidden'] && ! $detail['concealed'],
            ];
        }

        if ($out !== []) {
            $score += ($concealed ? (int) config('judol.hidden_bonus', 1) : 0) + ($seo ? (int) config('judol.seo_bonus', 1) : 0);
        }

        return [
            'score' => $score,
            'keywords' => array_column($out, 'keyword'),
            'details' => $out,
            'first_segment' => $firstHit[1] ?? null,
        ];
    }

    /**
     * Keywords present in a piece of text, exclusions applied, no threshold.
     *
     * @return array<int, string>
     */
    public function matchText(string $text): array
    {
        $found = [];

        foreach ($this->keywordHits(TextNormalizer::fold($text)) as [, $keyword]) {
            $found[$keyword] = true;
        }

        return array_keys($found);
    }

    /** Sum of current weights for these keywords (unknown/inactive ones count 0). */
    public function weightOf(array $keywords): int
    {
        $rules = $this->rules()['keywords'];
        $lower = array_change_key_case($rules, CASE_LOWER);
        $sum = 0;

        foreach (array_unique(array_map('mb_strtolower', $keywords)) as $keyword) {
            $sum += $lower[$keyword] ?? 0;
        }

        return $sum;
    }

    /** Whether a keyword is itself an active exclusion phrase. */
    public function isExcluded(string $keyword): bool
    {
        $this->foldedExclusions ??= array_fill_keys(array_map(TextNormalizer::foldKeyword(...), $this->rules()['exclusions']), true);

        return isset($this->foldedExclusions[TextNormalizer::foldKeyword($keyword)]);
    }

    /**
     * Keyword hits not swallowed by an exclusion, as [start, keyword, weight];
     * swallowed ones go to $swallowed as [start, keyword].
     *
     * @return array<int, array{0: int, 1: string, 2: int}>
     */
    protected function keywordHits(string $haystack, ?array &$swallowed = null): array
    {
        $swallowed = [];
        $keywords = [];
        $exclusions = [];

        foreach ($this->automaton()->search($haystack) as [$end, $entries, $length]) {
            $start = $end - $length;

            foreach ($entries as $entry) {
                if ($entry[0] === 'x') {
                    $exclusions[] = [$start, $end];
                } else {
                    $keywords[] = [$start, $end, $entry[1], $entry[2]];
                }
            }
        }

        $hits = [];
        $ends = [];

        foreach ($keywords as [$start, $end, $keyword, $weight]) {
            $overlapping = array_filter($exclusions, fn (array $x) => $this->sharesToken($start, $end, $x[0], $x[1]));

            if ($overlapping !== [] && ! $this->remainderHasKeyword($start, $end, $keywords, $overlapping)) {
                $swallowed[] = [$start, $keyword];

                continue;
            }

            $hits[] = [$start, $keyword, $weight];
            $ends[] = $end;
        }

        return $this->longestHits($hits, $ends);
    }

    /**
     * Drops a single-word hit lying entirely inside another kept hit (longest
     * match): "slot gacor" does not also count "gacor". A nested multi-word
     * keyword ("slot gacor" in "situs slot gacor") still counts, as before.
     * Hits with the very same span all stay.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}>  $hits
     * @param  array<int, int>  $ends  end offset of each hit
     * @return array<int, array{0: int, 1: string, 2: int}>
     */
    protected function longestHits(array $hits, array $ends): array
    {
        $order = array_keys($hits);
        // Start ascending, end descending: any earlier span starts no later.
        usort($order, fn (int $a, int $b) => [$hits[$a][0], $ends[$b]] <=> [$hits[$b][0], $ends[$a]]);

        $maxEnd = -1;
        $group = null;
        $groupContained = false;
        $drop = [];

        foreach ($order as $i) {
            $span = [$hits[$i][0], $ends[$i]];

            if ($span !== $group) {
                $maxEnd = $group === null ? $maxEnd : max($maxEnd, $group[1]);
                $group = $span;
                $groupContained = $maxEnd >= $span[1];
            }

            if ($groupContained && ! str_contains(TextNormalizer::foldKeyword($hits[$i][1]), ' ')) {
                $drop[$i] = true;
            }
        }

        return array_values(array_diff_key($hits, $drop));
    }

    /** Spans share a token; the +1/-1 skips sentinel spaces adjacent phrases legitimately share. */
    protected function sharesToken(int $start, int $end, int $otherStart, int $otherEnd): bool
    {
        return $start + 1 < $otherEnd - 1 && $end - 1 > $otherStart + 1;
    }

    /**
     * Whether the part of a keyword outside its exclusions holds another keyword:
     * "time slot gacor" keeps "slot gacor" only if "gacor" is itself a keyword,
     * while "kicau gacor hari ini" drops "gacor hari ini" ("hari ini" is not).
     *
     * @param  array<int, array{0: int, 1: int, 2: string, 3: int}>  $keywords
     * @param  array<int, array{0: int, 1: int}>  $overlapping
     */
    protected function remainderHasKeyword(int $start, int $end, array $keywords, array $overlapping): bool
    {
        foreach ($overlapping as [$xStart, $xEnd]) {
            // Fully inside an exclusion: no remainder at all.
            if ($start >= $xStart && $end <= $xEnd) {
                return false;
            }
        }

        foreach ($keywords as [$kStart, $kEnd]) {
            if ($kStart < $start || $kEnd > $end || ($kStart === $start && $kEnd === $end)) {
                continue;
            }

            foreach ($overlapping as [$xStart, $xEnd]) {
                if ($this->sharesToken($kStart, $kEnd, $xStart, $xEnd)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    protected function automaton(): AhoCorasick
    {
        if ($this->automaton !== null) {
            return $this->automaton;
        }

        $rules = $this->rules();
        $patterns = [];

        foreach ($rules['keywords'] as $keyword => $weight) {
            $folded = TextNormalizer::foldKeyword((string) $keyword);

            if ($folded === '') {
                continue;
            }

            // Spaced and run-together forms: "slot gacor" and "slotgacor".
            foreach (array_unique([$folded, str_replace(' ', '', $folded)]) as $form) {
                $patterns[' '.$form.' '][] = ['k', (string) $keyword, (int) $weight];
            }
        }

        foreach ($rules['exclusions'] as $phrase) {
            $folded = TextNormalizer::foldKeyword($phrase);

            if ($folded !== '') {
                $patterns[' '.$folded.' '][] = ['x'];
            }
        }

        return $this->automaton = new AhoCorasick($patterns);
    }

    /** @param  array<int, int>  $starts */
    protected function segmentAt(array $starts, int $offset): int
    {
        $low = 0;
        $high = count($starts) - 1;

        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);

            if ($starts[$mid] <= $offset) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low;
    }
}
