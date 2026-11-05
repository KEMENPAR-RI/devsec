<?php

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The two inputs this app must never take at face value.
 *
 * The first is the content of the sites it watches: a monitored site being under
 * someone else's control is not an edge case here, it is the entire premise of
 * a monitor. Everything it brings back - error messages above all - is
 * therefore hostile text that happens to be stored in our own database.
 *
 * The second is the request headers, which any client can write. They decide who
 * the rate limiter thinks it is talking to.
 */
class UntrustedInputTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A downtime incident quotes the error the monitored site answered with, which
     * the site controls. The detail block on this page is the one place that
     * renders those lines as HTML, so it is the one place the markup could have
     * executed.
     */
    public function test_text_read_from_a_monitored_site_cannot_inject_markup(): void
    {
        $website = Website::factory()->create(['name' => 'Portal Layanan']);

        Incident::create([
            'website_id' => $website->id,
            'type' => 'downtime',
            'severity' => 'critical',
            'title' => 'Portal Layanan tidak dapat diakses',
            'description' => 'Situs tidak merespons.',
            'meta' => ['error_message' => 'Bad gateway <img src=x onerror=alert(1)>'],
            'status' => 'open',
            'detected_at' => now(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('situs.show', $website));

        $response->assertOk();
        $response->assertDontSee('<img src=x', escape: false);
        $response->assertSee('Pesan Error: Bad gateway &lt;img src=x', escape: false);
    }

    /**
     * The same text reaches the admin a second way, in the alert email.
     * {{title}} and {{message}} are dropped into an HTML body, so they are
     * escaped like every other value that came from outside.
     */
    public function test_the_email_body_escapes_the_text_it_is_handed(): void
    {
        $html = TemplatedMail::for([
            'title' => 'Situs down di Portal <script>alert(1)</script>',
            'message' => 'Pesan dari situs: <img src=x onerror=alert(1)>',
        ])->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** The subject line is plain text, where an escaped "&" would be read literally. */
    public function test_the_subject_line_is_not_escaped(): void
    {
        $subject = TemplatedMail::for(['title' => 'Contoh & Mitra'])->envelope()->subject;

        $this->assertSame('['.config('app.name').'] Contoh & Mitra', $subject);
    }

    /**
     * X-Forwarded-For from an address that is not the proxy must be ignored.
     *
     * request()->ip() is what the login and password-reset throttle keys are
     * built from, so honouring this header from anyone would mean a password
     * spray could reset its own lockout by changing one string per attempt.
     */
    public function test_a_forwarded_for_header_from_an_untrusted_address_is_ignored(): void
    {
        Route::get('/__uji-ip', fn () => request()->ip());

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->get('/__uji-ip', ['X-Forwarded-For' => '198.51.100.7']);

        $response->assertOk();
        $response->assertSee('203.0.113.9');
        $response->assertDontSee('198.51.100.7');
    }

    /**
     * The other half of the same setting: the local reverse proxy terminates TLS,
     * so its X-Forwarded-Proto still has to be believed - otherwise every HTTPS
     * request looks plain to PHP and the HSTS header silently stops being sent in
     * exactly the deployment it exists for.
     */
    public function test_the_local_proxy_is_still_trusted_for_the_scheme(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('http://deadsec.apiprs.my.id/login', ['X-Forwarded-Proto' => 'https'])
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
    }
}
