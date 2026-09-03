<?php

namespace App\Http\Controllers;

use App\Services\AppSettings;
use GdImage;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Everything the browser needs to install the dashboard as an app.
 *
 * The manifest and its icons are generated per request instead of sitting in
 * public/ as static files, because both the app name and the logo are editable
 * from Pengaturan > Konfigurasi Aplikasi - a static manifest would keep
 * advertising the previous branding until someone remembered to regenerate it.
 * Both routes are public: the browser fetches the manifest anonymously, and the
 * install prompt has to be able to appear on the login screen too.
 */
class PwaController extends Controller
{
    /** accent-600 (#132B50) - the icon background, matching the brand mark. */
    private const BACKGROUND = [0x13, 0x2B, 0x50];

    /**
     * Icons are rendered at this multiple of the requested size and scaled back
     * down, so edges come out smooth instead of stair-stepped.
     */
    private const SUPERSAMPLE = 3;

    public function manifest(AppSettings $settings): Response
    {
        $name = $settings->name();

        $manifest = [
            'id' => '/',
            'name' => $name,
            'short_name' => Str::limit($name, 12, ''),
            'description' => 'Monitoring keamanan dan ketersediaan situs - Kementerian Pariwisata Republik Indonesia.',
            'lang' => 'id',
            'dir' => 'ltr',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'theme_color' => '#132b50',
            'background_color' => '#ffffff',
            'categories' => ['business', 'productivity', 'security'],
            'icons' => [
                ['src' => route('pwa.icon', 192), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => route('pwa.icon', 512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => route('pwa.icon.maskable', 512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            // Long-press / right-click on the installed icon jumps straight to a page.
            'shortcuts' => [
                $this->shortcut('Dashboard', route('dashboard', absolute: false)),
                $this->shortcut('Situs', route('situs', absolute: false)),
                $this->shortcut('Insiden', route('insiden', absolute: false)),
            ],
        ];

        return response(json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /** Square icon on the brand background, with room to breathe around the mark. */
    public function icon(int $size, AppSettings $settings): Response
    {
        return $this->renderIcon($size, 0.14, $settings);
    }

    /**
     * Maskable variant: Android crops icons to whatever shape the launcher uses, so
     * the mark is kept inside the central area every crop is guaranteed to keep -
     * see https://w3c.github.io/manifest/#icon-masks.
     */
    public function maskableIcon(int $size, AppSettings $settings): Response
    {
        return $this->renderIcon($size, 0.22, $settings);
    }

    /** @return array<string, mixed> */
    private function shortcut(string $name, string $url): array
    {
        return [
            'name' => $name,
            'url' => $url,
            'icons' => [['src' => route('pwa.icon', 192), 'sizes' => '192x192', 'type' => 'image/png']],
        ];
    }

    private function renderIcon(int $size, float $padding, AppSettings $settings): Response
    {
        // The routes only ever ask for the manifest's own sizes, but the parameter
        // is user-supplied, so it is clamped rather than trusted.
        $size = max(48, min(1024, $size));
        $scale = $size * self::SUPERSAMPLE;

        $canvas = imagecreatetruecolor($scale, $scale);
        imagealphablending($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $scale, $scale, imagecolorallocate($canvas, ...self::BACKGROUND));

        $box = (int) round($scale * (1 - 2 * $padding));
        $offset = (int) round(($scale - $box) / 2);

        if ($logo = $this->logoImage($settings)) {
            $this->drawContained($canvas, $logo, $offset, $box);
            imagedestroy($logo);
        } else {
            $this->drawShield($canvas, $offset, $box);
        }

        $icon = imagecreatetruecolor($size, $size);
        imagecopyresampled($icon, $canvas, 0, 0, 0, 0, $size, $size, $scale, $scale);
        imagedestroy($canvas);

        ob_start();
        imagepng($icon, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($icon);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => '"'.md5($png).'"',
        ]);
    }

    /** The uploaded logo as a GD image, or null when there is none to draw. */
    private function logoImage(AppSettings $settings): ?GdImage
    {
        $path = $settings->logoPath();

        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        // A branding upload can be png/jpg/webp/svg; imagecreatefromstring handles
        // the raster ones and returns false for anything it cannot decode, which
        // falls through to the built-in mark below.
        $image = @imagecreatefromstring((string) Storage::disk('public')->get($path));

        return $image ?: null;
    }

    /** Scales the logo to fit the safe box, keeping its aspect ratio, and centers it. */
    private function drawContained(GdImage $canvas, GdImage $logo, int $offset, int $box): void
    {
        $width = imagesx($logo);
        $height = imagesy($logo);
        $ratio = min($box / $width, $box / $height);

        $drawWidth = (int) round($width * $ratio);
        $drawHeight = (int) round($height * $ratio);

        imagecopyresampled(
            $canvas,
            $logo,
            $offset + (int) round(($box - $drawWidth) / 2),
            $offset + (int) round(($box - $drawHeight) / 2),
            0,
            0,
            $drawWidth,
            $drawHeight,
            $width,
            $height,
        );
    }

    /**
     * Fallback mark for an install made before any logo is uploaded: a white shield
     * with a check cut out of it. Deliberately a shape rather than the app's
     * initial - drawing readable text needs a bundled TTF, and an upscaled bitmap
     * letter would look far worse on a home screen than a clean silhouette.
     */
    private function drawShield(GdImage $canvas, int $offset, int $box): void
    {
        $white = imagecolorallocate($canvas, 0xFF, 0xFF, 0xFF);
        $p = fn (float $ratio): int => $offset + (int) round($ratio * $box);

        $outline = [];
        foreach ([[0.5, 0.04], [0.9, 0.2], [0.9, 0.5], [0.5, 0.96], [0.1, 0.5], [0.1, 0.2]] as [$px, $py]) {
            array_push($outline, $p($px), $p($py));
        }

        imagefilledpolygon($canvas, $outline, $white);

        $accent = imagecolorallocate($canvas, ...self::BACKGROUND);
        imagesetthickness($canvas, max(2, (int) round($box * 0.09)));
        imageline($canvas, $p(0.30), $p(0.50), $p(0.45), $p(0.66), $accent);
        imageline($canvas, $p(0.45), $p(0.66), $p(0.72), $p(0.34), $accent);
        imagesetthickness($canvas, 1);
    }
}
