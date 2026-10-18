<?php

namespace App\Services\Monitoring;

use App\Services\Security\OutboundUrlGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a site's TLS certificate straight off the handshake.
 *
 * Connects only to an address OutboundUrlGuard vetted, never through PHP's own
 * resolver: a second lookup here would reopen the DNS-rebinding gap the guard
 * closes for every HTTP request.
 */
class CertificateInspector
{
    /**
     * Expiry, issuer, names and trust of the certificate a URL presents, or null
     * when it cannot be inspected at all (not https, refused by the guard, no vetted address).
     *
     * @return array{reachable: bool, expires_at: ?Carbon, issuer: ?string, names: array<int, string>, chain_valid: ?bool, hostname_match: ?bool, protocol: ?string, error: ?string}|null
     */
    public function inspect(string $url): ?array
    {
        if (! $target = $this->target($url)) {
            return null;
        }

        [$host, $ip, $port] = $target;

        // Handshake 1: no verification, so a broken certificate is still read.
        $first = $this->handshake($ip, $port, $host, ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]);
        $cert = $first['cert'];

        if (! $cert) {
            return [
                'reachable' => false,
                'expires_at' => null,
                'issuer' => null,
                'names' => [],
                'chain_valid' => null,
                'hostname_match' => null,
                'protocol' => $first['protocol'],
                'error' => $first['error'] ?? 'Sertifikat tidak dapat dibaca.',
            ];
        }

        $names = $this->subjectAltNames($cert);

        // Outside its validity window verification fails on the date, which says nothing about the chain.
        $outOfWindow = (isset($cert['validTo_time_t']) && $cert['validTo_time_t'] < time())
            || (isset($cert['validFrom_time_t']) && $cert['validFrom_time_t'] > time());
        [$chainValid, $chainError] = $outOfWindow ? [null, null] : $this->verifyChain($ip, $port, $host);

        return [
            'reachable' => true,
            'expires_at' => isset($cert['validTo_time_t']) ? Carbon::createFromTimestamp($cert['validTo_time_t'], config('app.timezone')) : null,
            'issuer' => $this->issuer($cert),
            'names' => $names,
            'chain_valid' => $chainValid,
            'hostname_match' => $this->matchesHost($host, $names),
            'protocol' => $first['protocol'],
            'error' => $chainError,
        ];
    }

    /** The negotiated protocol (e.g. "TLSv1.3") from one pinned, unverified handshake. */
    public function tlsVersion(string $url): ?string
    {
        if (! $target = $this->target($url)) {
            return null;
        }

        [$host, $ip, $port] = $target;

        return $this->handshake($ip, $port, $host, ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true])['protocol'];
    }

    /**
     * Whether any presented name covers the host. RFC 6125: a wildcard stands for
     * exactly one whole left-most label, and never for an IP address.
     *
     * @param  array<int, string>  $names
     */
    public function matchesHost(string $host, array $names): bool
    {
        $host = rtrim(strtolower(trim($host, '[]')), '.');
        $hostIp = filter_var($host, FILTER_VALIDATE_IP) ? inet_pton($host) : null;

        foreach ($names as $name) {
            $name = rtrim(strtolower(trim($name)), '.');

            if ($hostIp !== null) {
                if (filter_var($name, FILTER_VALIDATE_IP) && inet_pton($name) === $hostIp) {
                    return true;
                }

                continue;
            }

            if ($name === $host) {
                return true;
            }

            // "*.example.com": at least two labels after the wildcard, so "*.com" never matches.
            if (str_starts_with($name, '*.') && substr_count($name, '.') >= 2) {
                $suffix = substr($name, 1);
                $label = str_ends_with($host, $suffix) ? substr($host, 0, -strlen($suffix)) : '';

                if ($label !== '' && ! str_contains($label, '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * One TLS handshake against a pinned IP, with SNI on the hostname. The only
     * place a socket is opened - tests override this.
     *
     * @return array{connected: bool, cert: ?array, protocol: ?string, error: ?string}
     */
    protected function handshake(string $ip, int $port, string $host, array $ssl): array
    {
        $errors = [];
        $address = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        $timeout = (int) config('monitoring.ssl.timeout', 5);

        set_error_handler(function (int $level, string $message) use (&$errors): bool {
            $errors[] = $message;

            return true;
        });

        try {
            $context = stream_context_create(['ssl' => $ssl + ['peer_name' => $host, 'SNI_enabled' => true]]);
            $client = stream_socket_client("ssl://{$address}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

            if (! $client) {
                if ($errors === []) {
                    $errors[] = $errstr ?: "Koneksi TLS gagal (errno {$errno}).";
                }

                return ['connected' => false, 'cert' => null, 'protocol' => null, 'error' => $this->errorText($errors)];
            }

            stream_set_timeout($client, $timeout);
            $params = stream_context_get_params($client);
            $meta = stream_get_meta_data($client);
            fclose($client);

            $peer = $params['options']['ssl']['peer_certificate'] ?? null;

            return [
                'connected' => true,
                'cert' => $peer ? (openssl_x509_parse($peer) ?: null) : null,
                'protocol' => $meta['crypto']['protocol'] ?? null,
                'error' => $errors ? $this->errorText($errors) : null,
            ];
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();

            return ['connected' => false, 'cert' => null, 'protocol' => null, 'error' => $this->errorText($errors)];
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Handshake 2: chain trust only (the name is judged locally). Null when it
     * cannot be judged - no readable CA bundle/dir, or the connection itself failed.
     *
     * @return array{0: ?bool, 1: ?string}
     */
    protected function verifyChain(string $ip, int $port, string $host): array
    {
        $cafile = config('monitoring.ssl.cafile');
        $capath = function_exists('openssl_get_cert_locations') ? (openssl_get_cert_locations()['default_cert_dir'] ?? null) : null;

        $trust = match (true) {
            $cafile && is_readable($cafile) => ['cafile' => $cafile],
            $capath && is_dir($capath) && is_readable($capath) => ['capath' => $capath],
            // Without CA certificates every chain would "fail": unknown, not invalid.
            default => null,
        };

        if ($trust === null) {
            return [null, null];
        }

        $result = $this->handshake($ip, $port, $host, ['verify_peer' => true, 'verify_peer_name' => false, 'allow_self_signed' => false] + $trust);

        if ($result['connected']) {
            return [true, null];
        }

        return str_contains(strtolower((string) $result['error']), 'certificate verify failed')
            ? [false, $result['error']]
            : [null, null];
    }

    /** [ascii host, vetted IP (IPv4 preferred), port], or null when nothing vetted to connect to. */
    protected function target(string $url): ?array
    {
        $rawHost = parse_url($url, PHP_URL_HOST);

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || ! $rawHost || OutboundUrlGuard::reason($url)) {
            return null;
        }

        $host = trim(strtolower($rawHost), '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } else {
            $addresses = OutboundUrlGuard::addressesFor(strtolower($rawHost))[1];
            $ip = collect($addresses)->first(fn (string $a) => (bool) filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
                ?? ($addresses[0] ?? null);
        }

        if ($ip === null) {
            return null;
        }

        // SANs carry the punycode form.
        if (! filter_var($host, FILTER_VALIDATE_IP) && preg_match('/[^\x20-\x7e]/', $host) && function_exists('idn_to_ascii')) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
        }

        return [$host, $ip, (int) (parse_url($url, PHP_URL_PORT) ?? 443)];
    }

    /** DNS and IP entries of the subjectAltName extension, lowercased. */
    protected function subjectAltNames(array $cert): array
    {
        $raw = $cert['extensions']['subjectAltName'] ?? '';

        return collect(explode(',', (string) $raw))
            // Certificate bytes are not guaranteed UTF-8; the JSON casts are.
            ->map(fn (string $entry) => trim(mb_scrub($entry, 'UTF-8')))
            ->map(fn (string $entry) => match (true) {
                str_starts_with($entry, 'DNS:') => strtolower(substr($entry, 4)),
                str_starts_with($entry, 'IP Address:') => strtolower(substr($entry, 11)),
                default => null,
            })
            // Hostname (optional leading "*.") or IP only, max 253 chars: drops names smuggling whitespace or separators.
            ->filter(fn (?string $name) => $name !== null && strlen($name) <= 253
                && (filter_var($name, FILTER_VALIDATE_IP) || preg_match('/^(\*\.)?[a-z0-9_-]+(\.[a-z0-9_-]+)*\.?$/', $name)))
            ->unique()
            // Attacker-controlled size: a cert can carry thousands of SANs.
            ->take(100)
            ->values()
            ->all();
    }

    /** "CN (O)", whichever parts the issuer carries. */
    protected function issuer(array $cert): ?string
    {
        $part = fn (string $key) => is_array($cert['issuer'][$key] ?? null)
            ? implode(', ', $cert['issuer'][$key])
            : ($cert['issuer'][$key] ?? null);

        $cn = $part('CN');
        $org = $part('O');

        [$cn, $org] = [$cn ? mb_scrub($cn, 'UTF-8') : $cn, $org ? mb_scrub($org, 'UTF-8') : $org];

        $issuer = match (true) {
            $cn && $org && $cn !== $org => "{$cn} ({$org})",
            default => $cn ?: $org,
        };

        return $issuer ? Str::limit($issuer, 250) : null;
    }

    /** Collected warnings as one line, without PHP's function-name prefix. */
    protected function errorText(array $errors): string
    {
        $lines = collect($errors)
            ->map(fn (string $message) => trim((string) preg_replace(['/^[a-z_]+\(\):\s*/i', '/\s+/'], ['', ' '], mb_scrub($message, 'UTF-8'))))
            // Generic follow-ups that also carry the pinned IP; the OpenSSL line says why.
            ->reject(fn (string $line) => count($errors) > 1 && preg_match('/^(Unable to connect to|Failed to enable crypto)/i', $line))
            ->filter()
            ->unique()
            ->implode('; ');

        return Str::limit($lines, 250);
    }
}
