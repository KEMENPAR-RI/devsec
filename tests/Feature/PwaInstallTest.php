<?php

namespace Tests\Feature;

use App\Models\Setting;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Guards the handful of facts a browser checks before it will offer to install
 * the dashboard. Each one is invisible in normal use and silently kills the
 * install prompt when it breaks - a manifest behind auth, a missing 512px icon,
 * a service worker that never registers - so they are asserted rather than
 * eyeballed on a phone.
 */
class PwaInstallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new RoleSeeder)->run();
    }

    public function test_the_manifest_is_served_to_a_signed_out_browser(): void
    {
        // The browser fetches the manifest anonymously and does not follow a login
        // redirect: putting this route behind auth makes the app uninstallable.
        $response = $this->get(route('pwa.manifest'));

        $response->assertOk();
        $this->assertSame('application/manifest+json', $response->headers->get('Content-Type'));
    }

    public function test_the_manifest_carries_everything_chrome_requires_to_install(): void
    {
        $manifest = json_decode($this->get(route('pwa.manifest'))->getContent(), true);

        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertNotEmpty($manifest['name']);
        $this->assertNotEmpty($manifest['short_name']);

        $sizes = array_column($manifest['icons'], 'sizes');
        $this->assertContains('192x192', $sizes, 'Chrome needs a 192px icon');
        $this->assertContains('512x512', $sizes, 'Chrome needs a 512px icon');

        $this->assertContains(
            'maskable',
            array_column($manifest['icons'], 'purpose'),
            'without one, Android letterboxes the icon inside its launcher shape'
        );
    }

    public function test_the_manifest_follows_the_branding_saved_in_pengaturan(): void
    {
        // The point of generating it per request rather than shipping a static
        // file: a rename has to reach the home-screen label too.
        Setting::set('app_name', 'Monitoring Pariwisata');

        $manifest = json_decode($this->get(route('pwa.manifest'))->getContent(), true);

        $this->assertSame('Monitoring Pariwisata', $manifest['name']);
    }

    public function test_every_declared_icon_is_a_real_png_of_the_size_it_claims(): void
    {
        $manifest = json_decode($this->get(route('pwa.manifest'))->getContent(), true);

        foreach ($manifest['icons'] as $icon) {
            $response = $this->get($icon['src']);
            $response->assertOk();

            [$width, $height, $type] = getimagesizefromstring($response->getContent());

            $this->assertSame(IMAGETYPE_PNG, $type, "{$icon['src']} is not a PNG");
            $this->assertSame($icon['sizes'], "{$width}x{$height}", "{$icon['src']} is the wrong size");
        }
    }

    public function test_the_login_screen_can_install_the_app_too(): void
    {
        // Most people meet this app on the login screen; if the manifest and the
        // install control only existed behind auth, a phone could never install it.
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee(route('pwa.manifest'), false);
        $response->assertSee('data-pwa-install', false);
    }

    /**
     * iOS never fires beforeinstallprompt and offers no install API, so the install
     * control there can only be instructions. It used to be a bare <span>, which on
     * a phone reads as a button that does nothing when tapped - the whole platform's
     * install path looked broken.
     */
    public function test_ios_gets_a_real_tappable_control_not_a_dead_label(): void
    {
        $body = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('data-pwa-ios', $body);
        $this->assertMatchesRegularExpression(
            '/data-pwa-ios.{0,400}<button/s',
            $body,
            'the iOS affordance must contain a button, not just text'
        );
        $this->assertStringContainsString('Tambah ke Layar Utama', $body, 'the actual steps are spelled out');
    }

    /**
     * A browser refuses both the service worker and the install prompt off a secure
     * origin, and says nothing about it - so on a plain-http deployment the button
     * simply never appears, which looks like the build having been done wrong
     * rather than a certificate being missing.
     */
    public function test_an_insecure_origin_is_explained_instead_of_showing_nothing(): void
    {
        $body = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('data-pwa-insecure', $body);
        $this->assertStringContainsString('Instalasi butuh HTTPS', $body);
    }

    public function test_the_client_script_gates_installability_on_a_secure_context(): void
    {
        $script = file_get_contents(resource_path('js/pwa.js'));

        $this->assertStringContainsString('window.isSecureContext', $script);
        $this->assertStringContainsString('data-pwa-insecure', $script);
    }

    public function test_the_service_worker_and_its_offline_page_are_served_from_the_web_root(): void
    {
        // Registered with scope '/', which the browser only allows when the script
        // itself is served from the root - moving it under /build or /js breaks it.
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('offline.html'));

        $this->assertStringContainsString(
            "register('/sw.js'",
            file_get_contents(resource_path('js/pwa.js')),
            'the worker must be registered from the root for scope / to be granted'
        );
    }

    /**
     * The favicon is the mark already chosen to work at a tiny size, which is the
     * job a home-screen icon does - so it wins over the logo, whose wordmark would
     * shrink to a smudge.
     */
    public function test_the_icon_is_drawn_from_the_favicon_when_one_is_uploaded(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/favicon.png', $this->solidPng(64, [0xFF, 0x00, 0x00]));
        Storage::disk('public')->put('branding/logo.png', $this->solidPng(64, [0x00, 0xFF, 0x00]));
        Setting::set('app_favicon_path', 'branding/favicon.png');
        Setting::set('app_logo_path', 'branding/logo.png');

        $this->assertSame([0xFF, 0x00, 0x00], $this->centerPixelOfIcon(), 'the favicon should be the one drawn');
    }

    public function test_the_logo_is_used_when_there_is_no_favicon(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/logo.png', $this->solidPng(64, [0x00, 0xFF, 0x00]));
        Setting::set('app_logo_path', 'branding/logo.png');

        $this->assertSame([0x00, 0xFF, 0x00], $this->centerPixelOfIcon());
    }

    /**
     * Regression guard for the reason this falls back at all: the favicon upload
     * accepts .ico, and GD cannot decode that format. Preferring the favicon
     * blindly would drop every .ico user back to the generic shield.
     */
    public function test_an_ico_favicon_falls_through_to_the_logo_rather_than_the_shield(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/favicon.ico', 'not something GD can decode');
        Storage::disk('public')->put('branding/logo.png', $this->solidPng(64, [0x00, 0xFF, 0x00]));
        Setting::set('app_favicon_path', 'branding/favicon.ico');
        Setting::set('app_logo_path', 'branding/logo.png');

        $this->assertSame([0x00, 0xFF, 0x00], $this->centerPixelOfIcon());
    }

    /** The RGB at the middle of the generated 192px icon, where the mark is drawn. */
    private function centerPixelOfIcon(): array
    {
        $png = $this->get(route('pwa.icon', 192))->assertOk()->getContent();
        $image = imagecreatefromstring($png);
        $rgb = imagecolorat($image, 96, 96);
        imagedestroy($image);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    /** A solid square PNG, so the colour at the icon's centre identifies the source. */
    private function solidPng(int $size, array $rgb): string
    {
        $image = imagecreatetruecolor($size, $size);
        imagefilledrectangle($image, 0, 0, $size, $size, imagecolorallocate($image, ...$rgb));

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
