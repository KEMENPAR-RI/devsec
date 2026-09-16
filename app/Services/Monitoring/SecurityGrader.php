<?php

namespace App\Services\Monitoring;

use App\Models\Website;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Scores how well a monitored site defends its own visitors.
 *
 * Everything measured here arrives on a response this app already fetches, or
 * from the TLS handshake it already opens for certificate expiry - so the whole
 * grade costs one request a day per site.
 *
 * Informational only: no incident is raised from a grade. Headers change about
 * once a year, and a nightly "still a C" alert teaches people to ignore alerts.
 * The value is the remediation list, which is exactly what an institution needs
 * in a report.
 */
class SecurityGrader
{
    /**
     * The checks, in the order they are shown, with the weight each carries.
     *
     * HTTPS and TLS version dominate on purpose. A missing Referrer-Policy is
     * untidy; plain HTTP means every password on the site is readable by anyone
     * on the same network, and no amount of headers makes up for it.
     */
    public const CHECKS = [
        'https' => ['label' => 'HTTPS', 'weight' => 25],
        'tls_version' => ['label' => 'Versi TLS', 'weight' => 15],
        'hsts' => ['label' => 'HSTS (Strict-Transport-Security)', 'weight' => 15],
        'csp' => ['label' => 'Content-Security-Policy', 'weight' => 15],
        'nosniff' => ['label' => 'X-Content-Type-Options', 'weight' => 8],
        'frame' => ['label' => 'Proteksi clickjacking', 'weight' => 8],
        'referrer' => ['label' => 'Referrer-Policy', 'weight' => 7],
        'permissions' => ['label' => 'Permissions-Policy', 'weight' => 7],
    ];

    /**
     * Grades one site, returning null when it could not be reached at all.
     *
     * A site that is simply down gets no grade rather than an F: "we could not
     * look" and "it is badly configured" are different statements, and recording
     * the second when the first is true would put a scary letter next to a site
     * whose only problem was a timeout.
     *
     * @return array{score: int, grade: string, findings: array<int, array{key: string, label: string, ok: bool, note: string}>}|null
     */
    public function grade(Website $website): ?array
    {
        if (OutboundUrlGuard::reason($website->url)) {
            return null;
        }

        $headers = $this->fetchHeaders($website->url);

        if ($headers === null) {
            return null;
        }

        $isHttps = str_starts_with(strtolower($website->url), 'https://');
        $tlsVersion = $isHttps ? $this->tlsVersion($website->url) : null;

        $results = [
            'https' => $isHttps
                ? [true, 'Situs diakses melalui HTTPS.']
                : [false, 'Situs masih melayani HTTP polos - seluruh isian formulir terbaca di jaringan yang sama.'],

            'tls_version' => match (true) {
                ! $isHttps => [false, 'Tidak ada TLS karena situs belum memakai HTTPS.'],
                $tlsVersion === null => [false, 'Versi TLS tidak dapat dibaca dari sini.'],
                in_array($tlsVersion, ['TLSv1.2', 'TLSv1.3'], true) => [true, "Handshake memakai {$tlsVersion}."],
                default => [false, "Handshake memakai {$tlsVersion}, yang sudah usang. Minimal TLS 1.2."],
            },

            'hsts' => $this->gradeHsts($headers, $isHttps),

            'csp' => $this->has($headers, 'content-security-policy')
                ? [true, 'Content-Security-Policy dikirim.']
                : [false, 'Tidak ada Content-Security-Policy - skrip yang berhasil disuntikkan bebas memuat apa pun.'],

            'nosniff' => $this->headerIs($headers, 'x-content-type-options', 'nosniff')
                ? [true, 'X-Content-Type-Options: nosniff dikirim.']
                : [false, 'Tanpa nosniff, berkas unggahan bisa dieksekusi browser sebagai skrip.'],

            'frame' => $this->gradeFrameProtection($headers),

            'referrer' => $this->has($headers, 'referrer-policy')
                ? [true, 'Referrer-Policy dikirim.']
                : [false, 'Tanpa Referrer-Policy, URL lengkap ikut terkirim ke situs pihak ketiga.'],

            'permissions' => $this->has($headers, 'permissions-policy')
                ? [true, 'Permissions-Policy dikirim.']
                : [false, 'Tanpa Permissions-Policy, skrip yang disuntikkan bisa meminta kamera atau lokasi.'],
        ];

        $score = 0;
        $findings = [];

        foreach (self::CHECKS as $key => $check) {
            [$ok, $note] = $results[$key];

            $score += $ok ? $check['weight'] : 0;
            $findings[] = ['key' => $key, 'label' => $check['label'], 'ok' => $ok, 'note' => $note];
        }

        return ['score' => $score, 'grade' => $this->letter($score), 'findings' => $findings];
    }

    /**
     * A max-age under a year is treated as not set.
     *
     * A short HSTS window is not a smaller version of the protection - the whole
     * guarantee is that the browser refuses plain HTTP for a long time, and a
     * one-day policy leaves the same redirect window open every other day.
     */
    protected function gradeHsts(array $headers, bool $isHttps): array
    {
        if (! $isHttps) {
            return [false, 'HSTS tidak berlaku selama situs belum HTTPS.'];
        }

        $value = $this->header($headers, 'strict-transport-security');

        if (! $value) {
            return [false, 'Tidak ada HSTS - pengunjung masih bisa diarahkan ke versi HTTP.'];
        }

        preg_match('/max-age\s*=\s*(\d+)/i', $value, $matches);
        $maxAge = (int) ($matches[1] ?? 0);

        return $maxAge >= 31536000
            ? [true, 'HSTS aktif dengan max-age minimal satu tahun.']
            : [false, "HSTS ada tetapi max-age hanya {$maxAge} detik. Setel minimal 31536000 (satu tahun)."];
    }

    /** Either header does the job; CSP frame-ancestors is the modern one. */
    protected function gradeFrameProtection(array $headers): array
    {
        if ($this->has($headers, 'x-frame-options')) {
            return [true, 'X-Frame-Options dikirim.'];
        }

        if (Str::contains(Str::lower((string) $this->header($headers, 'content-security-policy')), 'frame-ancestors')) {
            return [true, 'CSP frame-ancestors membatasi siapa yang boleh membingkai halaman.'];
        }

        return [false, 'Halaman bisa dibingkai situs lain - membuka peluang clickjacking.'];
    }

    /** A..F, with the boundaries picked so a site missing only the minor headers still reads as a B. */
    public function letter(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 78 => 'B',
            $score >= 60 => 'C',
            $score >= 40 => 'D',
            $score >= 20 => 'E',
            default => 'F',
        };
    }

    /**
     * Response headers, lowercased, or null when the site did not answer.
     *
     * @return array<string, string>|null
     */
    protected function fetchHeaders(string $url): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => JudolScanner::BROWSER_USER_AGENT])
                ->timeout(15)
                ->connectTimeout(10)
                ->withOptions(OutboundUrlGuard::httpOptions())
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        // A 403 from a WAF still carries the site's headers, which is what is
        // being graded - only a total non-answer is a miss.
        return collect($response->headers())
            ->mapWithKeys(fn (array $values, string $name) => [Str::lower($name) => implode(', ', $values)])
            ->all();
    }

    /**
     * The negotiated TLS protocol, e.g. "TLSv1.3".
     *
     * Read from the handshake rather than guessed: a server can advertise modern
     * ciphers and still accept TLS 1.0 from anything that asks for it.
     */
    protected function tlsVersion(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT) ?? 443;

        try {
            $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
            $client = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);

            if (! $client) {
                return null;
            }

            $meta = stream_get_meta_data($client);
            fclose($client);

            return $meta['crypto']['protocol'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function header(array $headers, string $name): ?string
    {
        return $headers[$name] ?? null;
    }

    protected function has(array $headers, string $name): bool
    {
        return filled($this->header($headers, $name));
    }

    protected function headerIs(array $headers, string $name, string $expected): bool
    {
        return Str::lower(trim((string) $this->header($headers, $name))) === $expected;
    }
}
