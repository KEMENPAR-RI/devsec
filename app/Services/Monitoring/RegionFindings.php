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
    /** The region a keyword match falls back to when no element could be pinned down. */
    public const FALLBACK_REGION = ['key' => 'page_body', 'label' => 'Isi halaman', 'selector' => null];

    /**
     * Promo banners kept per region. A footer holds a logo, payment icons and a
     * dozen widget thumbnails; past a handful the list stops being evidence.
     */
    public const MAX_STORED_IMAGES = 6;

    /**
     * @var array<string, array{
     *     region: array{key: string, label: string, selector: ?string},
     *     domains: array<string, true>,
     *     anchors: array<string, true>,
     *     keywords: array<string, true>,
     *     images: array<string, true>,
     *     path: ?string,
     *     snippet: ?string,
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
     * @param  array<int, string>  $images  promo banners sitting in the same region
     * @param  array{path: ?string, html: ?string}|null  $evidence  where exactly, and the markup
     */
    public function addLinks(string $url, array $region, array $domains, array $anchors, array $images = [], ?array $evidence = null): void
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

        $this->rememberImages($bucket, $images);
        $this->rememberEvidence($bucket, $evidence);
        $this->rememberUrl($bucket, $url);
    }

    /**
     * Records that this URL carries gambling keywords in this region.
     *
     * The region is passed in rather than assumed: a keyword injection is only
     * actionable once you know WHERE the text sits. "Isi halaman" - the fallback -
     * is what the caller sends when the phrase could not be pinned to an element,
     * which happens when it lives in the URL slug or is split across inline tags.
     *
     * @param  array<int, string>  $keywords
     * @param  array{key: string, label: string, selector: ?string}|null  $region
     * @param  array<int, string>  $images
     * @param  array{path: ?string, html: ?string}|null  $evidence
     */
    public function addKeywords(string $url, array $keywords, ?array $region = null, array $images = [], ?array $evidence = null): void
    {
        // No keywords, no finding. The scanner calls this for every crawled page,
        // and bucket() below would otherwise open a keyword_injection region on a
        // site where nothing matched - a finding that reports nothing.
        if ($keywords === []) {
            return;
        }

        $bucket = &$this->bucket($region ?? self::FALLBACK_REGION, 'keyword_injection');

        foreach ($keywords as $keyword) {
            $bucket['keywords'][$keyword] = true;
        }

        $this->rememberImages($bucket, $images);
        $this->rememberEvidence($bucket, $evidence);
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
                'image_urls' => array_keys($bucket['images']),
                'affected_urls' => array_slice($urls, 0, JudolFinding::MAX_STORED_URLS),
                'affected_count' => $count,
                'last_seen_at' => now(),
            ];

            // Only written when this crawl actually produced them. A crawl that hit
            // the fallback region has no snippet to offer, and blanking a good one
            // recorded earlier would lose the only pointer at the exact node.
            foreach (['evidence_html' => $bucket['snippet'], 'dom_path' => $bucket['path']] as $column => $value) {
                if (filled($value)) {
                    $attributes[$column] = $value;
                }
            }

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
     * @return array{region: array{key: string, label: string, selector: ?string}, domains: array<string, true>, anchors: array<string, true>, keywords: array<string, true>, images: array<string, true>, path: ?string, snippet: ?string, urls: array<int, string>, seen: array<string, true>, type: string}
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
                'images' => [],
                'path' => null,
                'snippet' => null,
                'urls' => [],
                'seen' => [],
                'type' => $type,
            ];
        }

        return $this->regions[$key];
    }

    /**
     * @param  array{images: array<string, true>}  $bucket
     * @param  array<int, string>  $images
     */
    protected function rememberImages(array &$bucket, array $images): void
    {
        foreach ($images as $image) {
            if (count($bucket['images']) >= self::MAX_STORED_IMAGES) {
                return;
            }

            if (trim($image) !== '') {
                $bucket['images'][$image] = true;
            }
        }
    }

    /**
     * First sighting wins. The same region on forty pages yields forty identical
     * snippets, and the one from the page the injection was first seen on is as
     * good as any - while re-deriving it every page would only churn the column.
     *
     * @param  array{path: ?string, snippet: ?string}  $bucket
     * @param  array{path: ?string, html: ?string}|null  $evidence
     */
    protected function rememberEvidence(array &$bucket, ?array $evidence): void
    {
        if ($evidence === null) {
            return;
        }

        $bucket['path'] ??= $evidence['path'] ?? null;
        $bucket['snippet'] ??= $evidence['html'] ?? null;
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
