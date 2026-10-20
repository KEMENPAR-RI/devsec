<?php

namespace App\Services\Monitoring;

use App\Models\DefacementFinding;
use App\Models\PageChange;
use App\Models\PageSnapshot;
use App\Models\Website;
use App\Support\ContentFingerprint;
use App\Support\ElementIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Content-change history for every crawled page: keeps one compressed snapshot
 * per page and records added/changed/removed events against it.
 *
 * Only one page's text and one snapshot blob are in memory at a time - snapshots
 * are preloaded WITHOUT their blobs, and a blob is fetched only when the page's
 * hash differs.
 */
class PageContentMonitor
{
    /** Every snapshot column except the blobs and the element inventory. */
    protected const COLUMNS = [
        'id', 'website_id', 'url', 'url_hash', 'title', 'description', 'content_hash', 'text_length', 'block_count',
        'last_modified_at', 'status', 'missed_crawls', 'baseline_hash', 'baseline_title', 'baseline_excerpt',
        'baseline_text_length', 'baseline_signatures', 'baseline_at', 'first_seen_at', 'last_crawled_at',
        'raw_sha256', 'raw_bytes', 'raw_stored', 'final_url', 'http_status', 'redirect_count', 'elements_hash',
    ];

    /** Compressed blob ceiling; the text is cut further until it fits. */
    protected const MAX_BLOB_BYTES = 60000;

    protected const MAX_DIFF_CHUNKS = 8;

    /** Website the preloaded snapshots belong to; null outside a crawl. */
    protected ?int $websiteId = null;

    /** @var Collection<string, PageSnapshot> keyed by url_hash */
    protected Collection $snapshots;

    /** @var array<string, true> url_hash visited this crawl */
    protected array $visited = [];

    /** @var array<string, true> url_hash actually compared (WAF challenge pages excluded) */
    protected array $observed = [];

    protected bool $hadSnapshots = false;

    protected ?DefacementMonitor $defacement = null;

    protected ?PageTextExtractor $extractor = null;

    public function __construct()
    {
        $this->snapshots = collect();
    }

    public function beginCrawl(Website $website): void
    {
        $this->websiteId = $website->id;
        $this->visited = [];
        $this->observed = [];
        $this->snapshots = $website->pageSnapshots()->get(self::COLUMNS)->keyBy('url_hash');

        // Only a previous CRAWL counts as a baseline: a lone homepage snapshot from
        // the daily command (not yet crawled since) must not turn every page "new".
        $homepage = sha1(Str::before($website->url, '#'));
        $this->hadSnapshots = $this->snapshots->contains(fn (PageSnapshot $s) => $s->url_hash !== $homepage)
            || ($website->judol_scanned_at && $this->snapshots->get($homepage)?->first_seen_at?->lt($website->judol_scanned_at));
    }

    /**
     * Compares one successfully fetched page with its snapshot. Callers skip
     * failed fetches and sites under maintenance; WAF challenge pages are
     * skipped here. Returns the recorded event, if any.
     *
     * $response carries the raw fetch: body (as received, decoded), status,
     * final_url, redirects - the source of hashes, raw copy and forensics.
     *
     * @param  array{body: string, status?: ?int, final_url?: ?string, redirects?: ?int}|null  $response
     */
    public function observe(Website $website, string $url, PageText $text, ?Carbon $lastModified = null, ?int $judolDetectionId = null, ?array $response = null): ?PageChange
    {
        $urlHash = sha1($url);

        if ($this->websiteId !== $website->id) {
            $this->loadSingle($website, $urlHash);
        }

        $this->visited[$urlHash] = true;

        // A challenge page is skipped - unless it carries a defacer signature.
        if (TextNormalizer::isChallengePage($text)
            && $this->defacement()->detector()->newStrongSignatures($text, (array) ($this->snapshots->get($urlHash)?->baseline_signatures ?? [])) === []) {
            return null;
        }

        $this->observed[$urlHash] = true;

        $body = mb_substr($text->visibleText(), 0, (int) config('monitoring.content_snapshot_chars', 40000));
        $hash = sha1(TextNormalizer::maskDynamic($text->title."\n".$text->description."\n".$body));
        $snapshot = $this->snapshots->get($urlHash);

        // A page not fully read moves no content (text, raw copy, inventory, hashes):
        // only defacement is judged, against the snapshot on record. It was still
        // reached, so it is neither "missing" nor pruned as stale.
        if (! $text->complete) {
            if ($snapshot) {
                if ($snapshot->content_hash !== $hash) {
                    $this->evaluateIncomplete($website, $snapshot, $text, $body);
                }

                $snapshot->update(['last_crawled_at' => now(), 'missed_crawls' => 0, 'status' => 'active']);
            }

            return null;
        }

        $finalUrl = $response !== null ? (string) ($response['final_url'] ?? $url) : null;
        $rawHash = $response !== null ? hash('sha256', (string) $response['body']) : null;
        $elements = $this->elementMap($text);
        $elementsHash = ElementIdentity::hash($elements);

        if (! $snapshot) {
            return $this->firstSight($website, $url, $urlHash, $text, $body, $hash, $lastModified, $judolDetectionId, $response, $rawHash, $finalUrl, $elements, $elementsHash);
        }

        $previousCrawledAt = $snapshot->last_crawled_at;
        $previousHash = $snapshot->raw_sha256;
        $beforeBase = $snapshot->final_url ?? $snapshot->url;
        $attributes = [
            'last_crawled_at' => now(),
            'last_modified_at' => $lastModified ?? $snapshot->last_modified_at,
            'missed_crawls' => 0,
            'status' => 'active',
        ];

        $elementDiff = $this->elementDiff($snapshot, $elements, $elementsHash);
        $redirect = $this->redirectChange($snapshot->final_url, $finalUrl);
        $elementEvent = $redirect !== null || array_filter($elementDiff) !== [];
        $textChanged = $snapshot->content_hash !== $hash;
        $textEvent = false;
        $diff = ['added' => [], 'removed' => [], 'added_total' => 0, 'removed_total' => 0];
        $metaChanges = [];
        $ratio = null;
        $fingerprint = '';

        if ($textChanged) {
            $blobs = PageSnapshot::query()->whereKey($snapshot->id)->first(['id', 'content_gz', 'baseline_fingerprint']);
            $before = (string) $blobs?->text();
            $snapshot->setAttribute('baseline_fingerprint', $blobs?->baseline_fingerprint)->syncOriginalAttribute('baseline_fingerprint');
            unset($blobs);

            // One diff for both uses: the old columns keep the first MAX_DIFF_CHUNKS.
            $diff = ContentFingerprint::diff($before, $body, max(self::MAX_DIFF_CHUNKS, (int) config('monitoring.forensics_max_items', 50)));
            $fingerprint = ContentFingerprint::fingerprint($body);
            $ratio = ContentFingerprint::replacedRatio(ContentFingerprint::fingerprint($before), $fingerprint);
            $metaChanges = array_filter([
                'title' => $this->metaChange($snapshot->title, $text->title),
                'description' => $this->metaChange($snapshot->description, $text->description),
            ]);
            unset($before);

            $attributes += $this->contentAttributes($text, $body, $hash);
            $textEvent = $diff['added'] !== [] || $diff['removed'] !== [] || $metaChanges !== [];
        }

        $event = $textEvent || $elementEvent;

        // The previous raw copy is read into memory before it is overwritten below.
        $beforeHtml = null;
        $beforeReason = null;

        if ($event && $response !== null) {
            $beforeHtml = $this->rawStore()->get($snapshot, $beforeReason);
        }

        // File first: a crash before the DB write leaves a hash mismatch ("before"
        // unavailable), never an old copy passing as the previous crawl.
        $attributes += $this->rawAttributes($snapshot, $response, $rawHash, $finalUrl, $elements, $elementsHash);
        $original = $snapshot->getRawOriginal();

        try {
            // Text, raw facts, inventory, final URL and the event move together.
            $change = DB::transaction(function () use ($website, $snapshot, $attributes, $textChanged, $text, $body, $fingerprint, $event, $url, $textEvent, $elementEvent, $previousCrawledAt, $lastModified, $diff, $metaChanges, $ratio, $judolDetectionId, $response, $rawHash, $finalUrl, $previousHash) {
                $snapshot->update($attributes);
                $finding = $textChanged ? $this->defacement()->evaluate($website, $snapshot, $text, $body, $fingerprint) : null;

                if (! $event) {
                    return null;
                }

                return PageChange::create([
                    'website_id' => $website->id,
                    'page_snapshot_id' => $snapshot->id,
                    'url' => $url,
                    'type' => 'changed',
                    'content_kind' => $textEvent && $elementEvent ? 'mixed' : ($textEvent ? 'text' : 'element'),
                    'previous_crawled_at' => $previousCrawledAt,
                    'detected_at' => now(),
                    'page_last_modified_at' => $lastModified,
                    'added' => array_slice($diff['added'], 0, self::MAX_DIFF_CHUNKS) ?: null,
                    'removed' => array_slice($diff['removed'], 0, self::MAX_DIFF_CHUNKS) ?: null,
                    'meta_changes' => $metaChanges ?: null,
                    'change_ratio' => $ratio,
                    'judol_detection_id' => $judolDetectionId,
                    'defacement_finding_id' => $finding?->id,
                ] + $this->responseColumns($url, $response, $rawHash, $finalUrl, $previousHash));
            });
        } catch (Throwable $e) {
            // Rolled back: the in-memory snapshot must not claim what the DB does not hold.
            $snapshot->setRawAttributes($original, true);

            throw $e;
        } finally {
            $this->dropBlobs($snapshot);
        }

        if ($change && $response !== null) {
            $this->recordForensics($change, $text, (string) $response['body'], $beforeHtml, $beforeReason, $beforeBase, $finalUrl ?? $url, $diff, $metaChanges['title'] ?? null, $elementDiff, $redirect);
        }

        return $change;
    }

    /**
     * Pages that answered 404/410 are "removed"; on a crawl that drained its whole
     * queue, pages not even attempted for N crawls in a row are "missing". A page
     * that was attempted but failed (timeout, 5xx, 403, WAF) is left untouched.
     *
     * @param  array{complete?: bool, gone?: array<string, int>, attempted?: array<string, true>}  $outcome  attempted is keyed by sha1(url)
     * @return array<int, string> url hashes actually compared this crawl (not WAF pages)
     */
    public function finishCrawl(Website $website, array $outcome): array
    {
        if ($this->websiteId !== $website->id) {
            return [];
        }

        $observed = array_keys($this->observed);

        $goneHashes = [];

        foreach ($outcome['gone'] ?? [] as $url => $status) {
            $goneHashes[sha1($url)] = true;
            $snapshot = $this->snapshots->get(sha1($url));

            if ($snapshot && $snapshot->status === 'active') {
                $this->markRemoved($website, $snapshot, $status === 410 ? 'http_410' : 'http_404');
            }
        }

        if (! empty($outcome['complete'])) {
            $limit = max(1, (int) config('monitoring.content_missing_after_crawls', 3));
            $attempted = $outcome['attempted'] ?? [];
            $missed = $this->snapshots->filter(fn (PageSnapshot $s) => $s->status === 'active'
                && ! isset($this->visited[$s->url_hash]) && ! isset($attempted[$s->url_hash]) && ! isset($goneHashes[$s->url_hash]));

            if ($missed->isNotEmpty()) {
                PageSnapshot::query()->whereIn('id', $missed->pluck('id'))->increment('missed_crawls');

                foreach ($missed as $snapshot) {
                    if ($snapshot->missed_crawls + 1 >= $limit) {
                        $this->markRemoved($website, $snapshot, 'not_reached');
                    }
                }
            }
        }

        $this->websiteId = null;
        $this->snapshots = collect();
        $this->visited = [];
        $this->observed = [];

        return $observed;
    }

    /**
     * Drops history past the retention window, in batches.
     *
     * @return array{changes: int, snapshots: int, findings: int}
     */
    public function pruneExpired(int $days): array
    {
        $cutoff = now()->subDays($days);

        return [
            'changes' => $this->deleteInBatches(PageChange::query()->where('detected_at', '<', $cutoff)),
            'snapshots' => $this->deleteInBatches(PageSnapshot::query()->where('last_crawled_at', '<', $cutoff)),
            'findings' => $this->deleteInBatches(DefacementFinding::query()->where('status', '!=', 'open')->where('last_seen_at', '<', $cutoff)),
        ];
    }

    /**
     * @param  array<string, string>  $elements
     */
    protected function firstSight(Website $website, string $url, string $urlHash, PageText $text, string $body, string $hash, ?Carbon $lastModified, ?int $judolDetectionId, ?array $response, ?string $rawHash, ?string $finalUrl, array $elements, string $elementsHash): ?PageChange
    {
        $count = $this->websiteId === $website->id ? $this->snapshots->count() : $website->pageSnapshots()->count();

        if ($count >= (int) config('monitoring.content_max_pages_per_site', 150)) {
            return null;
        }

        try {
            $snapshot = PageSnapshot::create([
                'website_id' => $website->id,
                'url' => Str::limit($url, 2048, ''),
                'url_hash' => $urlHash,
                'last_modified_at' => $lastModified,
                'status' => 'active',
                'missed_crawls' => 0,
                'first_seen_at' => now(),
                'last_crawled_at' => now(),
            ] + $this->contentAttributes($text, $body, $hash));
        } catch (UniqueConstraintViolationException) {
            // The other scanner got there first; it owns the baseline this round.
            return null;
        }

        $snapshot->offsetUnset('content_gz');
        $this->snapshots->put($urlHash, $snapshot);
        $this->storeRaw($snapshot, $response, $rawHash, $finalUrl, $elements, $elementsHash);

        $finding = $this->defacement()->evaluate($website, $snapshot, $text, $body, ContentFingerprint::fingerprint($body));
        $this->dropBlobs($snapshot);

        // The site's first crawl is the baseline - no events for it.
        if (! $this->hadSnapshots) {
            return null;
        }

        return PageChange::create([
            'website_id' => $website->id,
            'page_snapshot_id' => $snapshot->id,
            'url' => $url,
            'type' => 'added',
            'detected_at' => now(),
            'page_last_modified_at' => $lastModified,
            'added' => array_map(
                fn (string $chunk) => mb_strimwidth($chunk, 0, ContentFingerprint::MAX_CHUNK_CHARS, '…'),
                array_slice(ContentFingerprint::chunks($body), 0, self::MAX_DIFF_CHUNKS)
            ) ?: null,
            'judol_detection_id' => $judolDetectionId,
            'defacement_finding_id' => $finding?->id,
        ] + $this->responseColumns($url, $response, $rawHash, $finalUrl, null));
    }

    /** Defacement only, against the old snapshot; the incomplete text is never stored. */
    protected function evaluateIncomplete(Website $website, PageSnapshot $snapshot, PageText $text, string $body): void
    {
        $snapshot->setAttribute('baseline_fingerprint', PageSnapshot::query()->whereKey($snapshot->id)->value('baseline_fingerprint'))
            ->syncOriginalAttribute('baseline_fingerprint');
        $this->defacement()->evaluate($website, $snapshot, $text, $body, ContentFingerprint::fingerprint($body));
        $this->dropBlobs($snapshot);
    }

    /** @return array<string, string> inventory key => value */
    protected function elementMap(PageText $text): array
    {
        $map = [];

        foreach ($text->elements as $element) {
            $map[$element['key']] = $element['value'];
        }

        return $map;
    }

    /**
     * Element changes against the stored inventory; none while there is no
     * inventory yet (the first crawl with this feature only seeds it).
     *
     * @param  array<string, string>  $elements
     * @return array{added: array<string, string>, removed: array<string, string>, modified: array<string, array{before: string, after: string}>}
     */
    protected function elementDiff(PageSnapshot $snapshot, array $elements, string $elementsHash): array
    {
        if ($snapshot->elements_hash === null || $snapshot->elements_hash === $elementsHash) {
            return ['added' => [], 'removed' => [], 'modified' => []];
        }

        $before = (array) PageSnapshot::query()->whereKey($snapshot->id)->first(['id', 'elements'])?->elements;

        return ElementIdentity::diff($before, $elements);
    }

    /** The final URL moved to another registrable domain since the last complete crawl. */
    protected function redirectChange(?string $before, ?string $after): ?array
    {
        if ($before === null || $after === null) {
            return null;
        }

        $from = $this->extractor()->pageDomain($before);
        $to = $this->extractor()->pageDomain($after);

        return $from !== null && $to !== null && $from !== $to ? ['before' => $before, 'after' => $after] : null;
    }

    /** @return array<string, mixed> summary columns of page_changes */
    protected function responseColumns(string $url, ?array $response, ?string $rawHash, ?string $finalUrl, ?string $previousHash): array
    {
        if ($response === null) {
            return [];
        }

        return [
            'http_status' => isset($response['status']) ? (int) $response['status'] : null,
            'final_url' => $finalUrl !== null && $finalUrl !== $url ? Str::limit($finalUrl, 2048, '') : null,
            'redirect_count' => isset($response['redirects']) ? min(255, (int) $response['redirects']) : null,
            'hash_algorithm' => ContentForensics::HASH_ALGORITHM,
            'body_hash_before' => $previousHash,
            'body_hash_after' => $rawHash,
        ];
    }

    /**
     * Built after the event is committed, from the previous raw copy already in
     * memory. A failure leaves an "error" payload; the event itself stays.
     *
     * @param  array{added: array<int, string>, removed: array<int, string>, added_total: int, removed_total: int}  $diff
     */
    protected function recordForensics(PageChange $change, PageText $text, string $body, ?string $beforeHtml, ?string $beforeReason, string $beforeBase, string $finalUrl, array $diff, ?array $title, array $elementDiff, ?array $redirect): void
    {
        $forensics = app(ContentForensics::class);

        try {
            $hidden = [];

            foreach ($text->elements as $element) {
                $hidden[$element['key']] = $element['hidden'];
            }

            [$payload, $json, $gz] = $forensics->build([
                'before_unavailable' => $beforeReason,
                'before_base' => $beforeBase,
                'after_base' => $finalUrl,
                'title' => $title,
                'redirect' => $redirect,
                'hidden' => $hidden,
            ], $diff, $elementDiff, $beforeHtml, $body);
            unset($beforeHtml);

            $forensics->store($change, $payload, $json, $gz);
        } catch (Throwable $e) {
            Log::warning("PageContentMonitor: detail forensik {$change->url} gagal dibuat: {$e->getMessage()}");

            try {
                $forensics->store($change, ['v' => 1, 'unavailable' => 'error', 'before_unavailable' => null, 'counts' => [], 'undetailed' => 0, 'items' => []]);
            } catch (Throwable) {
                // The event itself stays; the detail reads "tidak tersedia".
            }
        }
    }

    /**
     * Snapshot columns for the raw copy, response facts and inventory - written
     * in the same update as the text so "before" always matches the last crawl.
     * The gzip file is (re)written here when the body changed or the file is gone.
     *
     * @param  array<string, string>  $elements
     * @return array<string, mixed>
     */
    protected function rawAttributes(PageSnapshot $snapshot, ?array $response, ?string $rawHash, ?string $finalUrl, array $elements, string $elementsHash): array
    {
        $attributes = [];

        if ($snapshot->elements_hash !== $elementsHash) {
            $attributes['elements_hash'] = $elementsHash;
            $attributes['elements'] = $elements;
        }

        if ($response === null) {
            return $attributes;
        }

        $attributes += [
            'http_status' => isset($response['status']) ? (int) $response['status'] : null,
            'final_url' => $finalUrl !== null ? Str::limit($finalUrl, 2048, '') : null,
            'redirect_count' => isset($response['redirects']) ? min(255, (int) $response['redirects']) : null,
        ];

        if ($snapshot->raw_sha256 !== $rawHash || ! $snapshot->raw_stored || ! $this->rawStore()->exists($snapshot)) {
            try {
                $stored = $this->rawStore()->put($snapshot, (string) $response['body']);
            } catch (Throwable $e) {
                Log::warning("PageContentMonitor: salinan HTML {$snapshot->url} gagal disimpan: {$e->getMessage()}");
                $stored = false;
            }

            $attributes += [
                'raw_sha256' => $rawHash,
                'raw_bytes' => min(strlen((string) $response['body']), 4294967295),
                'raw_stored' => $stored,
            ];
        }

        return $attributes;
    }

    /** @param  array<string, string>  $elements */
    protected function storeRaw(PageSnapshot $snapshot, ?array $response, ?string $rawHash, ?string $finalUrl, array $elements, string $elementsHash): void
    {
        $snapshot->update($this->rawAttributes($snapshot, $response, $rawHash, $finalUrl, $elements, $elementsHash));
        $snapshot->offsetUnset('elements');
        $snapshot->syncOriginal();
    }

    /** Blobs are only needed while one page is compared - never kept across the crawl. */
    protected function dropBlobs(PageSnapshot $snapshot): void
    {
        $snapshot->offsetUnset('elements');
        $snapshot->offsetUnset('content_gz');
        $snapshot->offsetUnset('baseline_fingerprint');
        $snapshot->syncOriginal();
    }

    /** Outside a crawl (daily homepage check): load just this page's snapshot. */
    protected function loadSingle(Website $website, string $urlHash): void
    {
        $this->snapshots = $website->pageSnapshots()->where('url_hash', $urlHash)->get(self::COLUMNS)->keyBy('url_hash');
        $this->hadSnapshots = $this->snapshots->isNotEmpty() || $website->pageSnapshots()->exists();
    }

    protected function markRemoved(Website $website, PageSnapshot $snapshot, string $reason): void
    {
        $snapshot->update(['status' => $reason === 'not_reached' ? 'missing' : 'removed']);

        PageChange::create([
            'website_id' => $website->id,
            'page_snapshot_id' => $snapshot->id,
            'url' => $snapshot->url,
            'type' => 'removed',
            'previous_crawled_at' => $snapshot->last_crawled_at,
            'detected_at' => now(),
            'meta_changes' => ['reason' => $reason],
        ]);
    }

    /** @return array<string, mixed> */
    protected function contentAttributes(PageText $text, string $body, string $hash): array
    {
        return [
            'title' => Str::limit($text->title, 255, ''),
            'description' => Str::limit($text->description, 500, ''),
            'content_hash' => $hash,
            'content_gz' => $this->compress($body),
            'text_length' => min($text->textLength, 4294967295),
            'block_count' => min($text->blockCount, 65535),
        ];
    }

    protected function compress(string $text): string
    {
        $blob = gzcompress($text, 6);

        while (strlen($blob) > self::MAX_BLOB_BYTES && $text !== '') {
            $text = mb_substr($text, 0, intdiv(mb_strlen($text), 2));
            $blob = gzcompress($text, 6);
        }

        return $blob;
    }

    /** @return array{before: ?string, after: ?string}|null */
    protected function metaChange(?string $before, string $after): ?array
    {
        $before = (string) $before;

        return TextNormalizer::maskDynamic($before) === TextNormalizer::maskDynamic($after)
            ? null
            : ['before' => $before !== '' ? $before : null, 'after' => $after !== '' ? $after : null];
    }

    protected function deleteInBatches($query): int
    {
        $deleted = 0;

        do {
            $batch = (clone $query)->orderBy('id')->limit(500)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        return $deleted;
    }

    protected function rawStore(): PageRawStore
    {
        return app(PageRawStore::class);
    }

    protected function extractor(): PageTextExtractor
    {
        return $this->extractor ??= app(PageTextExtractor::class);
    }

    protected function defacement(): DefacementMonitor
    {
        return $this->defacement ??= app(DefacementMonitor::class);
    }
}
