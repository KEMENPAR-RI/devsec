<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Application-wide settings editable from Pengaturan > Konfigurasi Aplikasi,
 * rather than only from .env plus a redeploy.
 *
 * Two kinds live here: things that shape the whole app (its name, the timezone
 * every timestamp is rendered in - applied at boot, see AppServiceProvider), and
 * the defaults a newly added site starts with, which used to be magic numbers
 * hard-coded in the "Tambah Situs" form.
 */
class AppSettings
{
    /**
     * Filesystem answers already given during this request, keyed by storage path.
     *
     * Setting::get() memoizes the path itself, but logoUrl()/faviconUrl() also ask
     * the disk whether that file is still there - and the layout resolves both on
     * every single page render. The existence check is the expensive half, so it
     * is the half worth remembering.
     *
     * Keyed by path rather than by setting key so that replacing the logo, which
     * stores under a new path, simply misses the memo. Removing one deletes the
     * file under a path already answered for, so that case is cleared explicitly -
     * see forgetResolvedUrls().
     *
     * @var array<string, ?string>
     */
    protected array $resolvedUrls = [];

    /**
     * Drops the memoized existence checks.
     *
     * Called after an upload is deleted, since the memo would otherwise still hand
     * back a URL for a file that is no longer on disk for the rest of the request.
     */
    public function forgetResolvedUrls(): void
    {
        $this->resolvedUrls = [];
    }

    public function name(): string
    {
        return Setting::get('app_name') ?: (string) config('app.name');
    }

    /** Storage path of the uploaded logo, relative to the "public" disk. */
    public function logoPath(): ?string
    {
        return Setting::get('app_logo_path') ?: null;
    }

    /**
     * A URL the browser can load, or null to fall back to the initial-letter mark.
     * Returns null when the row points at a file that is no longer there, so a
     * deleted upload degrades to the fallback instead of a broken image.
     */
    public function logoUrl(): ?string
    {
        return $this->publicUrl($this->logoPath());
    }

    /** Storage path of the uploaded favicon, relative to the "public" disk. */
    public function faviconPath(): ?string
    {
        return Setting::get('app_favicon_path') ?: null;
    }

    /**
     * A URL for the browser-tab icon, or null to let the browser fall back to its
     * default. Same missing-file guard as logoUrl(): a row pointing at a deleted
     * upload must not render a broken <link>.
     */
    public function faviconUrl(): ?string
    {
        return $this->publicUrl($this->faviconPath());
    }

    /**
     * A browser-loadable URL for an uploaded file, or null when the row points at
     * something no longer on disk - so a deleted upload degrades to the fallback
     * instead of rendering as a broken image or <link>.
     *
     * Shared by the logo and the favicon, which had the same four lines twice.
     */
    protected function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        // array_key_exists rather than ??=, so a path whose file is missing
        // memoizes as null instead of re-hitting the disk on every later call.
        if (! array_key_exists($path, $this->resolvedUrls)) {
            $this->resolvedUrls[$path] = Storage::disk('public')->exists($path)
                ? Storage::disk('public')->url($path)
                : null;
        }

        return $this->resolvedUrls[$path];
    }

    public function timezone(): string
    {
        return Setting::get('app_timezone') ?: (string) config('app.timezone', 'UTC');
    }

    public function defaultCheckIntervalMinutes(): int
    {
        return (int) (Setting::get('default_check_interval_minutes') ?: 5);
    }

    public function defaultDownThresholdFailures(): int
    {
        return (int) (Setting::get('default_down_threshold_failures') ?: 3);
    }

    public function defaultResponseTimeThresholdMs(): int
    {
        return (int) (Setting::get('default_response_time_threshold_ms') ?: 15000);
    }

    /** How often the public TV page refreshes, in seconds (10..3600). */
    public function tvPollSeconds(): int
    {
        return max(10, min(3600, (int) (Setting::get('tv_poll_seconds') ?: 300)));
    }

    /**
     * The defaults a new website is created with, shaped like Website's columns so
     * it can be spread straight into create()/fill().
     *
     * @return array{check_interval_minutes: int, down_threshold_failures: int, response_time_threshold_ms: int}
     */
    public function websiteDefaults(): array
    {
        return [
            'check_interval_minutes' => $this->defaultCheckIntervalMinutes(),
            'down_threshold_failures' => $this->defaultDownThresholdFailures(),
            'response_time_threshold_ms' => $this->defaultResponseTimeThresholdMs(),
        ];
    }

    /**
     * Timezones offered in the picker. Deliberately a short list of the Indonesian
     * zones plus UTC rather than all ~400 identifiers - this app is used from one
     * country, and a 400-item select is worse than useless.
     *
     * @return array<string, string>
     */
    public function timezoneOptions(): array
    {
        return [
            'Asia/Jakarta' => 'WIB - Asia/Jakarta (UTC+7)',
            'Asia/Makassar' => 'WITA - Asia/Makassar (UTC+8)',
            'Asia/Jayapura' => 'WIT - Asia/Jayapura (UTC+9)',
            'UTC' => 'UTC (UTC+0)',
        ];
    }
}
