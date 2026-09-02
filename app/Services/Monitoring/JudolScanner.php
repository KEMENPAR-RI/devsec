<?php

namespace App\Services\Monitoring;

use App\Models\Incident;
use App\Models\JudolDetection;
use App\Models\JudolKeyword;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Notifications\JudolContentDetected;
use App\Services\Notifications\MailSettings;
use DOMDocument;
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
    protected const BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    /**
     * Common leetspeak substitutions used to dodge keyword filters (e.g. "sl0t
     * gac0r", "t0gel"). Applied as a second haystack alongside the raw text -
     * cheap, local, no API call - so simple obfuscation still gets caught.
     * Deliberately excludes '1' - it stands for both 'i' and 'l' depending on
     * the word (s1tus vs s1ot), so a single substitution would corrupt as many
     * words as it fixes.
     */
    /** Image formats the Messages API can read - see looksLikeSupportedImage(). */
    protected const SUPPORTED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** Characters of page text kept per detection - enough to quote, not a mirror. */
    protected const SNAPSHOT_LIMIT = 40000;

    /** Added/removed passages reported per change, so a rewritten page stays readable. */
    protected const MAX_DIFF_CHUNKS = 8;

    protected const LEETSPEAK_MAP = [
        '0' => 'o', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's',
    ];

    /**
     * Full-site scan: crawl every reachable page and flag new judol matches.
     *
     * @return int number of new detections created
     */
    public function scan(Website $website): int
    {
        $found = collect();

        foreach ($this->crawl($website) as $url => $page) {
            if ($detection = $this->evaluateAndStore($website, $url, $page['html'], lastModified: $page['last_modified'])) {
                $found->push($detection);
            }
        }

        // Marks this site "just scanned" so isDueForJudolScan() waits out its
        // interval before the scheduler queues another full crawl for it.
        $website->update(['judol_scanned_at' => now()]);

        // One notification for the whole crawl, not one per flagged page - see
        // JudolContentDetected.
        $this->notifyFindings($website, $found);

        return $found->count();
    }

    /**
     * Force-check one specific URL right now (e.g. "Cek URL Tertentu" in the UI),
     * regardless of whether it was already scanned before.
     *
     * @return array<int, string> matched keywords, empty when the page is clean
     */
    public function scanUrlNow(Website $website, string $url): array
    {
        $result = $this->fetchWithError($url);

        if ($result['body'] === null) {
            throw new RuntimeException("Tidak dapat mengambil konten dari {$url}: {$result['error']}. Pastikan URL benar dan dapat diakses.");
        }

        // Deliberately does NOT notify: this runs because somebody clicked "Cek URL
        // Tertentu" and is already looking at the verdict on screen. Only the
        // automatic crawl (scan()) mails/notifies, so re-checking the same page ten
        // times in a row can't turn into ten emails.
        $detection = $this->evaluateAndStore($website, $url, $result['body'], force: true, lastModified: $result['last_modified']);

        return $detection?->matched_keywords ?? [];
    }

    /**
     * Breadth-first crawl starting from the homepage and sitemap(s), following
     * same-host links up to config('judol.max_depth') / config('judol.max_pages').
     *
     * @return Generator<string, array{html: string, last_modified: ?Carbon}>
     */
    protected function crawl(Website $website): Generator
    {
        $maxPages = max(1, (int) config('judol.max_pages', 60));
        $maxDepth = max(0, (int) config('judol.max_depth', 3));
        $host = parse_url($website->url, PHP_URL_HOST);

        $visited = [];
        $queue = [[$website->url, 0]];

        foreach ($this->sitemapUrls($website) as $sitemapUrl) {
            $queue[] = [$sitemapUrl, 0];
        }

        while ($queue && count($visited) < $maxPages) {
            [$url, $depth] = array_shift($queue);
            $url = $this->normalizeUrl($url);

            if (isset($visited[$url]) || ! $this->isCrawlable($url, $host)) {
                continue;
            }

            $visited[$url] = true;
            $result = $this->fetchWithError($url);
            $html = $result['body'];

            if ($html === null) {
                continue;
            }

            yield $url => ['html' => $html, 'last_modified' => $result['last_modified']];

            if ($depth < $maxDepth && count($visited) < $maxPages) {
                foreach ($this->extractLinks($html, $url, $host) as $link) {
                    if (! isset($visited[$link])) {
                        $queue[] = [$link, $depth + 1];
                    }
                }
            }
        }
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
     * @return array{body: ?string, error: ?string, last_modified: ?Carbon}
     */
    protected function fetchWithError(string $url): array
    {
        try {
            $response = $this->httpClient()->get($url);
        } catch (Throwable $e) {
            if (str_starts_with($url, 'https://') && Str::contains(Str::lower($e->getMessage()), ['ssl', 'certificate'])) {
                try {
                    $response = $this->httpClient(verifySsl: false)->get($url);
                    Log::warning("JudolScanner: {$url} diambil tanpa verifikasi SSL (curl tidak memercayai sertifikatnya, padahal browser biasa menerimanya).");
                } catch (Throwable $retryException) {
                    Log::warning("JudolScanner: gagal mengambil {$url}: {$retryException->getMessage()}");

                    return ['body' => null, 'error' => $retryException->getMessage(), 'last_modified' => null];
                }
            } else {
                Log::warning("JudolScanner: gagal mengambil {$url}: {$e->getMessage()}");

                return ['body' => null, 'error' => $e->getMessage(), 'last_modified' => null];
            }
        }

        if (! $response->successful()) {
            $reason = "HTTP {$response->status()}";
            Log::warning("JudolScanner: gagal mengambil {$url} ({$reason})");

            return ['body' => null, 'error' => $reason, 'last_modified' => null];
        }

        return ['body' => $response->body(), 'error' => null, 'last_modified' => $this->parseLastModified($response->header('Last-Modified'))];
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
            ->withOptions(['verify' => $verifySsl])
            ->timeout(15)
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
     * The full searchable text of a page: visible text (title, body, ...) PLUS every
     * <meta content="..."> value. Judol/SEO spam is very often stuffed into meta
     * description, og:description, keywords, etc. - which live in an attribute, so a
     * plain strip_tags() on the raw HTML silently misses all of it.
     */
    protected function extractText(string $html): string
    {
        $dom = $this->parseHtml($html);

        if (! $dom) {
            return '';
        }

        $parts = [$dom->textContent ?? ''];

        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if ($content = trim($meta->getAttribute('content'))) {
                $parts[] = $content;
            }
        }

        return implode(' ', $parts);
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
    protected function evaluateAndStore(Website $website, string $url, string $html, bool $force = false, ?Carbon $lastModified = null): ?JudolDetection
    {
        $existing = $website->judolDetections()->where('url', $url)->first();

        // Automatic crawl only cares about pages it hasn't already flagged; a manual
        // "cek URL tertentu" always re-evaluates, even for a URL checked before.
        if ($existing && ! $force) {
            return null;
        }

        $rawText = $this->extractText($html);
        $text = Str::lower($rawText);
        // Also check the URL itself: some injected pages carry the keyword only in
        // their slug (e.g. /slot-gacor-hari-ini/) with sparse or JS-rendered content.
        // Hyphens/underscores are normalized to spaces so "slot-gacor" still matches
        // the "slot gacor" keyword phrase.
        $urlSlug = str_replace(['-', '_'], ' ', Str::lower(urldecode($url)));
        $haystack = $text.' '.$urlSlug;

        // Deobfuscated copy catches simple keyword-filter evasion (e.g. "s1ot gacor",
        // "t0gel") without any extra cost - see LEETSPEAK_MAP.
        $normalizedHaystack = strtr($haystack, self::LEETSPEAK_MAP);

        // Dynamic keyword list (Deteksi Judol page) - falls back to config/judol.php
        // only if the table is somehow empty, so a botched migration/seed never
        // leaves the scanner silently matching nothing.
        $keywords = JudolKeyword::activeList() ?: config('judol.keywords', []);
        $threshold = (int) (Setting::get('judol_threshold') ?? config('judol.threshold', 1));

        $matched = collect($keywords)
            ->filter(fn (string $keyword) => Str::contains($haystack, Str::lower($keyword))
                || Str::contains($normalizedHaystack, Str::lower($keyword)))
            ->values();

        if ($matched->count() < $threshold) {
            $this->clearStaleDetection($existing, $url, 'kata kunci tidak lagi cocok');

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

        if ($aiSettings->enabled()) {
            $verdict = app(JudolAiClassifier::class)->classify(
                $url,
                $text,
                $matched->all(),
                $this->extractImages($html, $url),
            );

            $aiConfidentlyDenies = $verdict['is_judol'] === false
                && $verdict['confidence'] >= $aiSettings->confidenceThreshold();

            if ($aiConfidentlyDenies) {
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

        $snippetPosition = Str::position($text, Str::lower($matched->first())) ?: 0;
        $snippet = trim(substr($text, max(0, $snippetPosition - 60), 200));
        $description = "Halaman {$url} mengandung kata kunci judi online: ".$matched->implode(', ');

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
                'meta' => ['url' => $url, 'matched_keywords' => $matched->all()],
                'status' => 'open',
                'resolved_at' => null,
                'detected_at' => now(),
            ]);

            $snapshot = $this->snapshotText($rawText);

            $existing->update([
                'incident_id' => $incident->id,
                'matched_keywords' => $matched->all(),
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
            'meta' => ['url' => $url, 'matched_keywords' => $matched->all()],
            'status' => 'open',
            'detected_at' => now(),
        ]);

        $snapshot = $this->snapshotText($rawText);

        return JudolDetection::create([
            'website_id' => $website->id,
            'incident_id' => $incident->id,
            'url' => $url,
            'matched_keywords' => $matched->all(),
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
     * @param  Collection<int, JudolDetection>  $found
     */
    protected function notifyFindings(Website $website, Collection $found): void
    {
        $found = $found->filter(fn (JudolDetection $detection) => $detection->wasRecentlyCreated
            || $detection->wasChanged('content_hash'))->values();

        if ($found->isEmpty()) {
            return;
        }

        app(MailSettings::class)->applyToRuntime();
        Notification::send(User::all(), new JudolContentDetected($website, $found->values()));
    }

    /**
     * Re-checks every stored detection's matched_keywords against the CURRENT active
     * keyword list and threshold - no network fetch needed, since matched_keywords
     * was already recorded at scan time. Call this right after a keyword is removed
     * or deactivated so stale detections disappear immediately, instead of relying on
     * someone noticing and manually "Cek Ulang"-ing each one (or waiting for the next
     * scheduled scan, which never re-evaluates a URL it already flagged).
     *
     * @return int number of stale detections cleared
     */
    public function pruneStaleDetections(): int
    {
        $activeKeywords = collect(JudolKeyword::activeList())->map(fn (string $k) => Str::lower($k))->all();
        $threshold = (int) (Setting::get('judol_threshold') ?? config('judol.threshold', 1));
        $cleared = 0;

        JudolDetection::whereNotNull('matched_keywords')
            ->chunkById(50, function ($detections) use ($activeKeywords, $threshold, &$cleared) {
                foreach ($detections as $detection) {
                    $stillActive = collect($detection->matched_keywords)
                        ->filter(fn (string $keyword) => in_array(Str::lower($keyword), $activeKeywords, true))
                        ->count();

                    if ($stillActive < $threshold) {
                        $this->clearStaleDetection($detection, $detection->url, 'kata kunci yang cocok sudah dihapus/dinonaktifkan');
                        $cleared++;
                    }
                }
            });

        return $cleared;
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
