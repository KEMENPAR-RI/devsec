<?php

namespace App\Services\Monitoring;

use App\Models\JudolFinding;
use App\Models\Website;

/**
 * Collects per-region evidence across a whole crawl, then writes it out as one
 * finding per injection site.
 *
 * Accumulating rather than writing as it goes is the point: whether a footer link
 * is a sitewide injection or a one-off cannot be known from the page it was found
 * on - only from how many pages carried the same region. So the crawl records,
 * and the conclusion is drawn once at the end, when the count is known.
 */
class RegionFindings
{
    /**
     * @var array<string, array{
     *     region: array{key: string, label: string, selector: ?string},
     *     domains: array<string, true>,
     *     anchors: array<string, true>,
     *     keywords: array<string, true>,
     *     urls: array<int, string>,
     *     seen: array<string, true>,
     *     type: string,
     * }>
     */
    protected array $regions = [];

    /**
     * Records that this URL carries gambling links in this region.
     *
     * @param  array{key: string, label: string, selector: ?string}  $region
     * @param  array<int, string>  $domains
     * @param  array<int, string>  $anchors
     */
    public function addLinks(string $url, array $region, array $domains, array $anchors): void
    {
        $bucket = &$this->bucket($region, 'backlink_injection');

        foreach ($domains as $domain) {
            $bucket['domains'][$domain] = true;
        }

        foreach ($anchors as $anchor) {
            if (trim($anchor) !== '') {
                $bucket['anchors'][$anchor] = true;
            }
        }

        $this->rememberUrl($bucket, $url);
    }

    /**
     * Records that this URL carries gambling keywords. Keywords come from the page
     * text as a whole rather than from a located element, so they are filed under
     * the page body - the region a text injection actually lives in.
     *
     * @param  array<int, string>  $keywords
     */
    public function addKeywords(string $url, array $keywords): void
    {
        // No keywords, no finding. The scanner calls this for every crawled page,
        // and bucket() below would otherwise open a keyword_injection region on a
        // site where nothing matched - a finding that reports nothing.
        if ($keywords === []) {
            return;
        }

        $bucket = &$this->bucket(
            ['key' => 'page_body', 'label' => 'Isi halaman', 'selector' => null],
            'keyword_injection'
        );

        foreach ($keywords as $keyword) {
            $bucket['keywords'][$keyword] = true;
        }

        $this->rememberUrl($bucket, $url);
    }

    public function isEmpty(): bool
    {
        return $this->regions === [];
    }

    /**
     * Writes the accumulated regions to judol_findings, one row each.
     *
     * Existing rows are updated rather than duplicated: a region that was already
     * reported and is still there is the same finding seen again, and resetting it
     * to 'new' every crawl would undo an operator's triage. first_seen_at is
     * likewise preserved - it is how long the injection has been up, which is the
     * number that matters when correlating against access logs.
     *
     * @return array<int, JudolFinding> findings that were newly created
     */
    public function persist(Website $website): array
    {
        $created = [];

        foreach ($this->regions as $bucket) {
            $urls = $bucket['urls'];
            // count($seen), not count($urls): the stored list is capped, the count
            // must still be the real one - "muncul di 40 halaman" is the headline.
            $count = count($bucket['seen']);

            $existing = $website->judolFindings()
                ->where('region_key', $bucket['region']['key'])
                ->where('attack_type', $bucket['type'])
                ->first();

            $attributes = [
                'region_label' => $bucket['region']['label'],
                'region_selector' => $bucket['region']['selector'],
                'linked_domains' => array_keys($bucket['domains']),
                'anchor_texts' => array_keys($bucket['anchors']),
                'matched_keywords' => array_keys($bucket['keywords']),
                'affected_urls' => array_slice($urls, 0, JudolFinding::MAX_STORED_URLS),
                'affected_count' => $count,
                'last_seen_at' => now(),
            ];

            if ($existing) {
                $existing->update($attributes);

                continue;
            }

            $created[] = $website->judolFindings()->create([
                ...$attributes,
                'region_key' => $bucket['region']['key'],
                'attack_type' => $bucket['type'],
                'status' => 'new',
                'first_seen_at' => now(),
            ]);
        }

        return $created;
    }

    /**
     * @param  array{key: string, label: string, selector: ?string}  $region
     * @return array{region: array{key: string, label: string, selector: ?string}, domains: array<string, true>, anchors: array<string, true>, keywords: array<string, true>, urls: array<int, string>, type: string}
     */
    protected function &bucket(array $region, string $type): array
    {
        $key = $region['key'].'|'.$type;

        if (! isset($this->regions[$key])) {
            $this->regions[$key] = [
                'region' => $region,
                'domains' => [],
                'anchors' => [],
                'keywords' => [],
                'urls' => [],
                'seen' => [],
                'type' => $type,
            ];
        }

        return $this->regions[$key];
    }

    /**
     * @param  array{urls: array<int, string>, seen: array<string, true>}  $bucket
     */
    protected function rememberUrl(array &$bucket, string $url): void
    {
        if (isset($bucket['seen'][$url])) {
            return;
        }

        // Every URL counts toward the total; only the first MAX_STORED_URLS are
        // kept as a list. A sitewide injection on a large site would otherwise
        // store thousands of URLs that nobody reads past the first screen of.
        $bucket['seen'][$url] = true;

        if (count($bucket['urls']) < JudolFinding::MAX_STORED_URLS) {
            $bucket['urls'][] = $url;
        }
    }
}
