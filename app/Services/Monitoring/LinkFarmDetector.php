<?php

namespace App\Services\Monitoring;

use App\Services\AppSettings;

/**
 * Deterministic judol link farming: external link URLs matched against the
 * judol keyword list, aggregated per destination domain. No AI involved.
 */
class LinkFarmDetector
{
    public function __construct(protected UrlKeywordMatcher $matcher) {}

    /**
     * @param  array<int, array{url: string, domain: string, hidden: bool}>  $externalLinks
     * @return array{domains: array<string, array{keywords: array<int, string>, score: int, hidden: bool, sample_url: string}>, stats: array{external_links: int, hidden_external_links: int, hidden_ratio: float}}
     */
    public function detect(array $externalLinks): array
    {
        $threshold = app(AppSettings::class)->judolScoreThreshold();
        $max = max(1, (int) config('judol.url_match.max_links', 3000));
        $ignored = array_map(strtolower(...), (array) config('judol.url_match.ignored_hosts', []));
        $perDomain = [];
        $matched = [];
        $hidden = 0;
        $total = 0;

        foreach (array_slice($externalLinks, 0, $max) as $link) {
            $domain = (string) $link['domain'];
            $total++;
            $hidden += $link['hidden'] ? 1 : 0;

            if ($this->ignored($domain, $ignored)) {
                continue;
            }

            $keywords = $matched[(string) $link['url']] ??= $this->matcher->match((string) $link['url']);

            if ($keywords === []) {
                continue;
            }

            $perDomain[$domain] ??= ['keywords' => [], 'hidden' => false, 'sample_url' => (string) $link['url']];
            $perDomain[$domain]['keywords'] += $keywords;
            $perDomain[$domain]['hidden'] = $perDomain[$domain]['hidden'] || $link['hidden'];
        }

        $flagged = [];

        foreach ($perDomain as $domain => $entry) {
            $score = array_sum($entry['keywords']);

            if ($score >= $threshold) {
                $flagged[$domain] = [
                    'keywords' => array_keys($entry['keywords']),
                    'score' => $score,
                    'hidden' => $entry['hidden'],
                    'sample_url' => $entry['sample_url'],
                ];
            }
        }

        return [
            'domains' => $flagged,
            'stats' => [
                'external_links' => $total,
                'hidden_external_links' => $hidden,
                'hidden_ratio' => $total > 0 ? round($hidden / $total, 4) : 0.0,
            ],
        ];
    }

    /** @param  array<int, string>  $ignored */
    protected function ignored(string $domain, array $ignored): bool
    {
        foreach ($ignored as $host) {
            if ($domain === $host || str_ends_with($domain, '.'.$host)) {
                return true;
            }
        }

        return false;
    }
}
