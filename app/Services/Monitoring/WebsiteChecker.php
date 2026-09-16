<?php

namespace App\Services\Monitoring;

use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Notifications\AttackAnomalyDetected;
use App\Notifications\DnsChangedNotification;
use App\Notifications\DomainExpiringNotification;
use App\Notifications\SslExpiringNotification;
use App\Notifications\WebsiteDownNotification;
use App\Notifications\WebsiteRecoveredNotification;
use App\Services\Notifications\MailSettings;
use App\Services\Notifications\WebhookNotifier;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Notifications\Notification as NotificationClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Probes a website over HTTP and records the result, opening or resolving
 * incidents for downtime and response-time / content anomalies along the way.
 */
class WebsiteChecker
{
    /**
     * How far ahead a domain expiry is worth shouting about.
     *
     * Longer than the 14 days used for TLS, on purpose: a certificate can be
     * reissued in minutes, while renewing a .go.id domain runs through a
     * procurement process that does not move in a fortnight.
     */
    public const DOMAIN_WARNING_DAYS = 45;

    public function check(Website $website): WebsiteCheck
    {
        $start = microtime(true);

        $isUp = false;
        $statusCode = null;
        $contentHash = null;
        $contentLength = null;
        $errorMessage = null;

        try {
            $response = $this->fetchWithSslFallback($website->url);

            $statusCode = $response->status();
            $body = $response->body();
            $contentLength = strlen($body);
            $contentHash = sha1($body);
            $isUp = $response->successful() || $response->redirect();

            if (! $isUp) {
                $errorMessage = "HTTP {$statusCode}";
            }
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
        }

        $responseTimeMs = (int) round((microtime(true) - $start) * 1000);

        // A response that arrives past the site's own acceptable-latency threshold is
        // treated the same as no response at all: for practical purposes the site is
        // "down" for whoever is waiting on it, so it counts toward down_threshold_failures
        // and the downtime incident flow the same way an HTTP failure does.
        if ($isUp && $responseTimeMs > $website->response_time_threshold_ms) {
            $isUp = false;
            $errorMessage = "Response time {$responseTimeMs}ms melebihi ambang batas {$website->response_time_threshold_ms}ms";
        }

        // Recorded either way: the uptime history has to stay honest through a
        // maintenance window, otherwise a planned outage quietly flatters the
        // monthly figures. Only the incidents and the emails stop - see below.
        $underMaintenance = $website->isUnderMaintenance();

        $check = $website->checks()->create([
            'is_up' => $isUp,
            'status_code' => $statusCode,
            'response_time_ms' => $responseTimeMs,
            'content_hash' => $contentHash,
            'content_length' => $contentLength,
            'error_message' => $errorMessage,
            'checked_at' => now(),
        ]);

        // last_status still tracks reality during a window, so the moment it ends
        // the site is already in the right state rather than one check behind.
        $this->handleUpDown($website, $check, $underMaintenance);

        if ($isUp && ! $underMaintenance) {
            $this->detectResponseTimeAnomaly($website, $check);
            $this->detectContentAnomaly($website, $check);
            $this->maybeCheckSsl($website);
        }

        $website->update(['last_checked_at' => now()]);

        return $check;
    }

    /**
     * Fetches a URL, retrying once without SSL verification if the first attempt
     * fails on a certificate/chain problem - a handful of hosts present a chain
     * curl doesn't trust yet (e.g. a newer intermediate CA) even though real
     * browsers accept it fine, which was showing up as false "down" alerts. Mirrors
     * JudolScanner::fetchWithError() - same root cause, same fix.
     *
     * @throws Throwable when both the initial attempt and (if applicable) the
     *                   no-verify retry fail
     */
    protected function fetchWithSslFallback(string $url)
    {
        // Refused before the socket opens: a stored URL can point somewhere it was
        // never allowed to (added before this guard existed, or repointed by DNS).
        if ($reason = OutboundUrlGuard::reason($url)) {
            throw new RuntimeException($reason);
        }

        try {
            return $this->httpClient()->get($url);
        } catch (Throwable $e) {
            if (str_starts_with($url, 'https://') && Str::contains(Str::lower($e->getMessage()), ['ssl', 'certificate'])) {
                $response = $this->httpClient(verifySsl: false)->get($url);
                Log::warning("WebsiteChecker: {$url} diambil tanpa verifikasi SSL (curl tidak memercayai sertifikatnya, padahal browser biasa menerimanya).");

                return $response;
            }

            throw $e;
        }
    }

    /**
     * A browser-like client: realistic UA + headers (avoids WAF bot-blocking that
     * looked like false downtime), generous timeout, and one retry with backoff
     * (handles a transient blip - e.g. a slow TLS handshake - without immediately
     * counting it as a failed check).
     */
    protected function httpClient(bool $verifySsl = true): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
        ])
            // httpOptions() caps redirects and re-checks each hop's address, so a
            // 302 cannot land somewhere the guard above already refused.
            ->withOptions(['verify' => $verifySsl] + OutboundUrlGuard::httpOptions())
            // 20s hard ceiling - comfortably above the 15s default response_time_threshold_ms
            // (see the websites table default) so a legitimately slow-but-reachable site
            // (common for shared-hosting government/institutional sites) gets counted as a
            // real, measured "down due to slow response" rather than an opaque connection timeout.
            ->timeout(20)
            // 10s to connect, not less. The sites monitored here are mostly
            // government/institutional ones on shared hosting, where a TLS
            // handshake routinely takes longer than a commodity host would: at 5s
            // they were cut off mid-handshake and recorded as down while a browser
            // still loaded them fine. The failure is also invisible in the numbers
            // it produces - no response means no response_time_ms, so the check
            // reads as "down" with a latency well under the threshold, which is
            // exactly the confusing shape this caused in production.
            ->connectTimeout(10)
            // Two attempts with backoff. This is deliberately NOT the tight probe
            // it looks like it should be: one dropped handshake here becomes a red
            // dot on the dashboard and, past down_threshold_failures, a downtime
            // incident and an email to every admin. The cost of a retry is one more
            // request; the cost of skipping it is a false alarm.
            ->retry(2, 300, throw: false);
    }

    protected function handleUpDown(Website $website, WebsiteCheck $check, bool $underMaintenance = false): void
    {
        if (! $check->is_up) {
            $website->increment('consecutive_failures');
            $website->refresh();

            if ($website->last_status !== 'down' && $website->consecutive_failures >= $website->down_threshold_failures) {
                $website->update(['last_status' => 'down']);

                // Scheduled: the state is recorded, but nobody is woken up for an
                // outage they arranged themselves.
                if ($underMaintenance) {
                    return;
                }

                // What the run-up looked like, recorded at the moment the incident
                // opens - the checks it reads are pruned over time, so diagnosing
                // this later from stored history would not give the same answer.
                $diagnosis = app(OutageCause::class)->diagnose($website, $check);

                $incident = Incident::create([
                    'website_id' => $website->id,
                    'type' => 'downtime',
                    'severity' => 'critical',
                    'title' => "{$website->name} tidak dapat diakses",
                    'description' => $check->error_message ?? 'Website gagal merespons pada beberapa kali percobaan berturut-turut.',
                    'meta' => [
                        'consecutive_failures' => $website->consecutive_failures,
                        'status_code' => $check->status_code,
                        'error_message' => $check->error_message,
                        ...($diagnosis ? [
                            'likely_cause' => $diagnosis['cause'],
                            'cause_evidence' => $diagnosis['evidence'],
                            'cause_caveat' => $diagnosis['caveat'],
                            // Read by Website::statusKey() to colour the status dot.
                            // A flag, not the prose: rewording the label must not
                            // change what the dashboard shows.
                            'cause_is_overload' => $diagnosis['is_overload'],
                        ] : []),
                    ],
                    'status' => 'open',
                    'detected_at' => now(),
                ]);

                $this->notifyAdmins(new WebsiteDownNotification($website, $incident));
            }

            return;
        }

        // Check succeeded: reset failure streak and resolve any open downtime incident.
        if ($website->last_status === 'down') {
            $website->update(['last_status' => 'up', 'consecutive_failures' => 0]);

            $incident = $website->incidents()->open()->where('type', 'downtime')->latest('detected_at')->first();
            $incident?->resolve();

            // A "pulih" email for an outage nobody was told about is just noise.
            if (! $underMaintenance) {
                $this->notifyAdmins(new WebsiteRecoveredNotification($website, $incident));
            }
        } elseif ($website->consecutive_failures > 0) {
            $website->update(['consecutive_failures' => 0, 'last_status' => 'up']);
        } elseif ($website->last_status === 'unknown') {
            $website->update(['last_status' => 'up']);
        }
    }

    /**
     * Flags a sudden spike vs. this site's own recent baseline as a possible attack
     * signal - even while still under response_time_threshold_ms (once a check
     * breaches that threshold it's already handled as "down" upstream in check(),
     * so this only ever sees checks that passed).
     */
    protected function detectResponseTimeAnomaly(Website $website, WebsiteCheck $check): void
    {
        $recentAverage = $website->checks()
            ->where('is_up', true)
            ->where('id', '!=', $check->id)
            ->latest('checked_at')
            ->limit(10)
            ->avg('response_time_ms');

        if (! $recentAverage || $recentAverage < 50) {
            return;
        }

        $isSpike = $check->response_time_ms > $recentAverage * 3;

        if ($isSpike && ! $this->hasRecentOpenIncident($website, 'attack', 'response_time_spike')) {
            $incident = Incident::create([
                'website_id' => $website->id,
                'type' => 'attack',
                'severity' => 'warning',
                'title' => "Lonjakan response time pada {$website->name}",
                'description' => sprintf(
                    'Response time %dms, jauh di atas rata-rata %dms. Bisa jadi indikasi lonjakan trafik / serangan.',
                    $check->response_time_ms,
                    (int) $recentAverage
                ),
                'meta' => ['reason' => 'response_time_spike', 'response_time_ms' => $check->response_time_ms, 'baseline_ms' => (int) $recentAverage],
                'status' => 'open',
                'detected_at' => now(),
            ]);

            $this->notifyAdmins(new AttackAnomalyDetected($website, $incident));
        }
    }

    protected function detectContentAnomaly(Website $website, WebsiteCheck $check): void
    {
        $previous = $website->checks()
            ->where('is_up', true)
            ->where('id', '!=', $check->id)
            ->whereNotNull('content_length')
            ->latest('checked_at')
            ->first();

        if (! $previous || ! $previous->content_length || ! $check->content_length) {
            return;
        }

        $diffRatio = abs($check->content_length - $previous->content_length) / max($previous->content_length, 1);

        if ($diffRatio > 0.4 && ! $this->hasRecentOpenIncident($website, 'attack', 'content_changed')) {
            $incident = Incident::create([
                'website_id' => $website->id,
                'type' => 'attack',
                'severity' => 'warning',
                'title' => "Perubahan konten drastis pada {$website->name}",
                'description' => sprintf(
                    'Ukuran halaman berubah dari %s ke %s byte secara tiba-tiba. Bisa jadi indikasi deface atau penyusupan konten.',
                    number_format($previous->content_length),
                    number_format($check->content_length)
                ),
                'meta' => ['reason' => 'content_changed', 'previous_length' => $previous->content_length, 'current_length' => $check->content_length],
                'status' => 'open',
                'detected_at' => now(),
            ]);

            $this->notifyAdmins(new AttackAnomalyDetected($website, $incident));
        }
    }

    protected function maybeCheckSsl(Website $website): void
    {
        if (! str_starts_with($website->url, 'https://')) {
            return;
        }

        if ($website->ssl_checked_at && $website->ssl_checked_at->isAfter(now()->subDay())) {
            return;
        }

        $website->update(['ssl_checked_at' => now()]);

        $expiresAt = $this->certificateExpiryDate($website->url);

        if ($expiresAt && $expiresAt->isBefore(now()->addDays(14)) && ! $this->hasRecentOpenIncident($website, 'ssl', null)) {
            $incident = Incident::create([
                'website_id' => $website->id,
                'type' => 'ssl',
                'severity' => 'warning',
                'title' => "Sertifikat SSL {$website->name} akan kedaluwarsa",
                'description' => 'Sertifikat SSL akan kedaluwarsa pada '.$expiresAt->translatedFormat('d F Y').'.',
                'meta' => ['expires_at' => $expiresAt->toIso8601String()],
                'status' => 'open',
                'detected_at' => now(),
            ]);

            // An expiring certificate is the one incident type nobody finds out
            // about by the site going down - by then it already has. hasRecentOpenIncident()
            // above is what keeps this to one warning per certificate, not one a day.
            $this->notifyAdmins(new SslExpiringNotification($website, $incident));
        }
    }

    /**
     * Compares today's DNS answer against the last one recorded.
     *
     * Driven by the daily websites:check-domains command rather than by check():
     * zone records change on the order of years, so four extra lookups a minute
     * per site would be a resolver flood for nothing.
     *
     * Deliberately not gated on the site being up - a hijacked domain answers 200
     * from somebody else's server, and an expired one stops resolving entirely,
     * so the check that would explain a dead site must not be skipped precisely
     * when the site is dead.
     */
    public function checkDns(Website $website): void
    {
        if ($website->dns_checked_at && $website->dns_checked_at->isAfter(now()->subDay())) {
            return;
        }

        $host = parse_url($website->url, PHP_URL_HOST);

        if (! $host) {
            return;
        }

        $monitor = app(DnsMonitor::class);
        $current = $monitor->lookUp($host);

        // Nothing resolved at all: a resolver problem or an already-dead domain,
        // neither of which should overwrite a good baseline with an empty one.
        if ($current === []) {
            return;
        }

        $previous = $website->dns_records ?? [];

        $website->update(['dns_records' => $current, 'dns_checked_at' => now()]);

        // First sighting - there is nothing to have changed from.
        if ($previous === []) {
            return;
        }

        $changes = $monitor->diff($previous, $current);

        if ($changes === [] || $this->hasRecentOpenIncident($website, 'dns', null)) {
            return;
        }

        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'dns',
            'severity' => $monitor->severityFor($changes),
            'title' => "Record DNS {$website->name} berubah",
            'description' => 'Record '.implode(', ', array_keys($changes)).' berbeda dari yang terakhir tercatat.',
            'meta' => ['changes' => $changes],
            'status' => 'open',
            'detected_at' => now(),
        ]);

        $this->notifyAdmins(new DnsChangedNotification($website, $incident));
    }

    /**
     * Warns while there is still time to renew.
     *
     * A date typed in by an operator is never cleared by a lookup that came back
     * empty - plenty of ccTLDs (.id among them) publish no RDAP at all, and
     * wiping a known-good date because a registry is silent would be worse than
     * not looking.
     */
    public function checkDomainExpiry(Website $website): void
    {
        if ($website->domain_checked_at && $website->domain_checked_at->isAfter(now()->subDay())) {
            return;
        }

        $website->update(['domain_checked_at' => now()]);

        if ($found = app(DomainExpiry::class)->lookUp($website->url)) {
            $website->update(['domain_expires_at' => $found]);
        }

        $expiresAt = $website->fresh()->domain_expires_at;

        if (! $expiresAt || $expiresAt->isAfter(now()->addDays(self::DOMAIN_WARNING_DAYS))) {
            return;
        }

        if ($this->hasRecentOpenIncident($website, 'domain', null)) {
            return;
        }

        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'domain',
            // Past the date is not a warning any more - the domain is gone.
            'severity' => $expiresAt->isPast() ? 'critical' : 'warning',
            'title' => "Domain {$website->name} akan kedaluwarsa",
            'description' => 'Registrasi domain berakhir pada '.$expiresAt->translatedFormat('d F Y').'.',
            'meta' => ['expires_at' => $expiresAt->toIso8601String()],
            'status' => 'open',
            'detected_at' => now(),
        ]);

        $this->notifyAdmins(new DomainExpiringNotification($website, $incident));
    }

    protected function certificateExpiryDate(string $url): ?Carbon
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT) ?? 443;

        try {
            $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
            $client = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);

            if (! $client) {
                return null;
            }

            $params = stream_context_get_params($client);
            fclose($client);

            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);

            return isset($cert['validTo_time_t']) ? Carbon::createFromTimestamp($cert['validTo_time_t']) : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function hasRecentOpenIncident(Website $website, string $type, ?string $reason): bool
    {
        return $website->incidents()
            ->where('type', $type)
            ->open()
            ->when($reason, fn ($q) => $q->whereJsonContains('meta->reason', $reason))
            ->exists();
    }

    /**
     * Sends the notification to every user (single-tenant admin dashboard: everyone sees every website).
     * applyToRuntime() points the "smtp" mailer at the saved settings right before
     * sending - see App\Services\Notifications\MailSettings::applyToRuntime().
     */
    protected function notifyAdmins(NotificationClass $notification): void
    {
        $admins = User::all();

        app(MailSettings::class)->applyToRuntime();

        Notification::send($admins, $notification);

        // Once for the event, not once per admin: the webhook points at a shared
        // channel, and six identical Telegram messages for one outage is how a
        // channel gets muted.
        app(WebhookNotifier::class)->sendNotification($notification, $admins->first());
    }
}
