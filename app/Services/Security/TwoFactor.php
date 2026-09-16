<?php

namespace App\Services\Security;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP enrolment and verification (RFC 6238), the second factor at login.
 *
 * The algorithm itself comes from pragmarx/google2fa rather than being written
 * here - a hand-rolled one-time-password implementation is the kind of thing that
 * looks right and is subtly wrong, and this is the same library Laravel's own
 * Fortify uses.
 */
class TwoFactor
{
    /** How many 30-second steps either side of now a code is still accepted. */
    protected const WINDOW = 1;

    public const RECOVERY_CODE_COUNT = 8;

    public function __construct(protected Google2FA $engine) {}

    /** A fresh base32 secret for a new enrolment. */
    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    /**
     * Whether a six-digit code is valid for this secret right now.
     *
     * A malformed code is a plain "no", never an exception: the value comes
     * straight from a login form, and a thrown error there would leak which part
     * of the check failed.
     */
    public function verify(?string $secret, ?string $code): bool
    {
        $code = preg_replace('/\D/', '', (string) $code);

        if (blank($secret) || strlen($code) !== 6) {
            return false;
        }

        try {
            return (bool) $this->engine->verifyKey($secret, $code, self::WINDOW);
        } catch (\Throwable) {
            return false;
        }
    }

    /** The otpauth:// URI an authenticator app expects behind the QR code. */
    public function provisioningUri(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(config('app.name'), $user->email, $secret);
    }

    /**
     * The QR code as inline SVG.
     *
     * SVG, not PNG: the page's CSP is img-src 'self' data:, and an SVG written
     * straight into the markup needs neither a remote host nor ext-gd on the
     * server - which this app cannot assume is installed.
     */
    public function qrCodeSvg(User $user, string $secret, int $size = 200): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 0), new SvgImageBackEnd));

        return $writer->writeString($this->provisioningUri($user, $secret));
    }

    /** The secret in four-character groups, for someone typing it in by hand. */
    public function formatSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    /**
     * One-time codes for the day the phone is lost.
     *
     * Without these, losing the authenticator means losing the account - and an
     * account recovery path that runs through "ask an admin to disable it" is a
     * social-engineering target.
     *
     * @return array<int, string>
     */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();
    }
}
