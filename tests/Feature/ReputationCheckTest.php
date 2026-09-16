<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Notifications\ReputationFlagged;
use App\Services\Monitoring\ReputationChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A blocklisted domain is, for visitors, indistinguishable from an outage -
 * Chrome puts a red interstitial in front of it - yet the server answers
 * perfectly, so nothing else this app measures would ever notice.
 */
class ReputationCheckTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
    }

    // --- Opt-in -------------------------------------------------------------

    public function test_nothing_is_asked_without_an_api_key(): void
    {
        Http::fake();

        $this->assertFalse(app(ReputationChecker::class)->isConfigured());
        $this->assertSame('unknown', app(ReputationChecker::class)->check('https://contoh.example')['status']);

        Http::assertNothingSent();
    }

    public function test_an_unconfigured_install_records_nothing_at_all(): void
    {
        Http::fake(['rdap.org/*' => Http::response([], 404)]);

        $website = $this->website();

        $this->artisan('websites:check-domains')->assertSuccessful();

        // "We did not ask" must not be stored as "we asked and it was fine".
        $this->assertNull($website->fresh()->reputation_status);
    }

    // --- Reading the answer -------------------------------------------------

    public function test_a_clean_domain_is_recorded_as_clean(): void
    {
        $this->withKey();
        // Safe Browsing answers an empty body when there are no matches.
        Http::fake(['safebrowsing.googleapis.com/*' => Http::response([], 200)]);

        $result = app(ReputationChecker::class)->check('https://contoh.example');

        $this->assertSame('clean', $result['status']);
        $this->assertSame([], $result['threats']);
    }

    public function test_a_listed_domain_reports_its_threat_types(): void
    {
        $this->withKey();
        $this->fakeMatches(['MALWARE', 'SOCIAL_ENGINEERING']);

        $result = app(ReputationChecker::class)->check('https://contoh.example');

        $this->assertSame('flagged', $result['status']);
        $this->assertSame(['MALWARE', 'SOCIAL_ENGINEERING'], $result['threats']);
    }

    public function test_a_rejected_key_reads_as_unknown_not_as_clean(): void
    {
        $this->withKey();
        Http::fake(['safebrowsing.googleapis.com/*' => Http::response(['error' => 'bad key'], 403)]);

        // Silently reporting "clean" for a site nobody is actually checking is
        // the worst possible outcome here.
        $this->assertSame('unknown', app(ReputationChecker::class)->check('https://contoh.example')['status']);
    }

    public function test_threat_types_are_translated_for_operators(): void
    {
        $this->assertSame(
            'Malware, Phishing / rekayasa sosial',
            app(ReputationChecker::class)->describe(['MALWARE', 'SOCIAL_ENGINEERING'])
        );
    }

    // --- Incidents ----------------------------------------------------------

    public function test_a_newly_listed_site_raises_a_critical_incident_and_alerts(): void
    {
        Notification::fake();
        $this->withKey();
        $this->fakeMatches(['MALWARE']);

        $website = $this->website();

        $this->artisan('websites:check-domains')->assertSuccessful();

        $incident = Incident::where('type', 'reputation')->firstOrFail();

        $this->assertSame('critical', $incident->severity);
        $this->assertSame(['MALWARE'], $incident->meta['ancaman']);
        $this->assertSame('flagged', $website->fresh()->reputation_status);
        Notification::assertSentTo($this->admin, ReputationFlagged::class);
    }

    public function test_a_site_that_is_still_listed_tomorrow_is_not_re_reported(): void
    {
        Notification::fake();
        $this->withKey();
        $this->fakeMatches(['MALWARE']);

        $website = $this->website();

        $this->artisan('websites:check-domains');
        $website->update(['dns_checked_at' => null, 'domain_checked_at' => null]);
        $this->artisan('websites:check-domains');

        // Still listed is not news; a fresh incident every night would bury the
        // site that just got listed.
        $this->assertSame(1, Incident::where('type', 'reputation')->count());
    }

    public function test_delisting_closes_the_incident(): void
    {
        Notification::fake();
        $this->withKey();

        // One mutable stub rather than two Http::fake() calls: a second fake is
        // merged onto the first, and the earlier matching stub keeps winning.
        $listed = true;

        Http::fake([
            'safebrowsing.googleapis.com/*' => function () use (&$listed) {
                return Http::response($listed ? ['matches' => [['threatType' => 'MALWARE']]] : [], 200);
            },
            'rdap.org/*' => Http::response([], 404),
        ]);

        $website = $this->website();
        $this->artisan('websites:check-domains');

        $this->assertSame('open', Incident::where('type', 'reputation')->firstOrFail()->status);

        $listed = false;
        $website->update(['dns_checked_at' => null, 'domain_checked_at' => null]);
        $this->artisan('websites:check-domains');

        // The site must stop reading as blocked the moment Google stops blocking it.
        $this->assertSame('resolved', Incident::where('type', 'reputation')->firstOrFail()->status);
        $this->assertSame('clean', $website->fresh()->reputation_status);
    }

    public function test_the_incident_detail_names_the_threat_and_the_remedy(): void
    {
        Notification::fake();
        $this->withKey();
        $this->fakeMatches(['SOCIAL_ENGINEERING']);

        $this->website();
        $this->artisan('websites:check-domains');

        $lines = Incident::where('type', 'reputation')->firstOrFail()->detailLines();

        $this->assertSame('Phishing / rekayasa sosial', $lines['Kategori Ancaman']);
        $this->assertStringContainsString('Search Console', $lines['Langkah Pemulihan']);
    }

    // --- Helpers ------------------------------------------------------------

    protected function website(): Website
    {
        static $sequence = 0;

        return Website::factory()->create(['url' => 'https://reputasi-'.(++$sequence).'.example']);
    }

    protected function withKey(): void
    {
        Setting::set('safebrowsing_api_key', Crypt::encryptString('kunci-uji'));
    }

    /**
     * @param  array<int, string>  $threats
     */
    protected function fakeMatches(array $threats): void
    {
        Http::fake([
            'safebrowsing.googleapis.com/*' => Http::response([
                'matches' => collect($threats)->map(fn (string $threat) => ['threatType' => $threat])->all(),
            ], 200),
            'rdap.org/*' => Http::response([], 404),
        ]);
    }
}
