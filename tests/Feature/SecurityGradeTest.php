<?php

namespace Tests\Feature;

use App\Livewire\Websites\Show as WebsiteShow;
use App\Models\MaintenanceWindow;
use App\Models\User;
use App\Models\Website;
use App\Services\Monitoring\SecurityGrader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Grading the sites this app watches, rather than the app itself.
 *
 * Deliberately raises no incidents: headers change about once a year, so a
 * nightly "still a C" alert would only teach people to ignore alerts. The value
 * is the remediation list.
 */
class SecurityGradeTest extends TestCase
{
    use RefreshDatabase;

    // --- Scoring -----------------------------------------------------------

    public function test_a_fully_hardened_site_scores_well(): void
    {
        $this->fakeHeaders([
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=()',
        ]);

        $result = app(SecurityGrader::class)->grade($this->website('https://aman.example'));

        // Everything but the TLS handshake, which cannot be made in a test.
        $this->assertGreaterThanOrEqual(78, $result['score']);
        $this->assertContains($result['grade'], ['A', 'B']);
    }

    public function test_a_bare_site_scores_badly_and_says_why(): void
    {
        $this->fakeHeaders([]);

        $result = app(SecurityGrader::class)->grade($this->website('https://polos.example'));

        $this->assertLessThan(60, $result['score']);

        $failing = collect($result['findings'])->reject(fn (array $f) => $f['ok'])->pluck('key');

        $this->assertContains('hsts', $failing);
        $this->assertContains('csp', $failing);
        $this->assertContains('nosniff', $failing);
    }

    public function test_plain_http_is_the_heaviest_penalty(): void
    {
        $this->fakeHeaders([]);

        $https = app(SecurityGrader::class)->grade($this->website('https://a.example'));
        $http = app(SecurityGrader::class)->grade($this->website('http://b.example'));

        // A missing Referrer-Policy is untidy; plain HTTP means every form field
        // on the site is readable on the same network.
        $this->assertGreaterThan($http['score'], $https['score']);
    }

    public function test_a_short_hsts_window_does_not_count(): void
    {
        $this->fakeHeaders(['Strict-Transport-Security' => 'max-age=3600']);

        $result = app(SecurityGrader::class)->grade($this->website('https://pendek.example'));
        $hsts = collect($result['findings'])->firstWhere('key', 'hsts');

        $this->assertFalse($hsts['ok']);
        $this->assertStringContainsString('31536000', $hsts['note']);
    }

    public function test_csp_frame_ancestors_counts_as_clickjacking_protection(): void
    {
        $this->fakeHeaders(['Content-Security-Policy' => "frame-ancestors 'none'"]);

        $result = app(SecurityGrader::class)->grade($this->website('https://modern.example'));

        $this->assertTrue(collect($result['findings'])->firstWhere('key', 'frame')['ok']);
    }

    public function test_headers_are_matched_case_insensitively(): void
    {
        // Servers are inconsistent about casing, and a grade that depends on it
        // would be wrong for half the internet.
        $this->fakeHeaders(['x-content-type-options' => 'NoSniff']);

        $result = app(SecurityGrader::class)->grade($this->website('https://kasus.example'));

        $this->assertTrue(collect($result['findings'])->firstWhere('key', 'nosniff')['ok']);
    }

    public function test_a_waf_403_is_still_graded(): void
    {
        // A 403 from Cloudflare still carries the site's own headers, which is
        // what is being measured.
        Http::fake(['*' => Http::response('forbidden', 403, ['X-Content-Type-Options' => 'nosniff'])]);

        $result = app(SecurityGrader::class)->grade($this->website('https://waf.example'));

        $this->assertNotNull($result);
        $this->assertTrue(collect($result['findings'])->firstWhere('key', 'nosniff')['ok']);
    }

    public function test_an_unreachable_site_gets_no_grade_rather_than_an_f(): void
    {
        Http::fake(['*' => fn () => throw new RuntimeException('timeout')]);

        // "We could not look" and "it is badly configured" are different
        // statements; recording the second when the first is true would put a
        // scary letter next to a site whose only problem was a timeout.
        $this->assertNull(app(SecurityGrader::class)->grade($this->website('https://mati.example')));
    }

    public function test_an_internal_address_is_never_graded(): void
    {
        Http::fake();

        $this->assertNull(app(SecurityGrader::class)->grade(
            Website::factory()->create(['url' => 'http://192.168.1.1'])
        ));

        Http::assertNothingSent();
    }

    // --- The command -------------------------------------------------------

    public function test_the_command_stores_the_grade(): void
    {
        $this->fakeHeaders(['X-Content-Type-Options' => 'nosniff']);
        $website = $this->website('https://nilai.example');

        $this->artisan('websites:grade-security')->assertSuccessful();

        $website->refresh();

        $this->assertNotNull($website->security_grade);
        $this->assertNotNull($website->security_checked_at);
        $this->assertNotEmpty($website->security_findings);
    }

    public function test_the_command_skips_sites_under_maintenance(): void
    {
        $this->fakeHeaders([]);
        $website = $this->website('https://pemeliharaan.example');

        MaintenanceWindow::create([
            'website_id' => $website->id,
            'reason' => 'Migrasi server',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->artisan('websites:grade-security')->assertSuccessful();

        // A header missing mid-deploy is not a finding.
        $this->assertNull($website->fresh()->security_grade);
    }

    public function test_an_unreachable_site_keeps_its_previous_grade(): void
    {
        $website = $this->website('https://sempat-hidup.example');
        $website->update(['security_grade' => 'A', 'security_score' => 95]);

        Http::fake(['*' => fn () => throw new RuntimeException('timeout')]);

        $this->artisan('websites:grade-security')->assertSuccessful();

        $this->assertSame('A', $website->fresh()->security_grade);
    }

    // --- The button --------------------------------------------------------

    public function test_the_operator_can_re_grade_on_demand(): void
    {
        $this->fakeHeaders(['X-Content-Type-Options' => 'nosniff']);
        $website = $this->website('https://manual.example');

        Livewire::actingAs(User::factory()->create())
            ->test(WebsiteShow::class, ['website' => $website])
            ->call('gradeSecurityNow');

        $this->assertNotNull($website->fresh()->security_grade);
    }

    // --- Helpers -----------------------------------------------------------

    protected function website(string $url): Website
    {
        return Website::factory()->create(['url' => $url]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function fakeHeaders(array $headers): void
    {
        Http::fake(['*' => Http::response('<html></html>', 200, $headers)]);
    }
}
