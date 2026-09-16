<?php

namespace App\Services\Monitoring;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks Google Safe Browsing whether a monitored site is on its blocklist.
 *
 * A listed site is, in practice, as unreachable as one that is down: Chrome puts
 * a full-page red interstitial in front of it and almost nobody clicks through.
 * Nothing else here catches that - the server answers, the certificate is valid,
 * and the content may even be clean again after a cleanup Google has not
 * re-crawled yet.
 *
 * Entirely opt-in. Without an API key this reports "unknown" and never makes a
 * request, so a fresh install behaves exactly as it did before.
 */
class ReputationChecker
{
    protected const ENDPOINT = 'https://safebrowsing.googleapis.com/v4/threatMatches:find';

    /** Everything Safe Browsing will tell us about, in its own vocabulary. */
    protected const THREAT_TYPES = ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'];

    /** Those same types in words an operator can act on. */
    public const THREAT_LABELS = [
        'MALWARE' => 'Malware',
        'SOCIAL_ENGINEERING' => 'Phishing / rekayasa sosial',
        'UNWANTED_SOFTWARE' => 'Perangkat lunak tidak diinginkan',
        'POTENTIALLY_HARMFUL_APPLICATION' => 'Aplikasi berpotensi berbahaya',
    ];

    public function isConfigured(): bool
    {
        return filled($this->apiKey());
    }

    /**
     * @return array{status: string, threats: array<int, string>}
     */
    public function check(string $url): array
    {
        if (! $this->isConfigured()) {
            return ['status' => 'unknown', 'threats' => []];
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->post(self::ENDPOINT.'?key='.urlencode((string) $this->apiKey()), [
                    'client' => ['clientId' => 'magnas-hub-monitor', 'clientVersion' => '1.0'],
                    'threatInfo' => [
                        'threatTypes' => self::THREAT_TYPES,
                        'platformTypes' => ['ANY_PLATFORM'],
                        'threatEntryTypes' => ['URL'],
                        'threatEntries' => [['url' => $url]],
                    ],
                ]);
        } catch (Throwable $e) {
            Log::warning("ReputationChecker: gagal menghubungi Safe Browsing untuk {$url}: {$e->getMessage()}");

            return ['status' => 'unknown', 'threats' => []];
        }

        if (! $response->successful()) {
            // A rejected key is worth saying out loud once - silently reporting
            // "clean" for a site nobody is actually checking is the worst outcome.
            Log::warning('ReputationChecker: Safe Browsing menolak permintaan (HTTP '.$response->status().').');

            return ['status' => 'unknown', 'threats' => []];
        }

        // An empty response body is the API's way of saying "no matches", which
        // is the normal, healthy answer.
        $matches = $response->json('matches') ?? [];

        $threats = collect($matches)
            ->pluck('threatType')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return ['status' => $threats === [] ? 'clean' : 'flagged', 'threats' => $threats];
    }

    /** @param  array<int, string>  $threats */
    public function describe(array $threats): string
    {
        return collect($threats)
            ->map(fn (string $threat) => self::THREAT_LABELS[$threat] ?? $threat)
            ->implode(', ');
    }

    /** Stored encrypted on save, same treatment as the other API credentials. */
    protected function apiKey(): ?string
    {
        $stored = Setting::get('safebrowsing_api_key');

        if (blank($stored)) {
            return config('services.safebrowsing.key') ?: null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return (string) $stored;
        }
    }
}
