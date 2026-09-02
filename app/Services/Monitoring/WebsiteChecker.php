<?php

namespace App\Services\Monitoring;

use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Notifications\AttackAnomalyDetected;
use App\Notifications\SslExpiringNotification;
use App\Notifications\WebsiteDownNotification;
use App\Notifications\WebsiteRecoveredNotification;
use App\Services\Notifications\MailSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Notifications\Notification as NotificationClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Probes a website over HTTP and records the result, opening or resolving
 * incidents for downtime and response-time / content anomalies along the way.
 */
class WebsiteChecker
{
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

        $check = $website->checks()->create([
            'is_up' => $isUp,
            'status_code' => $statusCode,
            'response_time_ms' => $responseTimeMs,
            'content_hash' => $contentHash,
            'content_length' => $contentLength,
            'error_message' => $errorMessage,
            'checked_at' => now(),
        ]);

        $this->handleUpDown($website, $check);

        if ($isUp) {
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
            ->withOptions(['verify' => $verifySsl])
            // 20s hard ceiling - comfortably above the 15s default response_time_threshold_ms
            // (see the websites table default) so a legitimately slow-but-reachable site
            // (common for shared-hosting government/institutional sites) gets counted as a
            // real, measured "down due to slow response" rather than an opaque connection timeout.
            ->timeout(20)
            ->connectTimeout(10)
            ->retry(1, 300, throw: false);
    }

    protected function handleUpDown(Website $website, WebsiteCheck $check): void
    {
        if (! $check->is_up) {
            $website->increment('consecutive_failures');
            $website->refresh();

            if ($website->last_status !== 'down' && $website->consecutive_failures >= $website->down_threshold_failures) {
                $website->update(['last_status' => 'down']);

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

            $this->notifyAdmins(new WebsiteRecoveredNotification($website, $incident));
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
        app(MailSettings::class)->applyToRuntime();

        Notification::send(User::all(), $notification);
    }
}
