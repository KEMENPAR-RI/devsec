<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Turns a User-Agent string into something an operator can recognise, e.g.
 * "Chrome di Windows".
 *
 * Deliberately crude. The point is only to let someone scanning their session
 * list spot the one that is not theirs; a full UA parser would be a dependency
 * and a maintenance burden for a label.
 */
class DeviceName
{
    /** Needle => label, first match wins, so the specific ones come first. */
    protected const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Chrome' => 'Chrome',
        'Safari' => 'Safari',
        'Firefox' => 'Firefox',
    ];

    protected const PLATFORMS = [
        'Windows' => 'Windows',
        'Android' => 'Android',
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Macintosh' => 'macOS',
        'Mac OS X' => 'macOS',
        'Linux' => 'Linux',
    ];

    public static function fromUserAgent(?string $userAgent): string
    {
        if (blank($userAgent)) {
            return 'Perangkat tidak dikenal';
        }

        $browser = static::firstMatch($userAgent, static::BROWSERS);
        $platform = static::firstMatch($userAgent, static::PLATFORMS);

        return match (true) {
            $browser && $platform => "{$browser} di {$platform}",
            (bool) $browser => $browser,
            (bool) $platform => $platform,
            default => Str::limit($userAgent, 40),
        };
    }

    protected static function firstMatch(string $userAgent, array $map): ?string
    {
        foreach ($map as $needle => $label) {
            if (str_contains($userAgent, $needle)) {
                return $label;
            }
        }

        return null;
    }
}
