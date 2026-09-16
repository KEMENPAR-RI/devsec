<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    /**
     * The expiry date for a URL's domain, or null when nobody will tell us.
     */
    public function lookUp(string $url): ?Carbon
    {
        $domain = $this->registrableDomain($url);

        if (! $domain) {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->connectTimeout(5)
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
