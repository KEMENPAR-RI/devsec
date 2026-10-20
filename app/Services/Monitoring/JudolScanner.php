<?php

namespace App\Services\Monitoring;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\JudolDetection;
use App\Models\JudolFinding;
use App\Models\User;
use App\Models\Website;
use App\Notifications\JudolContentDetected;
use App\Services\AppSettings;
use App\Services\Notifications\MailSettings;
use App\Services\Notifications\WebhookNotifier;
use App\Services\Security\OutboundUrlGuard;
use App\Support\ResponseSizeLimit;
use DOMDocument;
use DOMText;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Crawls a website - homepage, sitemap(s), and every internal link reachable from
 * them - looking for pages containing online-gambling ("judol") spam keywords, a
 * common signature of a hacked/compromised site (e.g. a stray slot.html nobody
 * linked to on purpose).
 */
class JudolScanner
{
    /**
     * File extensions never worth fetching as a "page" (assets, not content).
     */
    protected const SKIPPED_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'bmp',
        'css', 'js', 'mjs', 'map',
        'pdf', 'zip', 'rar', '7z', 'doc', 'docx', 'xls', 'xlsx',
        'mp3', 'mp4', 'avi', 'mov', 'wav', 'ogg',
        'woff', 'woff2', 'ttf', 'eot', 'otf',
        'xml', 'json', 'txt', 'rss', 'atom',
    ];

    /**
     * A real browser UA - many hosting WAFs (Cloudflare, Imunify360, mod_security,
     * ...) silently block/challenge requests that look like non-browser bots,
     * even though the same URL loads fine in an actual browser.
     */
    public const BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    /** Image formats the Messages API can read - see looksLikeSupportedImage(). */
    protected const SUPPORTED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** Suffixes where the registered name is the THIRD label - see registrableDomain(). */
    protected const TWO_LABEL_SUFFIXES = [
        'co.id', 'go.id', 'ac.id', 'or.id', 'net.id', 'web.id', 'sch.id', 'my.id', 'biz.id', 'desa.id', 'mil.id',
        'co.uk', 'org.uk', 'gov.uk', 'ac.uk', 'com.au', 'com.sg', 'com.my', 'co.jp', 'com.br',
    ];

    /** Characters of page text kept per detection - enough to quote, not a mirror. */
    protected const SNAPSHOT_LIMIT = 40000;

    /** Added/removed passages reported per change, so a rewritten page stays readable. */
    protected const MAX_DIFF_CHUNKS = 8;

    /** Seconds spent in AI calls during the current scan (reset per scan). */
    protected float $aiSeconds = 0.0;

    protected bool $aiBudgetLogged = false;

    /** @var array{complete: bool, gone: array<string, int>, attempted: array<string, true>} how the last crawl() ended */
    protected array $crawlOutcome = ['complete' => false, 'gone' => [], 'attempted' => []];

    protected ?JudolMatcher $matcher = null;

    protected ?PageTextExtractor $extractor = null;

    /** @var array{detections: array<int, int>, findings: array<int, int>} ids removed by the last prune calls */
    protected array $pruned = ['detections' => [], 'findings' => []];

    /**
     * Full-site scan: crawl every reachable page and flag new judol matches.
     *
     * @return int number of new detections created
     */
    public function scan(Website $website): int
    {
        $this->resetAiBudget();
        $found = collect();

        // Per-page detections answer "which pages are dirty"; this answers "what was
        // changed". A backlink dropped into a shared footer shows up on every page
        // that renders it, and reporting that once - with the region and the list of
        // affected URLs - is the difference between one actionable finding and forty
        // rows repeating each other. See RegionFindings.
        $regions = new RegionFindings;

        // Rules may have changed since this site was last crawled (keyword weight,
        // exclusion, threshold) - settle its stored results first.
        $this->pruneStaleDetections($website);
        $this->pruneStaleFindings($website);

        // The keywords already on record for this site, keyed by URL - one query
        // rather than one per crawled page. evaluateAndStore() deliberately skips a
        // page it flagged on an earlier crawl, so without this the text injection
        // that covers forty pages would report "1 halaman" from the second crawl on,
        // while the backlink beside it kept counting all forty.
        $stored = $website->judolDetections()->get(['id', 'url', 'matched_keywords'])->keyBy('url');

        // Content history and defacement ride on the same fetches; a site under
        // maintenance is expected to change, so both sit that crawl out.
        $content = $website->isUnderMaintenance() ? null : app(PageContentMonitor::class);
        $content?->beginCrawl($website);

        foreach ($this->crawl($website) as $url => $page) {
            $pageText = $this->extractor()->extract($page['html'], $url, ! $page['truncated']);

            $this->recordRegions($website, $regions, $url, $page['html']);

            $detection = $this->evaluateAndStore($website, $url, $page['html'], lastModified: $page['last_modified'], pageText: $pageText);

            if ($detection) {
                $found->push($detection);
            }

            // Same reason recordRegions() runs unconditionally: what proves an
            // injection is sitewide is the pages that were ALREADY flagged.
            $this->recordKeywordRegions($regions, $url, $page['html'], $detection
                ? $detection->matched_keywords ?? []
                : $stored->get($url)?->matched_keywords ?? []);

            $this->observeContent($content, $website, $url, $pageText, $page['last_modified'], $detection?->id ?? $stored->get($url)?->id);
        }

        if ($content) {
            $this->settleContent($content, $website);
        }

        $newFindings = $regions->persist($website);

        // Marks this site "just scanned" so isDueForJudolScan() waits out its
        // interval before the scheduler queues another full crawl for it.
        $website->update(['judol_scanned_at' => now()]);

        // One notification for the whole crawl, not one per flagged page - see
        // JudolContentDetected.
        $this->notifyFindings($website, $found, $newFindings);

        return $found->count();
    }

    /** Content history + defacement for one page; never allowed to break the judol crawl. */
    protected function observeContent(?PageContentMonitor $content, Website $website, string $url, PageText $pageText, ?Carbon $lastModified, ?int $detectionId): void
    {
        if (! $content) {
            return;
        }

        try {
            $content->observe($website, $url, $pageText, $lastModified, $detectionId);
        } catch (Throwable $e) {
            Log::warning("JudolScanner: gagal mencatat perubahan konten {$url}: {$e->getMessage()}");
        }
    }

    protected function settleContent(PageContentMonitor $content, Website $website): void
    {
        try {
            $seen = $content->finishCrawl($website, $this->lastCrawlOutcome());
            app(DefacementMonitor::class)->settle($website, $seen);
        } catch (Throwable $e) {
            Log::warning("JudolScanner: gagal menyelesaikan pemantauan konten {$website->url}: {$e->getMessage()}");
        }
    }

    /**
     * How the last crawl() ended: complete = the queue drained (not cut by the
     * deadline or page budget), gone = URLs that answered 404/410, attempted =
     * sha1 of every URL fetched (successfully or not).
     *
     * @return array{complete: bool, gone: array<string, int>, attempted: array<string, true>}
     */
    public function lastCrawlOutcome(): array
    {
        return $this->crawlOutcome;
    }

    protected function matcher(): JudolMatcher
    {
        return $this->matcher ??= app(JudolMatcher::class);
    }

    protected function extractor(): PageTextExtractor
    {
        return $this->extractor ??= app(PageTextExtractor::class);
    }

    /**
     * Files this page's evidence under the region of markup it came from.
     *
     * Runs alongside evaluateAndStore() rather than inside it: that method returns
     * early for a URL it has already flagged, which is exactly the case this needs
     * to see - the fortieth page carrying the same footer is what proves the
     * injection is sitewide.
     */
    protected function recordRegions(Website $website, RegionFindings $regions, string $url, string $html): void
    {
        $host = parse_url($website->url, PHP_URL_HOST);
        $located = $this->extractLocatedOutboundLinks($html, $url, $host);

        if ($located === []) {
            return;
        }

        // Ask the classifier once for every domain on the page, then attribute the
        // answers back to the regions they came from - one call per page rather
        // than one per region.
        $domains = array_values(array_unique(array_column($located, 'domain')));
        $flagged = $this->flagGamblingDomains($domains, array_column($located, 'text', 'domain'));

        if ($flagged === []) {
            return;
        }

        $pageRegion = app(PageRegion::class);
        $imagesByRegion = $this->extractLocatedImages($html, $url);
        $byRegion = [];

        foreach ($located as $link) {
            if (! in_array($link['domain'], $flagged, true)) {
                continue;
            }

            $key = $link['region']['key'];
            $byRegion[$key] ??= [
                'region' => $link['region'],
                'domains' => [],
                'anchors' => [],
                'evidence' => [],
            ];
            $byRegion[$key]['domains'][] = $link['domain'];
            $byRegion[$key]['anchors'][] = $link['text'];

            // EVERY flagged link, not just the first in the region. Later links do
            // resolve to the same container, so the snippet repeats - but their
            // paths do not, and those paths are the list of edits to make. Reporting
            // one of eight injected list items reads as the whole problem, and an
            // operator who cleans from it leaves seven behind.
            if (count($byRegion[$key]['evidence']) < RegionFindings::MAX_STORED_NODES) {
                $byRegion[$key]['evidence'][] = [
                    'path' => $pageRegion->path($link['node']),
                    'html' => $pageRegion->snippet($link['node']),
                ];
            }
        }

        foreach ($byRegion as $key => $group) {
            $regions->addLinks(
                $url,
                $group['region'],
                array_unique($group['domains']),
                array_unique($group['anchors']),
                $imagesByRegion[$key] ?? [],
                $group['evidence'],
            );
        }
    }

    /**
     * Files this page's keyword matches under the region of markup each phrase was
     * actually found in.
     *
     * Before this, every keyword match on every site was filed under one hardcoded
     * "Isi halaman" region, which is not a location - it is the absence of one. A
     * text injection is as template-shaped as a backlink is (spam dropped into a
     * widget, a footer blurb, the meta description), and the operator needs the
     * same answer here as there: which block to open.
     *
     * @param  array<int, string>  $keywords
     */
    protected function recordKeywordRegions(RegionFindings $regions, string $url, string $html, array $keywords): void
    {
        if ($keywords === []) {
            return;
        }

        $located = $this->locateKeywords($html, $keywords);
        $imagesByRegion = $this->extractLocatedImages($html, $url);

        foreach ($located['regions'] as $group) {
            $regions->addKeywords(
                $url,
                $group['keywords'],
                $group['region'],
                $imagesByRegion[$group['region']['key']] ?? [],
                $group['evidence'],
            );
        }

        // Phrases that matched the page but could not be pinned to any element -
        // they live in the URL slug, or are split across inline tags. Reported
        // under the page body rather than guessed into a region they were not
        // found in, for the same reason locate() says "Lokasi tidak dikenali".
        $regions->addKeywords($url, $located['unlocated']);
    }

    /**
     * Which element carries each matched keyword, grouped by the region around it.
     *
     * @param  array<int, string>  $keywords
     * @return array{regions: array<int, array{region: array{key: string, label: string, selector: ?string}, keywords: array<int, string>, evidence: array<int, array{path: ?string, html: ?string}>}>, unlocated: array<int, string>}
     */
    protected function locateKeywords(string $html, array $keywords): array
    {
        $dom = $this->parseHtml($html);

        if (! $dom) {
            return ['regions' => [], 'unlocated' => $keywords];
        }

        $pageRegion = app(PageRegion::class);

        // Keyed by the lowercased phrase so a hit can be struck off in O(1) - the
        // list runs to a few hundred entries and the page to a few thousand nodes.
        $pending = [];

        foreach ($keywords as $keyword) {
            $pending[Str::lower($keyword)] = $keyword;
        }

        $groups = [];

        foreach ($this->textCarryingElements($dom) as $candidate) {
            if ($pending === []) {
                break;
            }

            // Same folding the page-level match uses, so obfuscated phrases are
            // located rather than falling through to the fallback region.
            $hits = [];

            foreach ($this->matcher()->matchText($candidate['text']) as $match) {
                $lower = Str::lower($match);

                if (isset($pending[$lower])) {
                    $hits[] = $pending[$lower];
                    unset($pending[$lower]);
                }
            }

            if ($hits === []) {
                continue;
            }

            $region = $candidate['region'] ?? $pageRegion->locate($candidate['node']);

            // Text sitting straight in <body> with no distinguishing container has
            // a real answer - it is the page body - so saying "Lokasi tidak
            // dikenali" here would be less true, not more careful. The node is
            // still pinned exactly, by the dom_path recorded below. A LINK keeps
            // reporting 'unknown', because there the container is the fix.
            if ($region['key'] === 'unknown') {
                $region = RegionFindings::FALLBACK_REGION;
            }

            $key = $region['key'];

            $groups[$key] ??= [
                'region' => $region,
                'keywords' => [],
                'evidence' => [],
            ];

            $groups[$key]['keywords'] = array_merge($groups[$key]['keywords'], $hits);

            // One entry per element that carried a phrase, not just the first in the
            // region: spam dropped into a widget usually lands in several sibling
            // nodes, and each is its own place to go and delete.
            if (count($groups[$key]['evidence']) < RegionFindings::MAX_STORED_NODES) {
                $groups[$key]['evidence'][] = [
                    'path' => $pageRegion->path($candidate['node']),
                    'html' => $pageRegion->snippet($candidate['node']),
                    'hidden' => $candidate['region'] === null && $this->extractor()->isHidden($candidate['node']),
                ];
            }
        }

        return ['regions' => array_values($groups), 'unlocated' => array_values($pending)];
    }

    /**
     * Every node that could carry a keyword, paired with the text it carries.
     *
     * Meta tags come first because SEO spam is stuffed into the description far
     * more often than into visible copy, and a match there has no visible element
     * to point at. Then elements, each with only its OWN text nodes: a parent's
     * textContent contains every descendant's copy as well, so matching on that
     * would pin every phrase to <html> and report the region as "the whole page".
     *
     * @return Generator<int, array{node: \DOMElement, text: string, region: array{key: string, label: string, selector: ?string}|null}>
     */
    protected function textCarryingElements(DOMDocument $dom): Generator
    {
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            $content = trim($meta->getAttribute('content'));

            if ($content === '') {
                continue;
            }

            $name = trim($meta->getAttribute('name')) ?: trim($meta->getAttribute('property'));

            yield [
                'node' => $meta,
                'text' => $content,
                // <meta> sits in <head>, which no landmark or class hint describes,
                // so locate() would call it "Lokasi tidak dikenali". Naming it is
                // both accurate and the more useful instruction: the fix is in the
                // SEO plugin or the template head, not in the page copy.
                'region' => [
                    'key' => 'meta',
                    'label' => 'Meta tag halaman',
                    'selector' => $name !== '' ? "meta[{$name}]" : 'meta',
                ],
            ];
        }

        foreach ($dom->getElementsByTagName('*') as $element) {
            if (in_array(Str::lower($element->nodeName), ['script', 'style', 'noscript'], true)) {
                continue;
            }

            $own = '';

            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMText) {
                    $own .= ' '.$child->data;
                }
            }

            $own = Str::squish($own);

            if ($own !== '') {
                yield ['node' => $element, 'text' => $own, 'region' => null];
            }
        }
    }

    /**
     * Every <img> on the page, grouped by the region it sits in.
     *
     * Deliberately not filtered by looksLikeSupportedImage(): that allow-list exists
     * because the Messages API rejects a whole request over one unreadable image,
     * which has nothing to do with showing a banner to an operator. What narrows
     * this down is the region - only images from a region that was actually flagged
     * are ever attached to a finding.
     *
     * @return array<string, array<int, string>> region key => absolute image URLs
     */
    protected function extractLocatedImages(string $html, string $baseUrl): array
    {
        $dom = $this->parseHtml($html);

        if (! $dom) {
            return [];
        }

        $pageRegion = app(PageRegion::class);
        $byRegion = [];

        foreach ($dom->getElementsByTagName('img') as $img) {
            $src = trim($img->getAttribute('src'));

            // Inline data: URIs aren't fetchable by URL, and "#"/anchor-only sources
            // resolve to the page itself.
            if ($src === '' || str_starts_with($src, 'data:') || str_starts_with($src, '#')) {
                continue;
            }

            $absolute = $this->resolveUrl($src, $baseUrl);

            if (! $absolute) {
                continue;
            }

            $key = $pageRegion->locate($img)['key'];
            $byRegion[$key] ??= [];

            if (count($byRegion[$key]) < RegionFindings::MAX_STORED_IMAGES && ! in_array($absolute, $byRegion[$key], true)) {
                $byRegion[$key][] = $absolute;
            }
        }

        return $byRegion;
    }

    /**
     * Which of these domains are gambling sites, as judged by the link classifier.
     *
     * @param  array<int, string>  $domains
     * @param  array<string, string>  $anchors  domain => anchor text
     * @return array<int, string>
     */
    protected function flagGamblingDomains(array $domains, array $anchors): array
    {
        $settings = app(JudolAiSettings::class);

        if (! $settings->usable() || ! config('judol.ai.links.enabled', true) || $domains === [] || ! $this->aiWithinBudget()) {
            return [];
        }

        $links = [];

        foreach ($domains as $domain) {
            $links[$domain] = $anchors[$domain] ?? '';
        }

        return $this->timedAi(fn () => app(JudolLinkClassifier::class)->gamblingDomains($links));
    }

    protected function resetAiBudget(): void
    {
        $this->aiSeconds = 0.0;
        $this->aiBudgetLogged = false;
    }

    /**
     * Once config('judol.ai.budget_seconds') is spent, the rest of the scan runs
     * keyword/heuristics-only - as if AI were off - so a slow API can't eat the job's timeout.
     */
    protected function aiWithinBudget(): bool
    {
        $budget = (float) config('judol.ai.budget_seconds', 120);

        if ($this->aiSeconds < $budget) {
            return true;
        }

        if (! $this->aiBudgetLogged) {
            $this->aiBudgetLogged = true;
            Log::warning(sprintf('JudolScanner: anggaran waktu AI per scan habis (%.1f dari %.0f detik); sisa halaman dinilai tanpa AI.', $this->aiSeconds, $budget));
        }

        return false;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    protected function timedAi(callable $call): mixed
    {
        $start = hrtime(true);

        try {
            return $call();
        } finally {
            $this->aiSeconds += (hrtime(true) - $start) / 1e9;
        }
    }

    /**
     * Force-check one specific URL right now (e.g. "Cek URL Tertentu" in the UI),
     * regardless of whether it was already scanned before.
     *
     * @return array<int, string> matched keywords, empty when the page is clean
     */
    public function scanUrlNow(Website $website, string $url): array
    {
        $this->resetAiBudget();
        $result = $this->fetchWithError($url);

        if ($result['body'] === null) {
            throw new RuntimeException("Tidak dapat mengambil konten dari {$url}: {$result['error']}. Pastikan URL benar dan dapat diakses.");
        }

        // Deliberately does NOT notify: this runs because somebody clicked "Cek URL
        // Tertentu" and is already looking at the verdict on screen. Only the
        // automatic crawl (scan()) mails/notifies, so re-checking the same page ten
        // times in a row can't turn into ten emails.
        $detection = $this->evaluateAndStore($website, $url, $result['body'], force: true, lastModified: $result['last_modified'], pageText: $this->extractor()->extract($result['body'], $url, ! $result['truncated']));

        // The manual check has to leave the same trail the crawl does. Without this
        // it wrote a per-page detection and no finding at all - so the page showed
        // as dirty on the site's own screen while Deteksi Judol, which lists
        // findings, stayed empty for that site and the operator had nothing to act on.
        //
        // Persisted as partial: this looked at one page, so it may only ever add to
        // what a full crawl established - see RegionFindings::persist().
        $regions = new RegionFindings;
        $this->recordRegions($website, $regions, $url, $result['body']);
        $this->recordKeywordRegions($regions, $url, $result['body'], $detection?->matched_keywords ?? []);

        if (! $regions->isEmpty()) {
            $regions->persist($website, partial: true);
        }

        return $detection?->matched_keywords ?? [];
    }

    /**
     * Breadth-first crawl starting from the homepage and sitemap(s), following
     * same-host links up to config('judol.max_depth') / config('judol.max_pages').
     *
     * @return Generator<string, array{html: string, last_modified: ?Carbon, truncated: bool}>
     */
    protected function crawl(Website $website): Generator
    {
        $maxPages = max(1, (int) config('judol.max_pages', 60));
        $maxDepth = max(0, (int) config('judol.max_depth', 3));
        $host = parse_url($website->url, PHP_URL_HOST);

        // A page budget alone does not bound a crawl's *duration*, and duration is
        // what the queue enforces: on a slow host 60 pages runs well past the job's
        // 300s timeout, which queue:work applies by SIGKILLing the worker mid-fetch.
        // The worker dies, systemd restarts it, it picks the same site up again and
        // dies the same way - the status=9/KILL restart loop. Stopping on our own
        // terms keeps whatever was already scanned and hands the rest to the next
        // scheduled run.
        $deadline = microtime(true) + max(30, (int) config('judol.max_seconds', 240));

        $visited = [];
        $this->crawlOutcome = ['complete' => false, 'gone' => [], 'attempted' => []];

        // Probe the homepage before anything else, including the sitemap fetch.
        // If the site does not answer here it will not answer for any of the
        // max_pages URLs below either, and each of those failures costs a full
        // connect timeout x retries - so a dead host would otherwise spend the
        // job's entire timeout budget confirming the same thing over and over.
        // This is the last line of defence for callers that bypass the
        // last_status gate in ScanJudol / ScanWebsiteForJudolJob - notably the
        // manual "Cek Judol" button, which scans on demand.
        $homepage = $this->normalizeUrl($website->url);
        $visited[$homepage] = true;
        $this->crawlOutcome['attempted'][sha1($homepage)] = true;
        $root = $this->fetchWithError($homepage);

        // A homepage that fails does NOT always mean a dead site. A WAF answering
        // 403 to the crawler - while every article underneath it still serves
        // fine - is the common shape of exactly the site this scanner exists for:
        // compromised, and now shielding its front door. Returning here left those
        // sites with per-page detections from manual checks (which fetch one URL
        // directly) but no findings at all, because findings are only ever built
        // from a crawl. So a responding server keeps going by way of the sitemap
        // and the pages already on record; only a server that does not answer at
        // all is still treated as nothing to crawl.
        if ($root['body'] === null) {
            if (! $this->accessRefused($root['error'])) {
                Log::warning("JudolScanner: menghentikan crawl {$website->url} - halaman utama tidak dapat diambil: {$root['error']}");

                return;
            }

            Log::info("JudolScanner: halaman utama {$website->url} ditolak ({$root['error']}), melanjutkan lewat sitemap dan URL yang sudah terdeteksi.");
        } else {
            yield $homepage => ['html' => $root['body'], 'last_modified' => $root['last_modified'], 'truncated' => $root['truncated']];
        }

        $queue = [];

        if ($maxDepth > 0 && $root['body'] !== null) {
            foreach ($this->extractLinks($root['body'], $homepage, $host) as $link) {
                if (! isset($visited[$link])) {
                    $queue[] = [$link, 1];
                }
            }
        }

        foreach ($this->sitemapUrls($website) as $sitemapUrl) {
            $queue[] = [$sitemapUrl, 0];
        }

        // URLs this site was already flagged on, as a seed of last resort. When the
        // homepage is blocked and the sitemap is missing or blocked too, these are
        // the only addresses known to exist - and they are the ones that matter,
        // since each is a page that previously matched.
        foreach ($website->judolDetections()->pluck('url') as $knownUrl) {
            $queue[] = [$knownUrl, 0];
        }

        while ($queue && count($visited) < $maxPages) {
            if (microtime(true) >= $deadline) {
                Log::info(sprintf(
                    'JudolScanner: crawl %s dihentikan pada batas waktu setelah %d halaman; sisanya dilanjutkan pada pemindaian berikutnya.',
                    $website->url,
                    count($visited)
                ));

                return;
            }

            [$url, $depth] = array_shift($queue);
            $url = $this->normalizeUrl($url);

            if (isset($visited[$url]) || ! $this->isCrawlable($url, $host)) {
                continue;
            }

            $visited[$url] = true;
            $this->crawlOutcome['attempted'][sha1($url)] = true;
            $result = $this->fetchWithError($url);
            $html = $result['body'];

            if ($html === null) {
                if (in_array($result['error'], ['HTTP 404', 'HTTP 410'], true)) {
                    $this->crawlOutcome['gone'][$url] = (int) substr($result['error'], 5);
                }

                continue;
            }

            yield $url => ['html' => $html, 'last_modified' => $result['last_modified'], 'truncated' => $result['truncated']];

            if ($depth < $maxDepth && count($visited) < $maxPages) {
                foreach ($this->extractLinks($html, $url, $host) as $link) {
                    if (! isset($visited[$link])) {
                        $queue[] = [$link, $depth + 1];
                    }
                }
            }
        }

        // Drained, not cut short - only then can an unvisited page count as missed.
        $this->crawlOutcome['complete'] = $queue === [] && $root['body'] !== null;
    }

    /**
     * Reads sitemap.xml (following one level of sitemap-index nesting) into a flat URL list.
     *
     * @return array<int, string>
     */
    protected function sitemapUrls(Website $website, ?string $sitemapUrl = null, int $depth = 0): array
    {
        if ($depth > 1) {
            return [];
        }

        $sitemapUrl ??= rtrim($website->url, '/').'/sitemap.xml';
        $maxSitemapUrls = (int) config('judol.max_sitemap_urls', 50);

        try {
            $response = $this->httpClient()->get($sitemapUrl);

            if (! $response->successful()) {
                return [];
            }

            $xml = @simplexml_load_string($response->body());

            if ($xml === false) {
                return [];
            }

            // Sitemap index: recurse into the child sitemaps it references.
            if (isset($xml->sitemap)) {
                $urls = [];

                foreach ($xml->sitemap as $entry) {
                    if (count($urls) >= $maxSitemapUrls) {
                        break;
                    }

                    $urls = array_merge($urls, $this->sitemapUrls($website, (string) $entry->loc, $depth + 1));
                }

                return $urls;
            }

            $urls = [];

            foreach ($xml->url as $entry) {
                if (count($urls) >= $maxSitemapUrls) {
                    break;
                }

                if ($loc = (string) $entry->loc) {
                    $urls[] = $loc;
                }
            }

            return $urls;
        } catch (Throwable) {
            return [];
        }
    }

    protected function fetch(string $url): ?string
    {
        return $this->fetchWithError($url)['body'];
    }

    /**
     * Fetches a URL, returning either the body or a human-readable failure reason
     * (never both). A handful of hosts also present a certificate chain curl
     * doesn't trust yet (e.g. a newer intermediate CA) even though real browsers
     * accept it fine - that gets one retry without verification, clearly logged.
     *
     * `last_modified` carries the page's own Last-Modified header when the server
     * sends one - see JudolDetection::page_last_modified_at for why that matters.
     *
     * `truncated` is true when the body was cut at the response size limit.
     *
     * @return array{body: ?string, error: ?string, last_modified: ?Carbon, truncated: bool}
     */
    protected function fetchWithError(string $url): array
    {
        // Crawled links come from the site's own markup, which on a hacked site is
        // attacker-controlled - so every address is re-checked, not just the seed.
        if ($reason = OutboundUrlGuard::reason($url)) {
            Log::warning("JudolScanner: {$url} ditolak: {$reason}");

            return ['body' => null, 'error' => $reason, 'last_modified' => null, 'truncated' => false];
        }

        try {
            $response = $this->httpClient()->get($url);
        } catch (Throwable $e) {
            if (str_starts_with($url, 'https://') && Str::contains(Str::lower($e->getMessage()), ['ssl', 'certificate'])) {
                try {
                    $response = $this->httpClient(verifySsl: false)->get($url);
                    Log::warning("JudolScanner: {$url} diambil tanpa verifikasi SSL (curl tidak memercayai sertifikatnya, padahal browser biasa menerimanya).");
                } catch (Throwable $retryException) {
                    Log::warning("JudolScanner: gagal mengambil {$url}: {$retryException->getMessage()}");

                    return ['body' => null, 'error' => $retryException->getMessage(), 'last_modified' => null, 'truncated' => false];
                }
            } else {
                Log::warning("JudolScanner: gagal mengambil {$url}: {$e->getMessage()}");

                return ['body' => null, 'error' => $e->getMessage(), 'last_modified' => null, 'truncated' => false];
            }
        }

        if (! $response->successful()) {
            $reason = "HTTP {$response->status()}";
            Log::warning("JudolScanner: gagal mengambil {$url} ({$reason})");

            return ['body' => null, 'error' => $reason, 'last_modified' => null, 'truncated' => false];
        }

        $body = $response->toPsrResponse()->getBody();

        return [
            'body' => $response->body(),
            'error' => null,
            'last_modified' => $this->parseLastModified($response->header('Last-Modified')),
            'truncated' => $body instanceof ResponseSizeLimit && $body->truncated,
        ];
    }

    /**
     * HTTP statuses that mean "this request was refused", not "this site is broken".
     *
     * A WAF shielding the homepage answers one of these while every article behind
     * it still serves normally - which is exactly the site this scanner exists for.
     * 5xx is deliberately absent: a server erroring on its own homepage is failing,
     * and the rest of its pages will fail the same way, so crawling on would burn
     * the job's whole timeout budget to learn nothing.
     */
    public const ACCESS_REFUSED_STATUSES = [401, 403, 405, 406, 409, 418, 429];

    /**
     * Whether a failed homepage fetch is a locked door rather than a dead site.
     *
     * fetchWithError() reports an HTTP status ("HTTP 403") when the server answered
     * and refused, and a transport message (timeout, DNS failure, connection
     * refused) when nothing answered at all. Only a refusal is worth crawling past:
     * it says the host is up and serving, it just does not like this particular
     * request.
     */
    protected function accessRefused(?string $error): bool
    {
        if ($error === null || ! preg_match('/^HTTP (\d{3})$/', $error, $m)) {
            return false;
        }

        return in_array((int) $m[1], self::ACCESS_REFUSED_STATUSES, true);
    }

    /**
     * Parses a Last-Modified response header into a Carbon date. Servers send it in
     * RFC 7231 format (GMT), but plenty send something slightly off (or nothing at
     * all) - an unparseable value is simply treated as "unknown" rather than blowing
     * up a scan. Converted to the app timezone before storing, since Eloquent writes
     * the wall-clock as-is and reads it back as app-timezone.
     */
    protected function parseLastModified(?string $header): ?Carbon
    {
        if (blank($header)) {
            return null;
        }

        try {
            return Carbon::parse($header)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A browser-like client: realistic UA + headers (avoids WAF bot-blocking),
     * generous timeout, and a couple of retries with backoff (handles transient
     * blocks / rate limits without giving up on the first hiccup).
     */
    protected function httpClient(bool $verifySsl = true): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => self::BROWSER_USER_AGENT,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
        ])
            ->withOptions(['verify' => $verifySsl] + OutboundUrlGuard::httpOptions())
            // Every hop and retry connects only to the guard-vetted IPs (no DNS rebinding).
            ->setHandler(ResponseSizeLimit::handler(OutboundUrlGuard::pinnedHandler()))
            ->timeout(15)
            // 10s, matching WebsiteChecker: the same shared-hosting sites are being
            // fetched, and at 5s their TLS handshake was being cut off. Here that
            // showed up as a crawl that stopped at the homepage (see crawl()) on a
            // site the dashboard was reporting as up.
            //
            // The per-URL cost this multiplies out to is bounded by the last_status
            // gate rather than by a short timeout: an unreachable site is never
            // crawled in the first place, so this budget is only ever spent on
            // hosts that answer.
            ->connectTimeout(10)
            ->retry(2, 400, throw: false);
    }

    /**
     * Parses an HTML string into a DOMDocument, tolerating malformed markup.
     */
    protected function parseHtml(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        return $dom;
    }

    /**
     * Extracts same-host, crawlable links from an HTML page's <a href> tags.
     *
     * @return array<int, string>
     */
    protected function extractLinks(string $html, string $baseUrl, ?string $host): array
    {
        $dom = $this->parseHtml($html);

        if (! $dom) {
            return [];
        }

        $links = [];

        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = trim($anchor->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#') || Str::startsWith($href, ['mailto:', 'tel:', 'javascript:'])) {
                continue;
            }

            $absolute = $this->resolveUrl($href, $baseUrl);

            if ($absolute && $this->isCrawlable($absolute, $host)) {
                $links[] = $this->normalizeUrl($absolute);
            }
        }

        return array_values(array_unique($links));
    }

    /**
     * The distinct external domains a page links out to, for JudolLinkClassifier
     * to judge.
     *
     * The counterpart to extractLinks(), which keeps only same-host URLs to crawl:
     * here we want exactly the ones it throws away. An injected backlink leaves the
     * visible page untouched and only adds an <a href> pointing somewhere else, so
     * the destination host is the entire evidence.
     *
     * Reduced to registrable domains rather than full URLs: an attacker who plants
     * forty deep links into one gambling site should cost one verdict, not forty,
     * and the cached verdict then covers every page that links there.
     *
     * Each domain is paired with its anchor text, because the domain alone is
     * often not enough. A real example from this app's own scan list: a hacked
     * ministry page linked to "wicc2024.com" - a name that reads like a
     * conference - under the anchor text "Live Draw Macau". The destination gives
     * nothing away; what the page invites you to click does.
     *
     * @return array<string, string> registrable domain => anchor text
     */
    protected function extractOutboundLinks(string $html, string $baseUrl, ?string $host): array
    {
        $dom = $this->parseHtml($html);

        if (! $dom) {
            return [];
        }

        $ignored = array_map(Str::lower(...), (array) config('judol.ignored_link_hosts', []));
        $limit = max(1, (int) config('judol.ai.links.max_domains', 40));
        $links = [];

        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = trim($anchor->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#') || Str::startsWith($href, ['mailto:', 'tel:', 'javascript:'])) {
                continue;
            }

            $absolute = $this->resolveUrl($href, $baseUrl);
            $linkHost = $absolute ? Str::lower((string) parse_url($absolute, PHP_URL_HOST)) : '';

            // Same-host links are the site's own pages - they are crawled instead,
            // and each is judged on its own content.
            if ($linkHost === '' || ($host && strcasecmp($linkHost, $host) === 0)) {
                continue;
            }

            $domain = $this->registrableDomain($linkHost);

            if (in_array($domain, $ignored, true)) {
                continue;
            }

            $text = $this->anchorText($anchor);

            // Several links to the same site: keep the first anchor text that says
            // something, since an injected block usually repeats one pitch and the
            // empty ones are image or "read more" links.
            if (isset($links[$domain])) {
                $links[$domain] = $links[$domain] ?: $text;

                continue;
            }

            if (count($links) >= $limit) {
                break;
            }

            $links[$domain] = $text;
        }

        return $links;
    }

    /**
     * The same outbound links, but each one paired with WHERE on the page it sits.
     *
     * extractOutboundLinks() answers "which domains", which is what the classifier
     * needs. This answers "which domains, from which region" - the extra axis that
     * lets a sitewide injection be reported once instead of once per URL, because
     * the region signature is the part that stays the same across pages.
     *
     * @return array<int, array{domain: string, text: string, region: array{key: string, label: string, selector: ?string}, node: \DOMElement}>
     */
    protected function extractLocatedOutboundLinks(string $html, string $baseUrl, ?string $host): array
    {
        $dom = $this->parseHtml($html);

        if (! $dom) {
            return [];
        }

        $ignored = array_map(Str::lower(...), (array) config('judol.ignored_link_hosts', []));
        $regions = app(PageRegion::class);
        $located = [];
        $seen = [];

        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = trim($anchor->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#') || Str::startsWith($href, ['mailto:', 'tel:', 'javascript:'])) {
                continue;
            }

            $absolute = $this->resolveUrl($href, $baseUrl);
            $linkHost = $absolute ? Str::lower((string) parse_url($absolute, PHP_URL_HOST)) : '';

            if ($linkHost === '' || ($host && strcasecmp($linkHost, $host) === 0)) {
                continue;
            }

            $domain = $this->registrableDomain($linkHost);

            if (in_array($domain, $ignored, true)) {
                continue;
            }

            $region = $regions->locate($anchor);

            // One entry per domain per region: an injected block repeats the same
            // link a dozen times, and that is one fact, not a dozen.
            $signature = $region['key'].'|'.$domain;

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $located[] = [
                'domain' => $domain,
                'text' => $this->anchorText($anchor),
                'region' => $region,
                // Kept so the caller can ask PageRegion where exactly this sits.
                // Valid only while $dom is alive, which it is for this call's
                // lifetime - nothing here outlives recordRegions().
                'node' => $anchor,
            ];
        }

        return $located;
    }

    /** The words a visitor would click on, falling back to what a screen reader would read. */
    protected function anchorText(\DOMElement $anchor): string
    {
        $text = Str::squish($anchor->textContent);

        if ($text === '') {
            $text = trim($anchor->getAttribute('title'));
        }

        if ($text === '') {
            foreach ($anchor->getElementsByTagName('img') as $img) {
                $text = trim($img->getAttribute('alt'));

                break;
            }
        }

        return Str::limit($text, 80, '');
    }

    /**
     * A host reduced to the domain someone actually registered, so "www.x.com",
     * "cdn.x.com" and "x.com" are one thing to judge and one thing to cache.
     *
     * Uses an explicit list of two-label suffixes rather than the full public
     * suffix list: the app's traffic is Indonesian government and news sites, so
     * .go.id / .co.id / .ac.id and a handful of foreign equivalents cover it, and
     * guessing from label length instead would mangle a legitimate three-letter
     * domain like bbc.com. Getting one wrong only costs a duplicate verdict for a
     * subdomain, never a missed detection.
     */
    protected function registrableDomain(string $host): string
    {
        $labels = explode('.', trim($host, '.'));

        if (count($labels) <= 2) {
            return $host;
        }

        $suffix = $labels[count($labels) - 2].'.'.$labels[count($labels) - 1];
        $take = in_array($suffix, self::TWO_LABEL_SUFFIXES, true) ? 3 : 2;

        return implode('.', array_slice($labels, -min($take, count($labels))));
    }

    /**
     * Extracts up to JudolAiSettings::maxImages() absolute <img src> URLs from a
     * page, for the AI classifier to read (promo banners often carry their pitch
     * as an image, not HTML text). Inline data: URIs are skipped - not fetchable
     * by URL - and icons/SVGs are unlikely to be spam banners.
     *
     * @return array<int, string>
     */
    protected function extractImages(string $html, string $baseUrl): array
    {
        $dom = $this->parseHtml($html);

        if (! $dom) {
            return [];
        }

        $limit = app(JudolAiSettings::class)->maxImages();
        $images = [];

        foreach ($dom->getElementsByTagName('img') as $img) {
            if (count($images) >= $limit) {
                break;
            }

            $src = trim($img->getAttribute('src'));

            // Inline data: URIs aren't fetchable by URL, and "#"/anchor-only sources
            // resolve to the page itself.
            if ($src === '' || $src === '#' || str_starts_with($src, 'data:') || str_starts_with($src, '#')) {
                continue;
            }

            $absolute = $this->resolveUrl($src, $baseUrl);

            // Allow-list, not a deny-list: Claude rejects the WHOLE request if any one
            // image isn't a supported format, so a single lazy-loading placeholder or
            // extension-less tracking pixel would take the entire classification down
            // with it. Better to skip a legitimate image than lose the verdict.
            if ($absolute && $this->looksLikeSupportedImage($absolute)) {
                $images[] = $absolute;
            }
        }

        return array_values(array_unique($images));
    }

    /**
     * Whether a URL's path ends in an image format the Messages API accepts. The
     * query string is ignored, so CDN URLs like "logo.png?updatedAt=..." still pass.
     */
    protected function looksLikeSupportedImage(string $url): bool
    {
        $extension = Str::lower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return in_array($extension, self::SUPPORTED_IMAGE_EXTENSIONS, true);
    }

    protected function resolveUrl(string $href, string $baseUrl): ?string
    {
        if (Str::startsWith($href, ['http://', 'https://'])) {
            return $href;
        }

        $base = parse_url($baseUrl);

        if (! $base || ! isset($base['host'])) {
            return null;
        }

        $scheme = $base['scheme'] ?? 'https';
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        if (str_starts_with($href, '//')) {
            return $scheme.':'.$href;
        }

        if (str_starts_with($href, '/')) {
            return "{$scheme}://{$base['host']}{$port}{$href}";
        }

        // Relative path: resolve against the base URL's own directory.
        $basePath = $base['path'] ?? '/';
        $basePath = substr($basePath, 0, strrpos($basePath, '/') + 1) ?: '/';

        return "{$scheme}://{$base['host']}{$port}".$basePath.$href;
    }

    protected function normalizeUrl(string $url): string
    {
        // Drop fragments; query strings are kept since some sites route pages through them.
        return (string) Str::of($url)->before('#');
    }

    protected function isCrawlable(string $url, ?string $host): bool
    {
        $parsed = parse_url($url);

        if (! $parsed || ! isset($parsed['host']) || ! $host || strcasecmp($parsed['host'], $host) !== 0) {
            return false;
        }

        $extension = Str::lower(pathinfo($parsed['path'] ?? '', PATHINFO_EXTENSION));

        return $extension === '' || ! in_array($extension, self::SKIPPED_EXTENSIONS, true);
    }

    /**
     * Matches a page's text against the judol keyword list and, if it clears the
     * threshold, records/refreshes the detection + incident.
     *
     * @return JudolDetection|null the stored detection, or null when nothing (new) matched
     */
    protected function evaluateAndStore(Website $website, string $url, string $html, bool $force = false, ?Carbon $lastModified = null, ?PageText $pageText = null): ?JudolDetection
    {
        $existing = $website->judolDetections()->where('url', $url)->first();

        // Automatic crawl only cares about pages it hasn't already flagged; a manual
        // "cek URL tertentu" always re-evaluates, even for a URL checked before.
        if ($existing && ! $force) {
            return null;
        }

        // Judged before the page text is read, because the answer changes what counts
        // as page text. Runs whether or not a keyword matched - that is the entire
        // point. Backlink injection leaves the visible page exactly as it was and only
        // adds hidden <a href> tags to a gambling site, so on this page the keyword
        // filter has nothing to find and the destination host is the only evidence
        // there is.
        $linkedDomains = $this->gamblingLinkedDomains($html, $url, parse_url($website->url, PHP_URL_HOST));

        // The anchor text of a link that was just flagged is left out. "Slot Gacor"
        // sitting inside <a href="gacor88.com"> IS the backlink finding, already
        // reported with the region it lives in and where it points; reading it a
        // second time as page text files a separate "teks promosi disisipkan"
        // finding for one single edit. Only ever excludes what the link classifier
        // actually flagged, so with the AI off nothing is dropped.
        $pageText ??= $this->extractor()->extract($html, $url);
        $text = $pageText->plainText($linkedDomains);

        // Folded, whole-word, weighted match over every segment (title, meta, body,
        // alt/title attributes, noscript, URL slug) - see JudolMatcher.
        $match = $this->matcher()->match($pageText, $linkedDomains);
        $matched = collect($match['keywords']);
        $keywordsMatched = $match['keywords'] !== [] && $match['score'] >= app(AppSettings::class)->judolScoreThreshold();

        if (! $keywordsMatched && $linkedDomains === []) {
            // A page that could not be fully read proves nothing - keep what is on record.
            if (! $pageText->complete) {
                Log::warning("JudolScanner: ekstraksi teks {$url} tidak lengkap, deteksi yang ada dipertahankan");

                return null;
            }

            $this->clearStaleDetection($existing, $url, 'kata kunci tidak lagi cocok dan tidak ada tautan ke situs judi');

            return null;
        }

        // Second opinion from Claude, on top of the free keyword filter: confirms the
        // match from the page text AND any promo images, or denies it as a false
        // positive (e.g. a news article that merely mentions "judi online"). Only an
        // EXPLICIT, confident denial (is_judol === false) suppresses the detection -
        // if the AI call fails or its answer is unparseable, is_judol is null ("unknown"),
        // and we keep trusting the keyword match rather than silently dropping a real hit.
        $aiSettings = app(JudolAiSettings::class);
        // Null when the classifier is switched off, so the detection records that it
        // was never reviewed rather than pretending to a verdict.
        $ai = null;

        // The page classifier only ever vets a KEYWORD match, so it is asked only
        // when there is one. On a link-only finding its answer would be worse than
        // useless: the visible article really is a clean article, so it would deny
        // the finding and delete a genuine backlink injection.
        if ($aiSettings->usable() && $keywordsMatched && $this->aiWithinBudget()) {
            $verdict = $this->timedAi(fn () => app(JudolAiClassifier::class)->classify(
                $url,
                $text,
                $matched->all(),
                $this->extractImages($html, $url),
            ));

            $aiConfidentlyDenies = $verdict['is_judol'] === false
                && $verdict['confidence'] >= $aiSettings->confidenceThreshold();

            // A denial of the page's TEXT says nothing about where the page links to,
            // and the two checks read different evidence - so a confident "this is
            // just a news article" cannot clear a page that is also pointing at a
            // gambling domain.
            if ($aiConfidentlyDenies && $linkedDomains === [] && $pageText->complete) {
                Log::info("JudolScanner: {$url} dibantah AI (confidence {$verdict['confidence']}): {$verdict['reasoning']}");
                $this->clearStaleDetection($existing, $url, 'dibantah AI');

                return null;
            }

            $ai = [
                'ai_verdict' => match ($verdict['is_judol']) {
                    true => 'confirmed',
                    // Denied, but not confidently enough to drop the finding.
                    false => 'disputed',
                    null => 'unavailable',
                },
                'ai_confidence' => $verdict['confidence'],
                'ai_reasoning' => $verdict['reasoning'],
                'ai_checked_at' => now(),
            ];
        }

        if ($linkedDomains !== []) {
            $ai = $this->withLinkFinding($ai, $linkedDomains);
        }

        $snippet = $this->snippet($pageText, $keywordsMatched ? $match : null, $text);
        $matchedKeywords = $keywordsMatched ? $matched->all() : [];
        $scoring = ['score' => $match['score'], 'match_details' => $keywordsMatched ? $match['details'] : null];
        $description = $this->describeFinding($url, $matchedKeywords, $linkedDomains);

        if ($existing) {
            $incident = $existing->incident ?: Incident::create([
                'website_id' => $website->id,
                'type' => 'judol',
                'severity' => 'critical',
                'title' => "Konten judi online terdeteksi di {$website->name}",
                'status' => 'open',
                'detected_at' => now(),
            ]);

            $incident->update([
                'description' => $description,
                'meta' => ['url' => $url, 'matched_keywords' => $matchedKeywords, 'linked_domains' => $linkedDomains],
                'status' => 'open',
                'resolved_at' => null,
                'detected_at' => now(),
            ]);

            $snapshot = $this->snapshotText($text);

            $existing->update([
                'incident_id' => $incident->id,
                'matched_keywords' => $matchedKeywords,
                ...$scoring,
                'linked_domains' => $linkedDomains,
                'snippet' => $snippet,
                'content_snapshot' => $snapshot,
                'content_hash' => sha1($snapshot),
                // Only overwritten when the page actually differs, so the record of
                // the last real change survives repeated identical re-checks.
                'content_changes' => $this->diffAgainstSnapshot($existing, $snapshot) ?? $existing->content_changes,
                ...($ai ?? []),
                'page_last_modified_at' => $lastModified ?? $existing->page_last_modified_at,
                'status' => 'new',
                'detected_at' => now(),
            ]);

            return $existing;
        }

        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'judol',
            'severity' => 'critical',
            'title' => "Konten judi online terdeteksi di {$website->name}",
            'description' => $description,
            'meta' => ['url' => $url, 'matched_keywords' => $matchedKeywords, 'linked_domains' => $linkedDomains],
            'status' => 'open',
            'detected_at' => now(),
        ]);

        $snapshot = $this->snapshotText($text);

        return JudolDetection::create([
            'website_id' => $website->id,
            'incident_id' => $incident->id,
            'url' => $url,
            'matched_keywords' => $matchedKeywords,
            ...$scoring,
            'linked_domains' => $linkedDomains,
            'snippet' => $snippet,
            'content_snapshot' => $snapshot,
            'content_hash' => sha1($snapshot),
            ...($ai ?? []),
            'page_last_modified_at' => $lastModified,
            'status' => 'new',
            'detected_at' => now(),
        ]);
    }

    /**
     * ~200 characters around the first keyword hit, taken from the segment it was
     * found in; a link-only finding opens from the top of the page text.
     *
     * @param  array{keywords: array<int, string>, first_segment: ?int}|null  $match
     */
    protected function snippet(PageText $pageText, ?array $match, string $text): string
    {
        $segment = $match && $match['first_segment'] !== null ? $pageText->segments[$match['first_segment']]['text'] : $text;
        $position = $match ? (mb_stripos($segment, $match['keywords'][0]) ?: 0) : 0;

        return trim(mb_substr($segment, max(0, $position - 60), 200));
    }

    /**
     * The gambling sites this page links out to, as judged by the AI.
     *
     * Empty whenever the check is switched off, no key is configured, or the AI
     * could not be reached - this check only ever ADDS a finding, so an outage
     * costs a detection rather than inventing one.
     *
     * @return array<int, string>
     */
    protected function gamblingLinkedDomains(string $html, string $url, ?string $host): array
    {
        $settings = app(JudolAiSettings::class);

        if (! $settings->usable() || ! config('judol.ai.links.enabled', true) || ! $this->aiWithinBudget()) {
            return [];
        }

        $links = $this->extractOutboundLinks($html, $url, $host);

        if ($links === []) {
            return [];
        }

        $flagged = $this->timedAi(fn () => app(JudolLinkClassifier::class)->gamblingDomains($links));

        if ($flagged !== []) {
            Log::info("JudolScanner: {$url} menaut ke situs judi: ".implode(', ', $flagged));
        }

        return $flagged;
    }

    /**
     * Folds the outbound-link finding into the AI columns.
     *
     * A link-only finding still came from the AI, so it must not be filed under
     * "Keyword saja" - but the link classifier answers yes/no, not a probability,
     * so ai_confidence stays null rather than being given an invented number.
     *
     * @param  array<string, mixed>|null  $ai  the page classifier's verdict, when it ran
     * @param  array<int, string>  $domains
     * @return array<string, mixed>
     */
    protected function withLinkFinding(?array $ai, array $domains): array
    {
        $summary = app(JudolLinkClassifier::class)->summarize($domains);

        if ($ai === null) {
            return [
                'ai_verdict' => 'confirmed',
                'ai_confidence' => null,
                'ai_reasoning' => $summary,
                'ai_checked_at' => now(),
            ];
        }

        // Both checks fired: the page's own content AND where it points. Keep the
        // page verdict's confidence and append what the links add to it. Joined by a
        // single newline, not a blank line: both sides are now findings lists, so
        // the link lines belong in the same list rather than in a second paragraph.
        return [
            ...$ai,
            'ai_verdict' => 'confirmed',
            'ai_reasoning' => trim($ai['ai_reasoning'] ?? '')."\n".$summary,
        ];
    }

    /**
     * What the incident says happened, phrased for whichever evidence was found -
     * keywords in the content, links pointing out to gambling sites, or both.
     *
     * @param  array<int, string>  $keywords
     * @param  array<int, string>  $domains
     */
    protected function describeFinding(string $url, array $keywords, array $domains): string
    {
        $parts = [];

        if ($keywords !== []) {
            $parts[] = 'mengandung kata kunci judi online: '.implode(', ', $keywords);
        }

        if ($domains !== []) {
            $parts[] = 'memasang tautan ke situs judi online: '.implode(', ', $domains);
        }

        return "Halaman {$url} ".implode(', dan ', $parts);
    }

    /**
     * The page's visible text, whitespace-normalized and capped, kept so the UI can
     * quote the injected passages back (JudolDetection::matchedExcerpts) and so the
     * next check has something to diff against.
     */
    protected function snapshotText(string $rawText): string
    {
        return Str::limit(Str::squish($rawText), self::SNAPSHOT_LIMIT, '');
    }

    /**
     * What this fetch added or removed versus the snapshot already on record.
     *
     * Deliberately a set difference over sentence-ish chunks rather than a
     * positional diff: injected spam is appended or swapped wholesale, so "which
     * passages are new" is the useful question, and it stays stable when unrelated
     * parts of a CMS page (timestamps, view counters) shuffle around.
     *
     * @return array{at: string, added: array<int, string>, removed: array<int, string>}|null
     *                                                                                        null when nothing is on record yet, or the page is unchanged
     */
    protected function diffAgainstSnapshot(JudolDetection $existing, string $snapshot): ?array
    {
        if (blank($existing->content_snapshot) || $existing->content_hash === sha1($snapshot)) {
            return null;
        }

        $before = $this->textChunks($existing->content_snapshot);
        $after = $this->textChunks($snapshot);

        $added = $after->diff($before)->take(self::MAX_DIFF_CHUNKS)->values();
        $removed = $before->diff($after)->take(self::MAX_DIFF_CHUNKS)->values();

        if ($added->isEmpty() && $removed->isEmpty()) {
            return null;
        }

        return [
            'at' => now()->toIso8601String(),
            'added' => $added->all(),
            'removed' => $removed->all(),
        ];
    }

    /**
     * Splits text into comparable chunks, dropping fragments too short to be worth
     * reporting as a change ("Beranda", "2026", ...).
     *
     * @return Collection<int, string>
     */
    protected function textChunks(string $text): Collection
    {
        return collect(preg_split('/(?<=[.!?])\s+|\s{2,}|\|/u', $text))
            ->map(fn (string $chunk) => trim($chunk))
            ->filter(fn (string $chunk) => mb_strlen($chunk) >= 25)
            ->unique()
            ->values();
    }

    /**
     * Sends the one batched notification for a scan's findings - nothing at all
     * when the scan came back clean, and nothing for a page whose content is byte
     * for byte what we already had on record. Re-flagging an unchanged page isn't
     * news, so it must never generate another email or dropdown entry; only a
     * brand-new detection or a page that genuinely changed since last time does.
     *
     * A newly recorded INJECTION SITE counts as news too, even when every page it
     * covers was already flagged. Those are two different discoveries: the pages
     * said "these are dirty", the finding says "and here is the one block they all
     * render". It also covers the case where findings could not be written at all
     * for a while - a site then has its whole injection reported for the first time
     * on the crawl that finally stores it, and staying silent there is how a real
     * discovery reaches nobody.
     *
     * @param  Collection<int, JudolDetection>  $found
     * @param  array<int, JudolFinding>  $newFindings  injection sites recorded for the first time
     */
    protected function notifyFindings(Website $website, Collection $found, array $newFindings = []): void
    {
        $found = $found->filter(fn (JudolDetection $detection) => $detection->wasRecentlyCreated
            || $detection->wasChanged('content_hash'))->values();

        if ($found->isEmpty() && $newFindings !== []) {
            $found = $this->detectionsBehind($website, $newFindings);
        }

        if ($found->isEmpty()) {
            return;
        }

        // Findings and detections are still written during a window - a page that
        // got injected while the site was being worked on is exactly the kind of
        // thing that must not be lost. Only the alert waits, and the page is
        // already on the Deteksi Judol list when the operator looks.
        if ($website->isUnderMaintenance()) {
            Log::info("JudolScanner: notifikasi untuk {$website->name} ditahan, situs sedang dalam jendela pemeliharaan.");

            return;
        }

        $admins = User::all();
        $notification = new JudolContentDetected($website, $found->values());

        app(MailSettings::class)->applyToRuntime();
        Notification::send($admins, $notification);

        app(WebhookNotifier::class)->sendNotification($notification, $admins->first());
    }

    /**
     * The stored detections for the pages a set of new findings covers.
     *
     * The notification speaks in detections - URLs, keywords, the incident to open -
     * so a finding-driven alert is expressed through the rows its pages already
     * have. Capped at the notification's own listing limit plus enough to make its
     * "dan N lainnya" honest, rather than loading a sitewide injection's every page.
     *
     * @param  array<int, JudolFinding>  $newFindings
     * @return Collection<int, JudolDetection>
     */
    protected function detectionsBehind(Website $website, array $newFindings): Collection
    {
        $urls = collect($newFindings)
            ->flatMap(fn (JudolFinding $finding) => $finding->affected_urls ?? [])
            ->unique()
            ->take(JudolFinding::MAX_STORED_URLS)
            ->values();

        if ($urls->isEmpty()) {
            return collect();
        }

        return $website->judolDetections()
            ->whereIn('url', $urls->all())
            ->orderBy('detected_at')
            ->get();
    }

    /**
     * Re-checks stored detections against the CURRENT keywords, weights and score
     * threshold - no network fetch. A keyword survives while it is still active;
     * with $recheckSnapshots (an exclusion was added) it must also still be found in
     * the stored page text after exclusions. The detection goes when the surviving
     * score drops below the threshold. Called at the start of each site's scan and
     * after the rules are edited on Deteksi Judol.
     *
     * @return int number of stale detections cleared
     */
    public function pruneStaleDetections(?Website $website = null, bool $recheckSnapshots = false): int
    {
        $matcher = $this->matcher();
        $threshold = app(AppSettings::class)->judolScoreThreshold();
        $cleared = 0;
        $this->pruned['detections'] = [];

        JudolDetection::whereNotNull('matched_keywords')
            ->when($website, fn ($q) => $q->where('website_id', $website->id))
            ->chunkById(50, function ($detections) use ($matcher, $threshold, $recheckSnapshots, &$cleared) {
                foreach ($detections as $detection) {
                    // A finding that rests on outbound gambling links has no keywords
                    // to go stale - editing the keyword list must not silently delete it.
                    if (filled($detection->linked_domains)) {
                        continue;
                    }

                    $stored = $detection->matched_keywords ?? [];
                    $surviving = array_values(array_filter($stored, fn (string $k) => $matcher->weightOf([$k]) > 0));

                    // A snapshot cut at SNAPSHOT_LIMIT may have lost the very phrase
                    // (footer spam) - absence there proves nothing.
                    // Legacy (pre-score) detections are rechecked once too, so the
                    // seeded exclusions reach them; the update below fills match_details.
                    if (($recheckSnapshots || $detection->match_details === null) && $surviving !== [] && filled($detection->content_snapshot)
                        && mb_strlen($detection->content_snapshot) < self::SNAPSHOT_LIMIT) {
                        // The slug was matched too, but is not part of the snapshot.
                        $slug = str_replace(['-', '_', '/'], ' ', urldecode((string) parse_url($detection->url, PHP_URL_PATH)));
                        $present = array_map(Str::lower(...), $matcher->matchText($detection->content_snapshot."\n".$slug));
                        $surviving = array_values(array_filter($surviving, fn (string $k) => in_array(Str::lower($k), $present, true)));
                    }

                    $score = $matcher->weightOf($surviving) + $this->storedBonus($detection->match_details ?? [], $surviving);

                    if ($surviving === [] || $score < $threshold) {
                        $this->clearStaleDetection($detection, $detection->url, 'kata kunci/bobot/pengecualian/ambang yang mendasarinya sudah berubah');
                        $this->pruned['detections'][] = $detection->id;
                        $cleared++;

                        continue;
                    }

                    if ($surviving !== $stored || $score !== $detection->score || $detection->match_details === null) {
                        $detection->update([
                            'matched_keywords' => $surviving,
                            'score' => $score,
                            'match_details' => $this->survivingDetails($detection->match_details, $surviving, $matcher),
                        ]);
                    }
                }
            });

        return $cleared;
    }

    /**
     * The same cleanup for injection sites: drop keywords that are inactive or are
     * themselves an exclusion phrase, and drop the finding when what is left weighs
     * less than the threshold and no backlink holds it up.
     *
     * @return int number of findings removed outright
     */
    public function pruneStaleFindings(?Website $website = null): int
    {
        $matcher = $this->matcher();
        $threshold = app(AppSettings::class)->judolScoreThreshold();
        $cleared = 0;
        $this->pruned['findings'] = [];

        JudolFinding::whereNotNull('matched_keywords')
            ->when($website, fn ($q) => $q->where('website_id', $website->id))
            ->chunkById(50, function ($findings) use ($matcher, $threshold, &$cleared) {
                foreach ($findings as $finding) {
                    $current = $finding->matched_keywords ?? [];

                    $surviving = array_values(array_filter(
                        $current,
                        fn (string $keyword) => $matcher->weightOf([$keyword]) > 0 && ! $matcher->isExcluded($keyword)
                    ));

                    // A backlink finding stands on where the page points, not on any
                    // phrase, so editing the keyword list must never delete one.
                    if (blank($finding->linked_domains) && ($surviving === [] || $matcher->weightOf($surviving) < $threshold)) {
                        Log::info("JudolScanner: menghapus titik penyisipan {$finding->region_label} - kata kunci yang tersisa tidak lagi memenuhi ambang skor.");
                        $finding->delete();
                        $this->pruned['findings'][] = $finding->id;
                        $cleared++;

                        continue;
                    }

                    if ($surviving !== $current) {
                        $finding->update(['matched_keywords' => $surviving]);
                    }
                }
            });

        return $cleared;
    }

    /**
     * One audit entry for what the last pruneStaleDetections()/pruneStaleFindings()
     * removed after a rule edit; nothing when nothing was removed.
     */
    public function auditPrune(string $trigger): void
    {
        $count = count($this->pruned['detections']) + count($this->pruned['findings']);

        if ($count === 0) {
            return;
        }

        AuditLog::record('deteksi-judol.dibersihkan', "{$count} temuan", [
            'pemicu' => $trigger,
            'deteksi' => array_slice($this->pruned['detections'], 0, 500),
            'titik_penyisipan' => array_slice($this->pruned['findings'], 0, 500),
        ]);
    }

    /**
     * Hidden/SEO bonus recorded at scan time, counted only for keywords still standing.
     *
     * @param  array<int, array{keyword?: string, sources?: array<int, string>, hidden?: bool, a11y?: bool}>  $details
     * @param  array<int, string>  $surviving
     */
    protected function storedBonus(array $details, array $surviving): int
    {
        $surviving = array_map(Str::lower(...), $surviving);
        $concealed = false;
        $seo = false;

        foreach ($details as $detail) {
            if (! in_array(Str::lower($detail['keyword'] ?? ''), $surviving, true)) {
                continue;
            }

            $concealed = $concealed || (($detail['hidden'] ?? false) && ! ($detail['a11y'] ?? false));
            $seo = $seo || array_intersect($detail['sources'] ?? [], ['title', 'meta', 'url']) !== [];
        }

        return ($concealed ? (int) config('judol.hidden_bonus', 1) : 0) + ($seo ? (int) config('judol.seo_bonus', 1) : 0);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $details
     * @param  array<int, string>  $surviving
     * @return array<int, array<string, mixed>>
     */
    protected function survivingDetails(?array $details, array $surviving, JudolMatcher $matcher): ?array
    {
        // Legacy rows: rebuilt from the keywords alone (no source/hidden known).
        $details ??= array_map(fn (string $k) => ['keyword' => $k, 'weight' => 0, 'sources' => [], 'hidden' => false, 'a11y' => false], $surviving);

        $surviving = array_map(Str::lower(...), $surviving);

        return collect($details)
            ->filter(fn (array $d) => in_array(Str::lower($d['keyword'] ?? ''), $surviving, true))
            ->map(fn (array $d) => ['weight' => $matcher->weightOf([$d['keyword']])] + $d)
            ->values()
            ->all();
    }

    /**
     * A forced re-check (force: true) that no longer matches is common after removing
     * a too-generic keyword (e.g. a bare "slot") - without this, the stale detection
     * would sit in the list forever, since the automatic crawl never re-evaluates a
     * URL it has already flagged. Resolves the linked incident (keeps the historical
     * record) and removes the now-invalid detection row.
     */
    protected function clearStaleDetection(?JudolDetection $existing, string $url, string $reason): void
    {
        if (! $existing) {
            return;
        }

        Log::info("JudolScanner: {$url} sudah bersih ({$reason}) - menghapus deteksi lama yang sudah tidak berlaku.");

        $existing->incident?->resolve();
        $existing->delete();
    }
}
