<?php

namespace App\Services\Monitoring;

use App\Models\DefacementFinding;
use App\Models\PageChange;
use App\Models\PageSnapshot;
use App\Models\Website;
use App\Support\ContentFingerprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
    /** Every snapshot column except the two blobs. */
    protected const COLUMNS = [
        'id', 'website_id', 'url', 'url_hash', 'title', 'description', 'content_hash', 'text_length', 'block_count',
        'last_modified_at', 'status', 'missed_crawls', 'baseline_hash', 'baseline_title', 'baseline_excerpt',
        'baseline_text_length', 'baseline_signatures', 'baseline_at', 'first_seen_at', 'last_crawled_at',
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
     */
    public function observe(Website $website, string $url, PageText $text, ?Carbon $lastModified = null, ?int $judolDetectionId = null): ?PageChange
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

        if (! $snapshot) {
            return $this->firstSight($website, $url, $urlHash, $text, $body, $hash, $lastModified, $judolDetectionId);
        }

        $previousCrawledAt = $snapshot->last_crawled_at;
        $touch = [
            'last_crawled_at' => now(),
            'last_modified_at' => $lastModified ?? $snapshot->last_modified_at,
            'missed_crawls' => 0,
            'status' => 'active',
        ];

        if ($snapshot->content_hash === $hash) {
            $snapshot->update($touch);

            return null;
        }

        $blobs = PageSnapshot::query()->whereKey($snapshot->id)->first(['id', 'content_gz', 'baseline_fingerprint']);
        $before = (string) $blobs?->text();
        $snapshot->setAttribute('baseline_fingerprint', $blobs?->baseline_fingerprint)->syncOriginalAttribute('baseline_fingerprint');
        unset($blobs);

        $diff = ContentFingerprint::diff($before, $body, self::MAX_DIFF_CHUNKS);
        $fingerprint = ContentFingerprint::fingerprint($body);
        $ratio = ContentFingerprint::replacedRatio(ContentFingerprint::fingerprint($before), $fingerprint);
        $metaChanges = array_filter([
            'title' => $this->metaChange($snapshot->title, $text->title),
            'description' => $this->metaChange($snapshot->description, $text->description),
        ]);
        unset($before);

        $snapshot->update($touch + $this->contentAttributes($text, $body, $hash));
        $finding = $this->defacement()->evaluate($website, $snapshot, $text, $body, $fingerprint);
        $this->dropBlobs($snapshot);

        if ($diff['added'] === [] && $diff['removed'] === [] && $metaChanges === []) {
            return null;
        }

        return PageChange::create([
            'website_id' => $website->id,
            'page_snapshot_id' => $snapshot->id,
            'url' => $url,
            'type' => 'changed',
            'previous_crawled_at' => $previousCrawledAt,
            'detected_at' => now(),
            'page_last_modified_at' => $lastModified,
            'added' => $diff['added'] ?: null,
            'removed' => $diff['removed'] ?: null,
            'meta_changes' => $metaChanges ?: null,
            'change_ratio' => $ratio,
            'judol_detection_id' => $judolDetectionId,
            'defacement_finding_id' => $finding?->id,
        ]);
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

    protected function firstSight(Website $website, string $url, string $urlHash, PageText $text, string $body, string $hash, ?Carbon $lastModified, ?int $judolDetectionId): ?PageChange
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
        ]);
    }

    /** Blobs are only needed while one page is compared - never kept across the crawl. */
    protected function dropBlobs(PageSnapshot $snapshot): void
    {
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

    protected function defacement(): DefacementMonitor
    {
        return $this->defacement ??= app(DefacementMonitor::class);
    }
}
