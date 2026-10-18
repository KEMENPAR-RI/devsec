<?php

namespace App\Services\Monitoring;

use App\Services\Security\OutboundUrlGuard;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use GuzzleHttp\Utils as GuzzleUtils;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Looks up when a domain's registration runs out.
 *
 * An expired domain is the most common way an institutional site actually dies,
 * and unlike almost everything else this app watches, it is entirely preventable
 * - but only if somebody is told before the date, not after.
 *
 * RDAP over HTTPS rather than classic WHOIS on port 43: WHOIS is unparseable
 * free text that differs per registry, and outbound port 43 is blocked on most
 * shared hosting - which is exactly where these sites live. RDAP returns JSON
 * over the same HTTP client everything else here uses.
 *
 * Plenty of ccTLDs (including .id) publish no RDAP service at all. That is a
 * "tidak diketahui", never an error: the operator can type the date in by hand on
 * the site form, and a manually entered date is never overwritten by a lookup
 * that came back empty.
 */
class DomainExpiry
{
    /** The IANA bootstrap service, which redirects to whichever registry is authoritative. */
    protected const RDAP_BASE = 'https://rdap.org/domain/';

    // How long one apex's answer is reused within a run.
    protected const CACHE_SECONDS = 3600;

    // RDAP answers are a few KB; anything past this is dropped, not buffered.
    public const MAX_BODY_BYTES = 1024 * 1024;

    /** apex => [?Carbon, cached_at]: sites sharing an apex cost one RDAP call. */
    protected array $cache = [];

    /**
     * The expiry date for a URL's domain, or null when nobody will tell us.
     */
    public function lookUp(string $url): ?Carbon
    {
        $domain = $this->registrableDomain($url);

        if ($domain && function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $domain)) {
            $domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: null;
        }

        // Only a plain hostname may reach the RDAP path: no "/", "?", "#" or "@" smuggled in.
        if (! $domain || ! preg_match('/^[a-z0-9.-]+$/', strtolower($domain))) {
            return null;
        }

        $domain = strtolower($domain);

        if (isset($this->cache[$domain]) && $this->cache[$domain][1] > time() - self::CACHE_SECONDS) {
            return $this->cache[$domain][0]?->copy();
        }

        $found = $this->fetch($domain);
        $this->cache[$domain] = [$found, time()];

        return $found?->copy();
    }

    protected function fetch(string $domain): ?Carbon
    {
        try {
            // Guard + pinning like every other outbound call: rdap.org redirects to registries nobody vetted.
            $response = Http::acceptJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->withOptions(OutboundUrlGuard::httpOptions())
                ->setHandler($this->cappedBodyHandler(OutboundUrlGuard::pinningHandler(GuzzleUtils::chooseHandler())))
                ->get(self::RDAP_BASE.$domain);
        } catch (Throwable $e) {
            Log::info("DomainExpiry: RDAP tidak dapat dihubungi untuk {$domain}: {$e->getMessage()}");

            return null;
        }

        // 404 just means this registry has no RDAP endpoint - common for ccTLDs,
        // and not worth a log line on every scheduled run.
        if (! $response->successful()) {
            return null;
        }

        return $this->expiryFromEvents($response->json('events') ?? []);
    }

    /** Same capped-sink pattern as WebsiteChecker::cappedBodyHandler(): memory stays bounded. */
    protected function cappedBodyHandler(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            $buffer = Psr7Utils::streamFor('');
            $written = 0;

            $options['sink'] = FnStream::decorate($buffer, [
                'write' => function (string $data) use ($buffer, &$written): int {
                    if ($written < self::MAX_BODY_BYTES) {
                        $written += $buffer->write(substr($data, 0, self::MAX_BODY_BYTES - $written));
                    }

                    return strlen($data);
                },
            ]);

            return $handler($request, $options);
        };
    }

    /**
     * RDAP reports lifecycle moments as a list of events; the one that matters is
     * the "expiration" entry.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    protected function expiryFromEvents(array $events): ?Carbon
    {
        foreach ($events as $event) {
            if (($event['eventAction'] ?? null) !== 'expiration') {
                continue;
            }

            try {
                return Carbon::parse($event['eventDate'])->startOfDay();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * The registrable name to ask about, e.g. "kemenparekraf.go.id" from
     * "https://www.kemenparekraf.go.id/berita".
     *
     * Multi-label public suffixes are the whole difficulty here: "go.id" is not a
     * registrable domain, and asking a registry about it gets a flat refusal. The
     * Indonesian second-level suffixes are listed explicitly because they are what
     * this app is pointed at; anything else falls back to the last two labels,
     * which is right for .com/.org/.net and the common single-label ccTLDs.
     */
    public function registrableDomain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $host = strtolower(trim((string) $host));
        $host = preg_replace('/^www\./', '', $host);

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        $labels = explode('.', $host);

        if (count($labels) < 2) {
            return null;
        }

        $lastTwo = implode('.', array_slice($labels, -2));

        // go.id, co.id, ac.id, my.id, ... - the registrable name is three labels.
        if (preg_match('/^(go|co|ac|or|net|web|sch|mil|desa|ponpes|biz|my)\.id$/', $lastTwo) && count($labels) >= 3) {
            return implode('.', array_slice($labels, -3));
        }

        return $lastTwo;
    }
}
