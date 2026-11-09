<?php

namespace App\Services\Monitoring;

use App\Models\Website;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Whether this monitor itself can reach the internet, checked before a site that
 * gave no response at all is marked down: when the VPS loses its uplink every
 * site would otherwise go red at once and flood admins with false alarms.
 *
 * Plain TCP connects to configured host:port targets - not ICMP (needs root) and
 * not the Http facade (a test's Http::fake would fake the probe too).
 */
class MonitorConnectivity
{
    public const CACHE_KEY = 'monitor:connectivity';

    public const OFFLINE_KEY = 'monitor:offline';

    // Set while probe targets fail but sites keep answering (egress to the targets blocked).
    public const BLOCKED_KEY = 'monitor:probe-blocked';

    // How long the dashboard indicator outlives the last failed probe.
    public const OFFLINE_TTL_SECONDS = 120;

    // How long a "targets blocked" verdict holds without fresh site responses.
    public const BLOCKED_TTL_SECONDS = 3600;

    // Answering sites examined for a public address; bounds the DNS lookups (mostly memoized).
    protected const EVIDENCE_SITES = 10;

    /** Online when any target accepts a connection; cached so one failing run probes once. */
    public function isOnline(): bool
    {
        // No targets configured: nothing to prove offline with, so never freeze checks.
        if (! config('monitoring.connectivity.enabled') || config('monitoring.connectivity.targets', []) === []) {
            return true;
        }

        $online = Cache::remember(
            self::CACHE_KEY,
            (int) config('monitoring.connectivity.cache_seconds', 30),
            fn (): bool => $this->probe(),
        );

        if ($online) {
            return true;
        }

        if ($this->probeTargetsBlocked()) {
            return true;
        }

        $this->markOffline();

        return false;
    }

    /** Whether the cached probe already says offline; never probes itself. */
    public function knownOffline(): bool
    {
        return (bool) config('monitoring.connectivity.enabled')
            && Cache::get(self::CACHE_KEY) === false
            && ! Cache::has(self::BLOCKED_KEY);
    }

    /** Records the offline episode for the dashboard, keeping its original start. */
    public function markOffline(): void
    {
        $current = Cache::get(self::OFFLINE_KEY);
        $now = now()->toIso8601String();

        // Logged once per episode, not once per site.
        if (! $current) {
            Log::warning('MonitorConnectivity: server monitor tidak dapat menjangkau internet; hasil cek tanpa respons tidak dicatat.');
        }

        Cache::put(self::OFFLINE_KEY, [
            'since' => $current['since'] ?? $now,
            'last_seen' => $now,
        ], self::OFFLINE_TTL_SECONDS);
    }

    /**
     * The current offline episode, or null when the monitor is (or was recently) online.
     *
     * @return array{since: string, last_seen: string}|null
     */
    public function offlineStatus(): ?array
    {
        $status = Cache::get(self::OFFLINE_KEY);

        return is_array($status) ? $status : null;
    }

    /**
     * Probe targets fail, yet sites kept answering since the probes started failing
     * (or recently, once known blocked): egress to the targets is firewalled, the
     * uplink is fine. Without this, every real outage would be dropped silently.
     */
    protected function probeTargetsBlocked(): bool
    {
        $blocked = Cache::has(self::BLOCKED_KEY);
        $since = $this->offlineStatus()['since'] ?? null;
        $window = (int) config('monitoring.connectivity.recent_response_seconds', 120);

        $from = match (true) {
            $blocked => now()->subSeconds($window),
            $since !== null => Carbon::parse($since)->timezone(config('app.timezone')),
            default => null,
        };

        if ($from === null || ! $this->sitesAnsweredSince($from)) {
            return false;
        }

        if (! $blocked) {
            Log::warning('MonitorConnectivity: target cek konektivitas tidak terjangkau, tetapi situs lain tetap merespons; periksa firewall egress ke MONITORING_CONNECTIVITY_TARGETS.');
        }

        Cache::put(self::BLOCKED_KEY, true, self::BLOCKED_TTL_SECONDS);
        Cache::forget(self::OFFLINE_KEY);

        return true;
    }

    /**
     * A genuine answer (2xx/3xx, or slow) since $from from a public site proves the uplink
     * works. Internal-only and LAN-resolving sites, error replies (a local proxy's 502) and
     * bare connections do not: they still answer while the uplink is down.
     */
    protected function sitesAnsweredSince(Carbon $from): bool
    {
        return Website::query()
            ->where('is_internal_only', false)
            ->whereHas('checks', fn ($query) => $query
                ->where('checked_at', '>=', $from)
                ->where(fn ($query) => $query->where('is_up', true)->orWhere('error_type', 'slow')))
            ->limit(self::EVIDENCE_SITES)
            ->pluck('url')
            ->contains(fn (string $url) => $this->isPublicHost($url));
    }

    /** Every address of the URL's host is public (not private/reserved); unresolvable is not. */
    protected function isPublicHost(string $url): bool
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

        if ($host === '') {
            return false;
        }

        // Memoized by the guard from the check's own fetch, so usually no new lookup.
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : OutboundUrlGuard::addressesFor($host)[1];

        return $addresses !== [] && collect($addresses)->every(
            fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false,
        );
    }

    protected function probe(): bool
    {
        $timeout = (float) config('monitoring.connectivity.timeout_seconds', 3);

        // Targets come from config only, never from user input.
        foreach ((array) config('monitoring.connectivity.targets', []) as $target) {
            $socket = @stream_socket_client('tcp://'.$target, $errno, $errstr, $timeout);

            if ($socket !== false) {
                fclose($socket);

                return true;
            }
        }

        return false;
    }
}
