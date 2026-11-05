<?php

namespace App\Services\Security;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use GuzzleHttp\Utils as GuzzleUtils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

/**
 * Decides whether this server is allowed to fetch a URL.
 *
 * Every outbound request this app makes is aimed at an address a user typed
 * (situs yang dipantau) or that came out of a hacked site's markup. Without a
 * check, either one can point at 127.0.0.1, an intranet host, or a cloud
 * metadata endpoint - and the monitor becomes a proxy into its own network.
 *
 * Hosts marked is_internal_only are not an exception: those resolve only from
 * inside their own organization's network, so they never resolve to a private
 * address here in the first place.
 */
class OutboundUrlGuard
{
    /** How long a host's verdict is trusted. Freshness only: the fetch is pinned to the vetted IPs. */
    protected const VERDICT_TTL_SECONDS = 60;

    /** Most addresses pinned per host; the rest are still vetted. */
    protected const MAX_PINNED_ADDRESSES = 8;

    /** Resolved verdicts keyed by host: [reason, addresses, expires_at]. DNS is slow and asked repeatedly. */
    protected static array $memo = [];

    /** Test seam: host => addresses, used instead of the system resolver when set. */
    protected static ?array $fakeDns = null;

    /** Test seam: the transport under pinnedHandler(), instead of Guzzle's default. */
    protected static $fakeTransport = null;

    /** Ranges PHP's own filters miss: CGNAT, plus IPv4-mapped IPv6 handled in normalize(). */
    protected const EXTRA_BLOCKED_CIDRS = ['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15'];

    /** Never resolvable to anything but this machine, whatever the resolver says. */
    protected const BLOCKED_HOSTS = ['localhost', 'ip6-localhost', 'ip6-loopback'];

    /** Why this URL may not be fetched, or null when it may. */
    public static function reason(string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return 'Hanya alamat http:// atau https:// yang bisa dipantau.';
        }

        if (! $host) {
            return 'Alamat tidak memuat nama host yang valid.';
        }

        // user:pass@host leaks credentials into logs and hides the real host from a glance.
        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            return 'Alamat tidak boleh memuat username atau password.';
        }

        return static::memoized(strtolower($host))[0];
    }

    /** [reason, vetted addresses] for a host, from the same memo entry as reason(). */
    public static function addressesFor(string $host): array
    {
        return static::memoized(strtolower($host));
    }

    /**
     * The verdict for a host, recomputed once its entry has aged out.
     *
     * Time-boxed rather than kept forever because queue:work is long-lived: without
     * an expiry, a host that changes DNS keeps whichever answer it got on the
     * worker's very first job.
     */
    protected static function memoized(string $host): array
    {
        [$reason, $addresses, $expiresAt] = static::$memo[$host] ?? [null, [], 0];

        if ($expiresAt < time()) {
            [$reason, $addresses] = static::resolveReason($host);

            // A miss is not memoized: the retry after a DNS blip must ask the resolver again.
            if ($reason !== null || $addresses !== []) {
                static::$memo[$host] = [$reason, $addresses, time() + static::VERDICT_TTL_SECONDS];
            }
        }

        return [$reason, $addresses];
    }

    public static function allows(string $url): bool
    {
        return static::reason($url) === null;
    }

    /**
     * Final URL and redirect count of a response fetched with httpOptions(), from
     * the X-Guzzle-Redirect-History header (track_redirects) - no extra request.
     *
     * Guzzle puts the real hops FIRST and keeps any value the target sent itself
     * after them, so the count is the position of the first entry equal to the
     * transfer's effective URI. No history means no redirect, whatever the URL
     * looks like (no false hop from percent-encoding/IDN differences). Ending at
     * the requested address counts as no redirect (a hop back to it is rare and
     * changes nothing the forensics compare).
     *
     * @return array{final_url: string, redirects: int}
     */
    public static function redirectFacts(Response $response, string $url): array
    {
        $history = array_values(array_map('trim', $response->toPsrResponse()->getHeader('X-Guzzle-Redirect-History')));
        $effective = $response->effectiveUri() !== null ? (string) $response->effectiveUri() : null;

        // Still at the requested address: no hop happened, so any history is the target's own header.
        $position = $effective !== null && $effective !== (string) Psr7Utils::uriFor($url)
            ? array_search($effective, $history, true)
            : false;

        return $position === false
            ? ['final_url' => $url, 'redirects' => 0]
            : ['final_url' => $effective, 'redirects' => $position + 1];
    }

    /**
     * Guzzle options that keep a redirect from walking past the check above.
     *
     * The first address is vetted before the request; a 302 is a second address
     * nobody vetted, which is the ordinary way an SSRF filter gets bypassed.
     */
    public static function httpOptions(): array
    {
        return [
            'allow_redirects' => [
                'max' => 5,
                'protocols' => ['http', 'https'],
                'strict' => false,
                'referer' => false,
                // Adds X-Guzzle-Redirect-History to the response; no extra request.
                'track_redirects' => true,
                'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
                    if ($reason = static::reason((string) $uri)) {
                        Log::warning('OutboundUrlGuard: redirect refused', ['to' => (string) $uri, 'reason' => $reason]);

                        throw new RuntimeException("Pengalihan ditolak: {$reason}");
                    }
                },
            ],
        ];
    }

    /**
     * Wraps a Guzzle handler so every request it sends (each redirect hop, retry
     * and fallback) connects only to the addresses vetted for that request's host,
     * via CURLOPT_RESOLVE - curl never resolves the name a second time itself.
     */
    public static function pinningHandler(callable $inner): callable
    {
        return function (RequestInterface $request, array $options) use ($inner) {
            $uri = $request->getUri();

            if ($reason = static::reason((string) $uri)) {
                return Create::rejectionFor(new RuntimeException($reason));
            }

            $host = strtolower($uri->getHost());

            // An IP literal is already vetted by reason() and needs no RESOLVE.
            if (! filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
                $addresses = array_slice(static::addressesFor($host)[1], 0, static::MAX_PINNED_ADDRESSES);

                // Without a pin curl would resolve on its own - the rebinding gap - so refuse instead.
                if ($addresses === []) {
                    return Create::rejectionFor(new ConnectException("cURL error 6: Could not resolve host: {$host}", $request, null, ['errno' => 6]));
                }

                $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
                $pinned = array_map(fn (string $ip) => str_contains($ip, ':') ? "[{$ip}]" : $ip, $addresses);
                $options['curl'][CURLOPT_RESOLVE] = ["{$host}:{$port}:".implode(',', $pinned)];
            }

            // A proxy would resolve the name itself, and RESOLVE does not apply behind one.
            $options['proxy'] = '';

            return $inner($request, $options);
        };
    }

    /** Guzzle's default handler wrapped in pinningHandler(), for any outbound Http client. */
    public static function pinnedHandler(): callable
    {
        return static::pinningHandler(static::$fakeTransport ?? GuzzleUtils::chooseHandler());
    }

    /** Test seam: pinnedHandler() hands requests to $transport until flush(). */
    public static function fakeTransport(callable $transport): void
    {
        static::$fakeTransport = $transport;
    }

    /** Test seam: answers lookups from $map (host => addresses) until flush(). */
    public static function fakeDns(array $map): void
    {
        static::$fakeDns = array_change_key_case($map);
    }

    /** Drops every memoized verdict and any fake DNS - for tests, and after a DNS change. */
    public static function flush(): void
    {
        static::$memo = [];
        static::$fakeDns = null;
        static::$fakeTransport = null;
    }

    /** [reason, addresses] for a host; addresses are empty for an IP literal or a refusal. */
    protected static function resolveReason(string $host): array
    {
        $host = trim($host, '[]');

        if (in_array(strtolower($host), static::BLOCKED_HOSTS, true) || str_ends_with(strtolower($host), '.localhost')) {
            return ['Alamat menunjuk ke server ini sendiri.', []];
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [static::isFetchable($host) ? null : static::privateAddressMessage($host, $host), []];
        }

        $addresses = static::lookUp($host);

        // Unresolvable is not the same as internal: a site whose DNS is briefly
        // broken must still be addable; pinningHandler() refuses to send it anyway.
        if ($addresses === []) {
            return [null, []];
        }

        foreach ($addresses as $address) {
            if (! static::isFetchable($address)) {
                return [static::privateAddressMessage($host, $address), []];
            }
        }

        return [null, $addresses];
    }

    /** Every A and AAAA record for a host, as plain strings. */
    protected static function lookUp(string $host): array
    {
        if (static::$fakeDns !== null) {
            return static::$fakeDns[strtolower($host)] ?? [];
        }

        $addresses = gethostbynamel($host) ?: [];

        // gethostbynamel is IPv4-only, and a host that is internal over IPv6 while
        // public over IPv4 has to be caught too.
        $records = @dns_get_record($host, DNS_AAAA) ?: [];

        foreach ($records as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    /** Public wrapper: whether an address is routable on the public internet. */
    public static function isPublicAddress(string $address): bool
    {
        return static::isPublic($address);
    }

    /**
     * Whether the monitor may connect to an address: public, or inside one of the
     * operator-approved private ranges (monitoring.allowed_private_cidrs) for
     * sites whose public name resolves to an intranet address from this server.
     * Kept apart from isPublic(), which also decides what DNS snapshots may show.
     */
    protected static function isFetchable(string $address): bool
    {
        return static::isPublic($address) || static::isAllowedPrivate($address);
    }

    /**
     * Only RFC 1918-style private space can be allowed. Loopback, link-local
     * (cloud metadata), CGNAT and other reserved ranges stay blocked even if a
     * configured CIDR happens to cover them.
     */
    protected static function isAllowedPrivate(string $address): bool
    {
        $address = static::normalize($address);

        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        foreach (static::EXTRA_BLOCKED_CIDRS as $cidr) {
            if (static::inCidr($address, $cidr)) {
                return false;
            }
        }

        foreach ((array) config('monitoring.allowed_private_cidrs', []) as $cidr) {
            $cidr = trim((string) $cidr);

            // Malformed or overly broad entries (/0-/7) are ignored rather than trusted.
            if (! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $cidr, $m)
                || ! filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                || (int) $m[2] < 8 || (int) $m[2] > 32) {
                continue;
            }

            if (static::inCidr($address, $cidr)) {
                return true;
            }
        }

        return false;
    }

    protected static function isPublic(string $address): bool
    {
        $address = static::normalize($address);

        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        foreach (static::EXTRA_BLOCKED_CIDRS as $cidr) {
            if (static::inCidr($address, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /** ::ffff:127.0.0.1 is the loopback wearing an IPv6 hat - judge it as IPv4. */
    protected static function normalize(string $address): string
    {
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $address, $m)) {
            return $m[1];
        }

        return $address;
    }

    protected static function inCidr(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return (ip2long($address) & $mask) === (ip2long($subnet) & $mask);
    }

    /** Generic for the user (no IP oracle); the detail goes to the log only. */
    protected static function privateAddressMessage(string $host, string $address): string
    {
        Log::warning('OutboundUrlGuard: internal address refused', ['host' => $host, 'address' => $address]);

        return 'Alamat ini mengarah ke jaringan internal dan tidak boleh dipantau.';
    }
}
