<?php

namespace App\Services\Security;

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
    /** How long a host's verdict is trusted. Short enough that a DNS change is picked up. */
    protected const VERDICT_TTL_SECONDS = 300;

    /** Resolved verdicts keyed by host: [reason, expires_at]. DNS is slow and asked repeatedly. */
    protected static array $memo = [];

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

        return static::memoized(strtolower($host));
    }

    /**
     * The verdict for a host, recomputed once its entry has aged out.
     *
     * Time-boxed rather than kept forever because queue:work is long-lived: without
     * an expiry, a host that changes DNS keeps whichever answer it got on the
     * worker's very first job.
     */
    protected static function memoized(string $host): ?string
    {
        [$reason, $expiresAt] = static::$memo[$host] ?? [null, 0];

        if ($expiresAt < time()) {
            $reason = static::resolveReason($host);
            static::$memo[$host] = [$reason, time() + static::VERDICT_TTL_SECONDS];
        }

        return $reason;
    }

    public static function allows(string $url): bool
    {
        return static::reason($url) === null;
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
                'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
                    if ($reason = static::reason((string) $uri)) {
                        throw new RuntimeException("Pengalihan ke {$uri} ditolak: {$reason}");
                    }
                },
            ],
        ];
    }

    /** Drops every memoized verdict - for tests, and after a DNS change. */
    public static function flush(): void
    {
        static::$memo = [];
    }

    protected static function resolveReason(string $host): ?string
    {
        $host = trim($host, '[]');

        if (in_array(strtolower($host), static::BLOCKED_HOSTS, true) || str_ends_with(strtolower($host), '.localhost')) {
            return 'Alamat menunjuk ke server ini sendiri.';
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return static::isPublic($host) ? null : static::privateAddressMessage($host);
        }

        $addresses = static::lookUp($host);

        // Unresolvable is not the same as internal: a site whose DNS is briefly
        // broken must still be addable, and the fetch will fail on its own merits.
        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! static::isPublic($address)) {
                return static::privateAddressMessage($address);
            }
        }

        return null;
    }

    /** Every A and AAAA record for a host, as plain strings. */
    protected static function lookUp(string $host): array
    {
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

    protected static function privateAddressMessage(string $address): string
    {
        return "Alamat ini mengarah ke jaringan internal ({$address}) dan tidak boleh dipantau.";
    }
}
