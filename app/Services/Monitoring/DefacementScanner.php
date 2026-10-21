<?php

namespace App\Services\Monitoring;

use App\Models\Website;
use App\Services\Security\OutboundUrlGuard;
use App\Support\ResponseSizeLimit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Looks for code on a page that the site's own authors did not put there.
 *
 * The judol scanner answers "does this page talk about gambling", which catches
 * SEO spam. This answers a different question: "is something executing here that
 * does not belong", which is what an actual compromise looks like before anyone
 * bothers to add visible spam - a hidden iframe, a miner, a loader pulling its
 * real payload from somewhere else.
 *
 * Only the homepage is examined. Injected code is almost always added to a shared
 * template, so it is on the homepage too, and checking one page a day per site
 * keeps this cheap enough to actually run.
 */
class DefacementScanner
{
    /** Reason codes reported by scan(); the rest are judged only against a baseline. */
    protected const REPORTED_CODES = [
        'foreign_script', 'obfuscated_js', 'js_redirect', 'cloaking', 'hidden_frame',
        'meta_refresh', 'hidden_link_block', 'cryptominer',
    ];

    /** @var array{body: string, status: int, last_modified: ?Carbon, complete: bool, final_url: ?string, redirects: int, text: PageText}|null the homepage fetched by the last scan() */
    protected ?array $lastHomepage = null;

    /**
     * Everything risky on a site's homepage right now (no baseline): fetched,
     * parsed once by PageTextExtractor, read from its InjectionProfile. Allow-lists
     * live in config('defacement.injection'); hosts compare by registrable domain.
     *
     * @return array<int, array{jenis: string, detail: string}>
     */
    public function scan(Website $website): array
    {
        $this->lastHomepage = null;
        $page = $this->fetchPage($website->url);

        if ($page['body'] === null) {
            return [];
        }

        $url = Str::before($website->url, '#');
        $text = app(PageTextExtractor::class)->extractAt($page['body'], $url, $page['complete'], $page['final_url'] ?? $url);
        // Cut at the size limit or parsed only part way: absence of a finding proves nothing.
        $this->lastHomepage = ['complete' => $page['complete'] && $text->complete, 'text' => $text] + $page;

        $findings = [];
        $seen = [];

        foreach ($text->risk?->items ?? [] as $key => $item) {
            if (! in_array($item['code'], self::REPORTED_CODES, true)) {
                continue;
            }

            // One line per foreign host, not per script path.
            $detail = $item['code'] === 'foreign_script' ? Str::before(Str::after((string) $key, 'xs|'), '/') : $item['detail'];

            if (isset($seen[$item['code'].'|'.$detail])) {
                continue;
            }

            $seen[$item['code'].'|'.$detail] = true;
            $findings[] = ['jenis' => DefacementMonitor::INJECTION_LABELS[$item['code']] ?? $item['code'], 'detail' => $detail];
        }

        return $findings;
    }

    /**
     * The homepage the last scan() fetched successfully, or null when it failed -
     * reused by the content/defacement check so no second request is made.
     *
     * @return array{body: string, status: int, last_modified: ?Carbon, complete: bool, final_url: ?string, redirects: int, text: PageText}|null
     */
    public function lastHomepage(): ?array
    {
        return $this->lastHomepage;
    }

    protected function fetch(string $url): ?string
    {
        return $this->fetchPage($url)['body'];
    }

    /**
     * @return array{body: ?string, status: ?int, last_modified: ?Carbon, complete: bool, final_url: ?string, redirects: int}
     */
    protected function fetchPage(string $url): array
    {
        $failed = ['body' => null, 'status' => null, 'last_modified' => null, 'complete' => false, 'final_url' => null, 'redirects' => 0];

        if (OutboundUrlGuard::reason($url)) {
            return $failed;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => JudolScanner::BROWSER_USER_AGENT])
                ->timeout(15)
                ->connectTimeout(10)
                ->withOptions(OutboundUrlGuard::httpOptions())
                ->setHandler(ResponseSizeLimit::handler(OutboundUrlGuard::pinnedHandler()))
                ->get($url);
        } catch (Throwable) {
            return $failed;
        }

        if (! $response->successful()) {
            return ['status' => $response->status()] + $failed;
        }

        try {
            $lastModified = filled($header = $response->header('Last-Modified'))
                ? Carbon::parse($header)->setTimezone(config('app.timezone'))
                : null;
        } catch (Throwable) {
            $lastModified = null;
        }

        $body = $response->toPsrResponse()->getBody();

        return [
            'body' => $response->body(),
            'status' => $response->status(),
            'last_modified' => $lastModified,
            'complete' => ! ($body instanceof ResponseSizeLimit && $body->truncated),
        ] + OutboundUrlGuard::redirectFacts($response, $url);
    }
}
