<?php

namespace App\Http\Controllers;

use App\Models\JudolFinding;
use App\Services\Monitoring\JudolScanner;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Serves the promo banners found at an injection site.
 *
 * They cannot simply be hotlinked. This app's CSP is `img-src 'self' data:`
 * (see SecurityHeaders), so an <img> pointing at the compromised site is blocked
 * by the browser and the operator sees a broken box where the evidence should be.
 * Loosening the policy to allow arbitrary remote hosts would trade a real defence
 * for a thumbnail, so the bytes come through here instead.
 *
 * The URL is never taken from the request: the caller names a finding and an
 * INDEX into that finding's stored image_urls, so the only fetchable addresses
 * are ones the crawler already recorded. What is still attacker-influenced is
 * what lives at those addresses - the markup they came from belongs to a hacked
 * site - which is what the private-range block, the content-type allow-list and
 * the size cap below are for.
 */
class JudolEvidenceController extends Controller
{
    /** Raster formats only. An SVG is a script container, and no promo banner needs one. */
    private const ALLOWED_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /** Bytes kept per banner. Spam creatives are small; anything larger is not evidence. */
    private const MAX_BYTES = 2 * 1024 * 1024;

    private const CACHE_DIRECTORY = 'judol-evidence';

    public function image(JudolFinding $finding, int $index): Response
    {
        $url = ($finding->image_urls ?? [])[$index] ?? null;

        if (! $url || ! Str::startsWith($url, ['http://', 'https://'])) {
            throw new NotFoundHttpException;
        }

        if ($cached = $this->cached($url)) {
            return $this->respond($cached['body'], $cached['type']);
        }

        $fetched = $this->fetch($url);

        if (! $fetched) {
            throw new NotFoundHttpException;
        }

        $this->store($url, $fetched['body'], $fetched['type']);

        return $this->respond($fetched['body'], $fetched['type']);
    }

    /**
     * Downloads one banner, or null when anything at all is off about it.
     *
     * @return array{body: string, type: string}|null
     */
    private function fetch(string $url): ?array
    {
        if (! $this->isPubliclyRoutable($url)) {
            return null;
        }

        try {
            $response = $this->client()->get($url);
        } catch (Throwable $e) {
            // The same allowance JudolScanner::fetchWithError() makes, for the same
            // reason: a fair share of these hosts present a certificate chain curl
            // does not trust while every real browser accepts it. Without this the
            // crawler reads the page fine and then the banner from that very page
            // comes back as "gagal diambil" - which reads as a bug in this feature
            // rather than as the site's expired intermediate CA.
            if (! $this->looksLikeCertificateFailure($url, $e)) {
                return null;
            }

            try {
                $response = $this->client(verifySsl: false)->get($url);
                Log::warning("JudolEvidenceController: {$url} diambil tanpa verifikasi SSL (curl tidak memercayai sertifikatnya, padahal browser biasa menerimanya).");
            } catch (Throwable) {
                return null;
            }
        }

        if (! $response->successful()) {
            return null;
        }

        $type = trim(Str::lower(Str::before((string) $response->header('Content-Type'), ';')));
        $body = $response->body();

        if (! isset(self::ALLOWED_TYPES[$type]) || $body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        return ['body' => $body, 'type' => $type];
    }

    /**
     * The same browser-shaped client the crawler uses.
     *
     * Not an honest "evidence fetcher" UA: the hosts this reaches are the ones
     * already behind Cloudflare/Imunify360, and those WAFs quietly block anything
     * that does not look like a browser - see JudolScanner::BROWSER_USER_AGENT.
     * A banner the crawl could see has to stay fetchable here.
     */
    private function client(bool $verifySsl = true): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => JudolScanner::BROWSER_USER_AGENT])
            ->withOptions(['verify' => $verifySsl])
            // Connects only to the IPs isPubliclyRoutable() vetted - no second lookup to rebind.
            ->setHandler(OutboundUrlGuard::pinnedHandler())
            ->timeout(8)
            ->connectTimeout(4)
            // A redirect is a second address, and one this app never recorded - so
            // following it would walk straight past isPubliclyRoutable().
            ->withoutRedirecting();
    }

    /** A TLS trust failure, as opposed to a host that is simply unreachable. */
    private function looksLikeCertificateFailure(string $url, Throwable $e): bool
    {
        return str_starts_with($url, 'https://')
            && Str::contains(Str::lower($e->getMessage()), ['ssl', 'certificate']);
    }

    /**
     * Whether this host is somewhere on the public internet.
     *
     * The addresses here came out of a hacked site's markup, so one of them
     * pointing at 127.0.0.1 or a cloud metadata endpoint is a scenario worth
     * closing rather than reasoning about. The content-type check would catch most
     * of it; this makes the request never happen.
     */
    private function isPubliclyRoutable(string $url): bool
    {
        // One behaviour change: a host that does not resolve now passes, because
        // the fetch then simply fails on its own. Everything that does resolve is
        // checked over IPv6 as well as IPv4.
        return OutboundUrlGuard::allows($url);
    }

    /**
     * @return array{body: string, type: string}|null
     */
    private function cached(string $url): ?array
    {
        foreach (self::ALLOWED_TYPES as $type => $extension) {
            $path = $this->pathFor($url, $extension);

            if (Storage::disk('local')->exists($path)) {
                return ['body' => (string) Storage::disk('local')->get($path), 'type' => $type];
            }
        }

        return null;
    }

    private function store(string $url, string $body, string $type): void
    {
        Storage::disk('local')->put($this->pathFor($url, self::ALLOWED_TYPES[$type]), $body);
    }

    /**
     * Cached on the PRIVATE disk, not the public one: these are pulled off
     * compromised sites, and the permission gate in front of this route is only
     * meaningful while the bytes cannot also be fetched straight out of /storage.
     */
    private function pathFor(string $url, string $extension): string
    {
        return self::CACHE_DIRECTORY.'/'.sha1($url).'.'.$extension;
    }

    private function respond(string $body, string $type): Response
    {
        return response($body, 200, [
            'Content-Type' => $type,
            // Belt and braces with the allow-list: whatever the bytes turn out to
            // be, the browser is told not to go looking for something executable.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
