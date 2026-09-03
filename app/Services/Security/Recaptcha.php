<?php

namespace App\Services\Security;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google reCAPTCHA v3 on the public auth forms (login, lupa kata sandi).
 *
 * v3 is invisible: the browser silently earns a token and Google scores it 0.0
 * (almost certainly a bot) to 1.0 (almost certainly a person). There is no puzzle
 * to solve, so a legitimate operator signing in during an incident is never
 * slowed down - which is why it suits this app better than the checkbox variant.
 *
 * Configured from Pengaturan > Konfigurasi Aplikasi; off until someone puts a key
 * pair in, so a fresh install and the test suite behave as though it isn't there.
 *
 * RECAPTCHA_ENABLED=false in .env overrides all of that - see allowedByEnv().
 */
class Recaptcha
{
    public const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /** Below this score the submission is refused. Google's own suggested default. */
    public const DEFAULT_MIN_SCORE = 0.5;

    /**
     * Only on once it's switched on AND has both keys - a half-filled form must
     * never lock anyone out of the dashboard.
     */
    public function enabled(): bool
    {
        return $this->allowedByEnv()
            && Setting::get('recaptcha_enabled') === '1'
            && filled($this->siteKey())
            && filled($this->secretKey());
    }

    /**
     * The escape hatch: RECAPTCHA_ENABLED=false in .env forces reCAPTCHA off no
     * matter what the settings table says.
     *
     * A mistyped key pair rejects every login, and the toggle that would undo it
     * lives behind that login - so the recovery switch has to sit somewhere
     * reachable over SSH alone. Defaults to true, so an .env without the line
     * behaves exactly as before.
     */
    public function allowedByEnv(): bool
    {
        return (bool) config('services.recaptcha.enabled', true);
    }

    /** Public by design - it ships to the browser in the script tag. */
    public function siteKey(): ?string
    {
        return Setting::get('recaptcha_site_key') ?: null;
    }

    public function secretKey(): ?string
    {
        $stored = Setting::get('recaptcha_secret_key');

        if (blank($stored)) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            // Stored before encryption was in place, or written by hand.
            return $stored;
        }
    }

    public function hasSecretKey(): bool
    {
        return filled(Setting::get('recaptcha_secret_key'));
    }

    public function minScore(): float
    {
        $stored = Setting::get('recaptcha_min_score');

        return $stored === null ? self::DEFAULT_MIN_SCORE : (float) $stored;
    }

    /**
     * Checks one token with Google.
     *
     * On an explicit negative verdict (bad token, score too low, wrong action) the
     * submission is refused. On a *transport* failure - Google unreachable, timeout -
     * it is allowed through and logged instead: this is the dashboard people sign
     * into to handle outages, and making Google a hard dependency of that would
     * turn their bad day into a worse one.
     *
     * @return array{ok: bool, score: ?float, message: ?string}
     */
    public function verify(?string $token, string $action): array
    {
        if (! $this->enabled()) {
            return ['ok' => true, 'score' => null, 'message' => null];
        }

        if (blank($token)) {
            return ['ok' => false, 'score' => null, 'message' => 'Verifikasi keamanan gagal dimuat. Muat ulang halaman dan coba lagi.'];
        }

        try {
            $response = Http::asForm()
                ->timeout(8)
                ->post(self::VERIFY_URL, [
                    'secret' => $this->secretKey(),
                    'response' => $token,
                    'remoteip' => request()->ip(),
                ]);
        } catch (Throwable $e) {
            Log::warning('reCAPTCHA tidak dapat dihubungi, verifikasi dilewati: '.$e->getMessage());

            return ['ok' => true, 'score' => null, 'message' => null];
        }

        if ($response->failed()) {
            Log::warning('reCAPTCHA membalas HTTP '.$response->status().', verifikasi dilewati.');

            return ['ok' => true, 'score' => null, 'message' => null];
        }

        $body = $response->json();
        $score = isset($body['score']) ? (float) $body['score'] : null;

        if (($body['success'] ?? false) !== true) {
            $codes = implode(', ', (array) ($body['error-codes'] ?? []));
            Log::info('reCAPTCHA menolak token'.($codes ? " ({$codes})" : '').'.');

            return ['ok' => false, 'score' => $score, 'message' => 'Verifikasi keamanan gagal. Silakan coba lagi.'];
        }

        // A token minted for a different form must not be replayed on this one.
        if (isset($body['action']) && $body['action'] !== $action) {
            return ['ok' => false, 'score' => $score, 'message' => 'Verifikasi keamanan tidak cocok dengan formulir ini. Muat ulang halaman.'];
        }

        if ($score !== null && $score < $this->minScore()) {
            Log::info("reCAPTCHA skor {$score} di bawah ambang {$this->minScore()} untuk aksi {$action}.");

            return ['ok' => false, 'score' => $score, 'message' => 'Aktivitas Anda terdeteksi mencurigakan oleh sistem keamanan. Silakan coba lagi.'];
        }

        return ['ok' => true, 'score' => $score, 'message' => null];
    }
}
