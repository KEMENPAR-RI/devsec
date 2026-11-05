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
use Carbon\CarbonInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Utils as GuzzleUtils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Notifications\Notification as NotificationClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * Probes a website over HTTP and records the result, opening or resolving
 * incidents for downtime and response-time / content anomalies along the way.
 */
class WebsiteChecker
{
    public const BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    // Body bytes kept per response; only hashed and measured here, so 2 MB is plenty.
    public const MAX_BODY_BYTES = 2 * 1024 * 1024;

    // Slack on top of the per-attempt timeout shared by retries and the SSL fallback.
    public const DEADLINE_MARGIN_SECONDS = 5;

    // Less time than this left is not worth another attempt.
    public const MIN_ATTEMPT_SECONDS = 5;

    // cURL errnos for TLS/certificate failures: 35, 51, 58, 60, 77, 83.
    public const TLS_ERRNOS = [35, 51, 58, 60, 77, 83];

    // Transfer time of the current attempt (summed over its redirect hops), from handler stats.
    protected ?float $transferSeconds = null;

    // End of the current check's total budget, across retries and the SSL fallback.
    protected ?CarbonInterface $deadline = null;

    // Shared across one run so RDAP lookups are deduped per apex.
    protected ?DomainExpiry $domainExpiry = null;

    public function check(Website $website): WebsiteCheck
    {
        $startedAt = now();
        $start = microtime(true);
        $this->transferSeconds = null;

        $isUp = false;
        $statusCode = null;
        $contentHash = null;
        $contentLength = null;
        $errorMessage = null;
        $errorType = null;

        try {
            $response = $this->fetchWithSslFallback($website->url, $this->timeoutFor($website));

            $statusCode = $response->status();
            $body = $response->body();
            $contentLength = strlen($body);
            $contentHash = sha1($body);
            $isUp = $response->successful() || $response->redirect();

            if (! $isUp) {
                $errorMessage = "HTTP {$statusCode}";
                $errorType = 'http';
            }
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
            $errorType = $this->errorTypeFor($e);
        }

        // Final attempt only; wall clock is the fallback when no stats exist (faked client).
        $responseTimeMs = (int) round(($this->transferSeconds ?? (microtime(true) - $start)) * 1000);

        // A response that arrives past the site's own acceptable-latency threshold is
        // treated the same as no response at all: for practical purposes the site is
        // "down" for whoever is waiting on it, so it counts toward down_threshold_failures
        // and the downtime incident flow the same way an HTTP failure does.
        if ($isUp && $responseTimeMs > $website->response_time_threshold_ms) {
            $isUp = false;
            $errorMessage = "Response time {$responseTimeMs}ms melebihi ambang batas {$website->response_time_threshold_ms}ms";
            $errorType = 'slow';
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
            'error_type' => $errorType,
            'checked_at' => now(),
        ]);

        // last_status still tracks reality during a window, so the moment it ends
        // the site is already in the right state rather than one check behind.
        $this->handleUpDown($website, $check, $underMaintenance);

        if ($isUp && ! $underMaintenance) {
            $this->detectResponseTimeAnomaly($website, $check);
            $this->detectContentAnomaly($website, $check);
        }

        // Also when the handshake itself failed: the inspector says why.
        if (($isUp || $errorType === 'ssl') && ! $underMaintenance) {
            // Must not cost the check its last_checked_at (bad cert encoding, SMTP down).
            try {
                $this->maybeCheckSsl($website);
            } catch (Throwable $e) {
                Log::warning("WebsiteChecker: cek SSL gagal untuk {$website->url}: {$e->getMessage()}");
            }
        }

        // A failed lookup is no drift evidence, and retrying it every minute is just resolver load.
        if (! $underMaintenance && $errorType !== 'dns') {
            $this->detectIpDrift($website);
        }

        // Start time, not end time, so the check interval does not drift by the probe's duration.
        $website->update(['last_checked_at' => $startedAt]);

        return $check;
    }

    /**
     * Fetches a URL, retrying once without SSL verification if the first attempt
     * fails on a certificate/chain problem - a handful of hosts present a chain
     * curl doesn't trust yet (e.g. a newer intermediate CA) even though real
     * browsers accept it fine, which was showing up as false "down" alerts.
     *
     * @throws Throwable when both the initial attempt and (if applicable) the
     *                   no-verify retry fail
     */
    protected function fetchWithSslFallback(string $url, int $timeout = 20)
    {
        // Started before the guard: its DNS lookup counts against the check's budget.
        $this->deadline = now()->addSeconds($timeout + self::DEADLINE_MARGIN_SECONDS);

        // Refused before the socket opens: a stored URL can point somewhere it was
        // never allowed to (added before this guard existed, or repointed by DNS).
        if ($reason = OutboundUrlGuard::reason($url)) {
            throw new RuntimeException($reason);
        }

        try {
            return $this->httpClient(timeout: $timeout)->get($url);
        } catch (Throwable $e) {
            // errno, not message text: the message also carries the URL. Skipped once the budget is spent.
            $remaining = $this->remainingSeconds();

            if (str_starts_with($url, 'https://') && in_array($this->curlErrno($e), self::TLS_ERRNOS, true) && $remaining >= self::MIN_ATTEMPT_SECONDS) {
                $this->transferSeconds = null;
                $response = $this->httpClient(verifySsl: false, timeout: min($timeout, $remaining))->get($url);
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
    protected function httpClient(bool $verifySsl = true, int|float $timeout = 20): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => self::BROWSER_USER_AGENT,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
        ])
            // httpOptions() caps redirects and re-checks each hop's address, so a
            // 302 cannot land somewhere the guard above already refused.
            ->withOptions([
                'verify' => $verifySsl,
                'on_stats' => function (TransferStats $stats): void {
                    // Faked responses carry no transfer time; check() then falls back to the wall clock.
                    if ($stats->getTransferTime() !== null) {
                        $this->transferSeconds = ($this->transferSeconds ?? 0.0) + $stats->getTransferTime();
                    }
                },
            ] + OutboundUrlGuard::httpOptions())
            ->setHandler($this->cappedBodyHandler())
            // Derived from the site's threshold (see timeoutFor()) so a slow-but-reachable
            // site is measured as "slow" rather than cut off as an opaque timeout.
            ->timeout($timeout)
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
            //
            // A timeout is not retried: it already waited the full budget, and the
            // next scheduled check (one minute later while unconfirmed) verifies it.
            // Nor is anything retried once the check's total budget is spent.
            // A TLS error is deterministic: its budget goes to the no-verify fallback instead.
            // Laravel passes null for a 3xx without Location (e.g. 304): that is a response, not a failure.
            ->retry(2, function (): int {
                $this->transferSeconds = null;

                return 300;
            }, fn (?Throwable $e, PendingRequest $request): bool => $e !== null
                && ! $this->isTimeout($e)
                && ! in_array($this->curlErrno($e), self::TLS_ERRNOS, true)
                && $this->fitsBudget($request, $timeout), throw: false);
    }

    /** Whether another attempt fits the check's deadline; if so, caps its timeout to the time left. */
    protected function fitsBudget(PendingRequest $request, int|float $timeout): bool
    {
        $remaining = $this->remainingSeconds();

        if ($remaining < self::MIN_ATTEMPT_SECONDS) {
            return false;
        }

        $request->timeout(min($timeout, $remaining));

        return true;
    }

    /** Seconds left before the current check's deadline; unbounded outside a check. */
    protected function remainingSeconds(): float
    {
        return $this->deadline ? now()->diffInMilliseconds($this->deadline) / 1000 : INF;
    }

    /** cURL errno from the underlying Guzzle exception, if any. */
    protected function curlErrno(Throwable $e): ?int
    {
        for ($current = $e; $current; $current = $current->getPrevious()) {
            if ($current instanceof ConnectException || $current instanceof RequestException) {
                $errno = $current->getHandlerContext()['errno'] ?? null;

                return is_numeric($errno) ? (int) $errno : null;
            }
        }

        return null;
    }

    /** clamp(threshold + 5s, 10s, 30s): long enough to measure "slow", short enough for the worker. */
    protected function timeoutFor(Website $website): int
    {
        return (int) min(30, max(10, ceil($website->response_time_threshold_ms / 1000) + 5));
    }

    /**
     * The default Guzzle handler, but each response body is buffered only up to
     * MAX_BODY_BYTES: the rest is acknowledged and dropped, so curl still finishes
     * normally and a huge page cannot exhaust the worker's memory. Below
     * RedirectMiddleware, so every hop is also pinned to its vetted IPs.
     */
    protected function cappedBodyHandler(?callable $handler = null): callable
    {
        // $handler only for tests that must reach a local server.
        $handler ??= OutboundUrlGuard::pinningHandler(GuzzleUtils::chooseHandler());

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

    protected function isTimeout(Throwable $e): bool
    {
        // errno first: the message also carries the URL; text only when no errno exists (e.g. faked).
        if ($errno = $this->curlErrno($e)) {
            return $errno === 28;
        }

        return str_contains($e->getMessage(), 'cURL error 28') || Str::contains(Str::lower($e->getMessage()), 'timed out');
    }

    /** Coarse failure class for website_checks.error_type, read off the transport error. */
    protected function errorTypeFor(Throwable $e): string
    {
        // OutboundUrlGuard refusals (up front or on a redirect hop) are bare RuntimeExceptions.
        if ($e::class === RuntimeException::class) {
            return 'blocked';
        }

        if ($errno = $this->curlErrno($e)) {
            return match (true) {
                $errno === 28 => 'timeout',
                $errno === 6 => 'dns',
                in_array($errno, self::TLS_ERRNOS, true) => 'ssl',
                default => 'connect',
            };
        }

        $message = Str::lower($e->getMessage());

        return match (true) {
            $this->isTimeout($e) => 'timeout',
            Str::contains($message, ['curl error 6:', 'could not resolve']) => 'dns',
            Str::contains($message, ['ssl', 'certificate', 'tls']) => 'ssl',
            default => 'connect',
        };
    }

    protected function handleUpDown(Website $website, WebsiteCheck $check, bool $underMaintenance = false): void
    {
        if (! $check->is_up) {
            $website->increment('consecutive_failures');
            $website->refresh();

            if ($website->consecutive_failures >= $website->down_threshold_failures) {
                $wasDown = $website->last_status === 'down';

                if (! $wasDown) {
                    $website->update(['last_status' => 'down']);
                }

                // Scheduled: the state is recorded, but nobody is woken up for an
                // outage they arranged themselves.
                if ($underMaintenance) {
                    return;
                }

                // Already down: an offline-paused site is left alone so a deploy cannot flood admins.
                if ($wasDown && $website->isPausedForOffline()) {
                    return;
                }

                $firstFailedAt = $this->streakStartedAt($website, $check);

                // Already down: only an outage with no incident yet (it began in a maintenance
                // window) gets one; an open or hand-resolved incident of this streak is enough.
                if ($wasDown && $website->incidents()
                    ->where('type', 'downtime')
                    ->where(fn ($q) => $q->open()->orWhere('detected_at', '>=', $firstFailedAt))
                    ->exists()) {
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
                        'first_failed_at' => $firstFailedAt->toIso8601String(),
                        'consecutive_failures' => $website->consecutive_failures,
                        'status_code' => $check->status_code,
                        'error_message' => $check->error_message,
                        'error_type' => $check->error_type,
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

            // No incident resolved means nobody was told about an outage: a "pulih" email is noise.
            if ($incident) {
                $incident->resolve();

                if (! $underMaintenance) {
                    $this->notifyAdmins(new WebsiteRecoveredNotification($website, $incident));
                }
            }
        } elseif ($website->consecutive_failures > 0) {
            $website->update(['consecutive_failures' => 0, 'last_status' => 'up']);
        } elseif ($website->last_status === 'unknown') {
            $website->update(['last_status' => 'up']);
        }
    }

    /** When the current failure streak began: the oldest of the last consecutive_failures checks. */
    protected function streakStartedAt(Website $website, WebsiteCheck $check): CarbonInterface
    {
        $checkedAt = $website->checks()
            ->latest('checked_at')
            ->latest('id')
            ->offset(max($website->consecutive_failures - 1, 0))
            ->value('checked_at');

        return $checkedAt ? Carbon::parse($checkedAt) : $check->checked_at;
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
            ->pluck('response_time_ms')
            ->avg();

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

        $cert = $this->inspectCertificate($website->url);

        if ($cert === null) {
            return;
        }

        // Unreachable handshake: keep the last known certificate, record only why.
        if (! $cert['reachable']) {
            $website->update(['ssl_error' => Str::limit((string) $cert['error'], 250)]);

            return;
        }

        $website->update([
            'ssl_expires_at' => $cert['expires_at'],
            'ssl_issuer' => $cert['issuer'],
            'ssl_names' => $cert['names'],
            'ssl_chain_valid' => $cert['chain_valid'],
            'ssl_hostname_match' => $cert['hostname_match'],
            'ssl_error' => $cert['error'] ? Str::limit($cert['error'], 250) : null,
        ]);

        $problems = $this->sslProblems($website, $cert);
        $open = $website->incidents()->where('type', 'ssl')->open()->latest('detected_at')->get();

        // expiring and expired are one problem at two stages; legacy rows carry no reason.
        $group = fn (?string $reason) => in_array($reason ?? 'expiring', ['expiring', 'expired'], true) ? 'expiry' : $reason;

        // Each problem stands alone: one that is fixed closes even while another persists.
        $open->reject(fn (Incident $incident) => isset($problems[$group($incident->meta['reason'] ?? null)]))
            ->each(fn (Incident $incident) => $incident->resolve());

        foreach ($problems as $key => $problem) {
            $existing = $open->first(fn (Incident $incident) => $group($incident->meta['reason'] ?? null) === $key);

            if (! $existing) {
                $incident = Incident::create([
                    'website_id' => $website->id,
                    'type' => 'ssl',
                    'severity' => $problem['severity'],
                    'title' => $problem['title'],
                    'description' => $problem['description'],
                    'meta' => $problem['meta'],
                    'status' => 'open',
                    'detected_at' => now(),
                ]);

                // Nobody finds out about a certificate problem by the site going down - by then it already has.
                $this->notifyAdmins(new SslExpiringNotification($website, $incident));

                continue;
            }

            // Crossing the critical threshold re-alerts once.
            if ($existing->severity === 'warning' && $problem['severity'] === 'critical' && empty($existing->meta['escalated'])) {
                $existing->update([
                    'severity' => 'critical',
                    'title' => $problem['title'],
                    'description' => $problem['description'],
                    'meta' => [...($existing->meta ?? []), ...$problem['meta'], 'escalated' => true],
                ]);

                $this->notifyAdmins(new SslExpiringNotification($website, $existing));
            } elseif (($existing->meta['reason'] ?? 'expiring') !== $problem['meta']['reason']) {
                // Same problem, later stage (expiring -> expired): keep the record current, no second email.
                $existing->update([
                    'title' => $problem['title'],
                    'description' => $problem['description'],
                    'meta' => [...($existing->meta ?? []), ...$problem['meta']],
                ]);
            }
        }
    }

    /** Test seam: the certificate as CertificateInspector::inspect() reports it. */
    protected function inspectCertificate(string $url): ?array
    {
        return app(CertificateInspector::class)->inspect($url);
    }

    /**
     * Everything wrong with a certificate, keyed by problem group (expiry, hostname,
     * chain); empty when healthy. Days are calendar days in the app timezone.
     *
     * @return array<string, array{severity: string, title: string, description: string, meta: array<string, mixed>}>
     */
    protected function sslProblems(Website $website, array $cert): array
    {
        $tz = config('app.timezone');
        $expiresAt = $cert['expires_at']?->copy()->setTimezone($tz);
        $host = parse_url($website->url, PHP_URL_HOST);
        $days = $expiresAt ? (int) now($tz)->startOfDay()->diffInDays($expiresAt->copy()->startOfDay(), false) : null;
        $date = $expiresAt?->translatedFormat('d F Y');
        $found = [];

        $expiry = match (true) {
            $expiresAt && $expiresAt->isPast() => ['critical', 'expired',
                "Sertifikat SSL {$website->name} sudah kedaluwarsa",
                "Sertifikat SSL kedaluwarsa sejak {$date}. Browser menolak membuka situs ini."],
            $days !== null && $days <= (int) config('monitoring.ssl.critical_days', 3) => ['critical', 'expiring',
                "Sertifikat SSL {$website->name} akan kedaluwarsa",
                "Sertifikat SSL akan kedaluwarsa pada {$date}."],
            $days !== null && $days <= (int) config('monitoring.ssl.warning_days', 14) => ['warning', 'expiring',
                "Sertifikat SSL {$website->name} akan kedaluwarsa",
                "Sertifikat SSL akan kedaluwarsa pada {$date}."],
            default => null,
        };

        if ($expiry) {
            $found['expiry'] = $expiry;
        }

        if ($cert['hostname_match'] === false) {
            $found['hostname'] = ['critical', 'hostname',
                "Sertifikat SSL {$website->name} tidak cocok dengan nama host",
                $cert['names']
                    ? "Sertifikat tidak berlaku untuk {$host} (berlaku untuk: ".Str::limit(implode(', ', $cert['names']), 200).').'
                    : "Sertifikat tidak memuat Subject Alternative Name (SAN), sehingga browser menolaknya untuk {$host}."];
        }

        if ($cert['chain_valid'] === false) {
            $found['chain'] = ['warning', 'chain',
                "Rantai sertifikat SSL {$website->name} tidak valid",
                'Rantai sertifikat tidak dapat diverifikasi ke CA tepercaya (intermediate tidak lengkap atau penerbit tidak dikenal).'];
        }

        return array_map(fn (array $problem) => [
            'severity' => $problem[0],
            'title' => $problem[2],
            'description' => $problem[3],
            'meta' => array_filter([
                'reason' => $problem[1],
                'expires_at' => $expiresAt?->toIso8601String(),
                'issuer' => $cert['issuer'],
                'host' => $host,
                'names' => $cert['names'],
                'chain_valid' => $cert['chain_valid'],
                'hostname_match' => $cert['hostname_match'],
                'error' => $cert['error'],
            ], fn ($value) => $value !== null),
        ], $found);
    }

    /**
     * Pulls the daily DNS check forward when the addresses the guard just resolved
     * differ from the stored A/AAAA baseline - throttled to once an hour.
     * Skipped for CDN/load-balanced sites, whose addresses rotate by design.
     */
    protected function detectIpDrift(Website $website): void
    {
        $baseline = $website->dns_records ?? [];
        $host = parse_url($website->url, PHP_URL_HOST);

        if (! $host || $website->dns_ip_rotates || (! isset($baseline['A']) && ! isset($baseline['AAAA']))) {
            return;
        }

        if ($website->dns_ip_drift_at && $website->dns_ip_drift_at->isAfter(now()->subHour())) {
            return;
        }

        // Memoized by the guard during this check's fetch: no extra lookup.
        [$reason, $addresses] = OutboundUrlGuard::addressesFor(strtolower($host));

        if ($reason !== null || $addresses === []) {
            return;
        }

        // Same normalisation as the stored baseline (mask, sort, cap), or a >MAX_VALUES set drifts forever.
        $monitor = app(DnsMonitor::class);
        $v4 = $monitor->normalize('A', array_filter($addresses, fn (string $ip) => (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
        $v6 = $monitor->normalize('AAAA', array_filter($addresses, fn (string $ip) => (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)));

        // A family the resolver did not answer for is no evidence of drift.
        $drifted = (isset($baseline['A']) && $v4 !== [] && $v4 !== $baseline['A'])
            || (isset($baseline['AAAA']) && $v6 !== [] && $v6 !== $baseline['AAAA']);

        if (! $drifted) {
            return;
        }

        $website->update(['dns_ip_drift_at' => now()]);

        try {
            $this->checkDns($website, force: true);
        } catch (Throwable $e) {
            Log::warning("WebsiteChecker: cek DNS dini gagal untuk {$website->url}: {$e->getMessage()}");
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
    public function checkDns(Website $website, bool $force = false): void
    {
        if (! $force && $website->dns_checked_at && $website->dns_checked_at->isAfter(now()->subDay())) {
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

        // Pre-apex baseline: its NS/MX were read off the host, still comparable only when host is the apex.
        if (! isset($previous['apex']) && ($current['apex'] ?? null) === [strtolower($host)]) {
            $previous['apex'] = $current['apex'];
        }

        $changes = $monitor->diff($previous, $current);

        if ($changes === []) {
            return;
        }

        $severity = $monitor->severityFor($changes, (bool) $website->dns_ip_rotates);
        $open = $website->incidents()->where('type', 'dns')->open()->latest('detected_at')->first();

        // Already open: record the new change on it rather than lose it with the overwritten baseline.
        if ($open) {
            $this->appendDnsChange($website, $open, $changes, $severity);

            return;
        }

        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'dns',
            'severity' => $severity,
            'title' => "Record DNS {$website->name} berubah",
            'description' => 'Record '.implode(', ', array_keys($changes)).' berbeda dari yang terakhir tercatat.',
            'meta' => ['changes' => $changes],
            'status' => 'open',
            'detected_at' => now(),
        ]);

        $this->notifyAdmins(new DnsChangedNotification($website, $incident));
    }

    /**
     * Adds a later change to an open DNS incident: meta.history keeps each event
     * (last 20), meta.changes stays the cumulative original -> latest view.
     * A warning that turns critical (e.g. NS moved after MX) re-alerts once.
     */
    protected function appendDnsChange(Website $website, Incident $incident, array $changes, string $severity): void
    {
        $meta = $incident->meta ?? [];
        $meta['history'] = array_slice([...($meta['history'] ?? []), ['detected_at' => now()->toIso8601String(), 'changes' => $changes]], -20);

        foreach ($changes as $type => $change) {
            $meta['changes'][$type] = ['dari' => $meta['changes'][$type]['dari'] ?? $change['dari'], 'ke' => $change['ke']];
        }

        $escalate = $incident->severity === 'warning' && $severity === 'critical' && empty($meta['escalated']);

        $incident->update([
            'meta' => $escalate ? [...$meta, 'escalated' => true] : $meta,
            'severity' => $escalate ? 'critical' : $incident->severity,
        ]);

        if ($escalate) {
            $this->notifyAdmins(new DnsChangedNotification($website, $incident));
        }
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

        // One instance per checker run, so sites sharing an apex share one RDAP lookup.
        $this->domainExpiry ??= app(DomainExpiry::class);

        if ($found = $this->domainExpiry->lookUp($website->url)) {
            $website->update(['domain_expires_at' => $found, 'domain_expiry_source' => 'rdap']);
        }

        $expiresAt = $website->fresh()->domain_expires_at;

        if (! $expiresAt) {
            return;
        }

        // Renewed (or a corrected date): the warning no longer applies.
        if ($expiresAt->isAfter(now()->addDays((int) config('monitoring.domain.warning_days', 45)))) {
            $website->incidents()->open()->where('type', 'domain')->each(fn (Incident $incident) => $incident->resolve());

            return;
        }

        $days = (int) now()->startOfDay()->diffInDays($expiresAt->copy()->startOfDay(), false);
        // Past the date is not a warning any more - the domain is gone.
        $severity = $expiresAt->isPast() || $days <= (int) config('monitoring.domain.critical_days', 3) ? 'critical' : 'warning';
        $title = $expiresAt->isPast() ? "Domain {$website->name} sudah kedaluwarsa" : "Domain {$website->name} akan kedaluwarsa";
        $description = 'Registrasi domain berakhir pada '.$expiresAt->translatedFormat('d F Y').'.';
        $open = $website->incidents()->open()->where('type', 'domain')->latest('detected_at')->first();

        if ($open) {
            // Crossing the critical threshold re-alerts once; a later stage only refreshes the record.
            if ($open->severity === 'warning' && $severity === 'critical' && empty($open->meta['escalated'])) {
                $open->update([
                    'severity' => 'critical',
                    'title' => $title,
                    'description' => $description,
                    'meta' => [...($open->meta ?? []), 'expires_at' => $expiresAt->toIso8601String(), 'escalated' => true],
                ]);

                $this->notifyAdmins(new DomainExpiringNotification($website, $open));
            } elseif ($open->title !== $title) {
                $open->update(['title' => $title, 'description' => $description]);
            }

            return;
        }

        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'domain',
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
            'meta' => ['expires_at' => $expiresAt->toIso8601String()],
            'status' => 'open',
            'detected_at' => now(),
        ]);

        $this->notifyAdmins(new DomainExpiringNotification($website, $incident));
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
