<?php

use App\Support\Dates;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

if (! function_exists('safe_image_urls')) {
    /**
     * Sanitizes one or more image URLs for safe use in an `<img src>` (or similar)
     * attribute - rejects dangerous schemes (javascript:, data:, vbscript:, ...),
     * keeping only http(s) URLs and scheme-relative/root-relative ones (no scheme
     * at all, e.g. "//cdn.example.com/x.png" or "/storage/logo.png").
     *
     * Accepts a single URL or any iterable of them and returns the same shape back:
     * a string in returns a string (or null if it was empty/unsafe); an iterable in
     * returns a plain array with invalid/empty entries dropped. Does NOT HTML-escape
     * the result - that's Blade's job via `{{ }}` at the call site, so callers don't
     * end up double-escaping.
     *
     * @param  string|iterable<string|null>|null  $urls
     * @return ($urls is string ? string|null : array<int, string>)
     */
    function safe_image_urls(string|iterable|null $urls): string|array|null
    {
        $sanitizeOne = function (?string $url): ?string {
            $url = $url !== null ? trim($url) : null;

            if (! $url) {
                return null;
            }

            $scheme = parse_url($url, PHP_URL_SCHEME);

            if ($scheme !== null && ! in_array(strtolower($scheme), ['http', 'https'], true)) {
                return null;
            }

            return $url;
        };

        if ($urls === null || is_string($urls)) {
            return $sanitizeOne($urls);
        }

        return Collection::make($urls)->map($sanitizeOne)->filter()->values()->all();
    }
}

if (! function_exists('app_datetime')) {
    /**
     * A timestamp rendered in the app's configured timezone with its Indonesian
     * label, e.g. "02 Sep 2026, 15:32 WIB". Null in, empty string out.
     *
     * The thin global wrapper exists so views read as `{{ app_datetime($x) }}`
     * rather than a fully-qualified static call on every row; the logic itself is
     * in App\Support\Dates, where it can be tested directly.
     */
    function app_datetime(CarbonInterface|string|null $value, ?string $format = null): string
    {
        return Dates::display($value, $format ?? Dates::SHORT);
    }
}

if (! function_exists('app_datetime_full')) {
    /** Long form: "02 September 2026, 15:32 WIB". */
    function app_datetime_full(CarbonInterface|string|null $value): string
    {
        return Dates::display($value, Dates::FULL);
    }
}

if (! function_exists('app_time_ago')) {
    /** "3 menit yang lalu", in the app's timezone. */
    function app_time_ago(CarbonInterface|string|null $value): string
    {
        return Dates::relative($value);
    }
}

if (! function_exists('app_timezone_label')) {
    /** WIB / WITA / WIT for the configured timezone. */
    function app_timezone_label(): string
    {
        return Dates::abbreviation();
    }
}
