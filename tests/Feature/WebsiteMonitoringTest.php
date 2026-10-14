<?php

namespace Tests\Feature;

use App\Jobs\CheckWebsiteJob;
use App\Jobs\ScanWebsiteForJudolJob;
use App\Livewire\JudolDetections\Index;
use App\Models\Incident;
use App\Models\JudolDetection;
use App\Models\JudolKeyword;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Notifications\WebsiteDownNotification;
use App\Services\Monitoring\JudolAiClassifier;
use App\Services\Monitoring\JudolAiSettings;
use App\Services\Monitoring\JudolScanner;
use App\Services\Monitoring\WebsiteChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_pages_render_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();

        $this->actingAs($user);

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('situs'))->assertOk();
        $this->get(route('situs.show', $website))->assertOk();
        $this->get(route('insiden'))->assertOk();
        $this->get(route('deteksi-judol'))->assertOk();
        $this->get(route('notifikasi'))->assertOk();
    }

    public function test_repeated_failures_open_a_downtime_incident_and_notify(): void
    {
        NotificationFacade::fake();

        Http::fake(fn () => Http::response('', 500));

        $user = User::factory()->create();
        $website = Website::factory()->create(['down_threshold_failures' => 3]);

        $checker = new WebsiteChecker;
        $checker->check($website);
        $checker->check($website);
        $checker->check($website);

        $website->refresh();

        $this->assertSame('down', $website->last_status);
        $this->assertSame(1, Incident::where('website_id', $website->id)->where('type', 'downtime')->count());
        NotificationFacade::assertSentTo($user, WebsiteDownNotification::class);
    }

    public function test_a_recovered_website_resolves_its_downtime_incident(): void
    {
        // Two 500s, then the recovery: the probe retries once before giving up
        // (see WebsiteChecker::httpClient), so a single failing response is not
        // enough to record an outage - by design, since one dropped handshake
        // would otherwise open an incident and email every admin.
        Http::fakeSequence()->push('', 500)->push('', 500)->push('ok', 200);

        $website = Website::factory()->create(['down_threshold_failures' => 1]);
        $checker = new WebsiteChecker;

        $checker->check($website);
        $website->refresh();
        $this->assertSame('down', $website->last_status);

        $checker->check($website);
        $website->refresh();
        $this->assertSame('up', $website->last_status);
        $this->assertSame('resolved', Incident::where('website_id', $website->id)->where('type', 'downtime')->first()->status);
    }

    public function test_response_time_over_threshold_is_treated_as_down(): void
    {
        NotificationFacade::fake();
        // A real delay before the fake response resolves, so response_time_ms is
        // reliably non-zero and clears the threshold with margin - a razor-thin gap
        // between the two (e.g. 5ms delay vs. a 1ms threshold) is flaky under system
        // load/scheduling jitter, which this suite has hit intermittently.
        Http::fake(function () {
            usleep(20000);

            return Http::response('ok', 200);
        });

        $website = Website::factory()->create(['down_threshold_failures' => 1, 'response_time_threshold_ms' => 5]);

        $check = (new WebsiteChecker)->check($website);

        $this->assertFalse($check->is_up);
        $this->assertStringContainsString('melebihi ambang batas', $check->error_message);

        $website->refresh();
        $this->assertSame('down', $website->last_status);
        $this->assertSame(1, Incident::where('website_id', $website->id)->where('type', 'downtime')->count());
    }

    /**
     * Regression: connectTimeout was briefly lowered to 5s and the retry removed,
     * as a "a host that has not connected by now is unreachable" optimisation. It
     * is not true here - the government/institutional sites this monitors sit on
     * shared hosting whose TLS handshake regularly runs past 5s - and every one of
     * them flipped red while still loading fine in a browser.
     *
     * The symptom was deceptive enough to be worth pinning: a cut-off handshake
     * produces no response at all, so the check recorded "down" with a
     * response_time_ms far below the threshold. A red site whose latency looked
     * healthy.
     *
     * This reads the source rather than the client object: Http::fake() never opens
     * a connection, so no behavioural test can observe a connect timeout, and the
     * alternative - reflecting into PendingRequest's private state - pins this
     * suite to framework internals that are free to change.
     */
    public function test_the_probe_allows_a_slow_tls_handshake_and_retries_once(): void
    {
        $source = file_get_contents(app_path('Services/Monitoring/WebsiteChecker.php'));

        preg_match('/->connectTimeout\((\d+)\)/', $source, $connect);
        $this->assertNotEmpty($connect, 'the probe must set an explicit connect timeout');
        $this->assertGreaterThanOrEqual(
            10,
            (int) $connect[1],
            'shared-hosting TLS handshakes need room; below 10s these sites read as down'
        );

        preg_match('/->retry\((\d+),/', $source, $retry);
        $this->assertNotEmpty($retry, 'the probe must set an explicit retry count');
        $this->assertGreaterThanOrEqual(
            2,
            (int) $retry[1],
            'a single dropped handshake must not be enough to open a downtime incident'
        );
    }

    public function test_pages_with_gambling_keywords_are_flagged(): void
    {
        NotificationFacade::fake();

        Http::fake([
            'judol-test.example' => Http::response('<title>Slot Gacor Maxwin</title><body>Bandar togel dan rtp live tertinggi.</body>', 200),
            'judol-test.example/sitemap.xml' => Http::response('', 404),
        ]);

        $website = Website::factory()->create(['url' => 'http://judol-test.example']);

        $created = (new JudolScanner)->scan($website);

        $this->assertSame(1, $created);
        $this->assertSame(1, JudolDetection::where('website_id', $website->id)->count());
        $this->assertSame(1, Incident::where('website_id', $website->id)->where('type', 'judol')->count());
    }

    public function test_crawler_follows_internal_links_to_find_pages_not_listed_in_the_sitemap(): void
    {
        NotificationFacade::fake();

        Http::fake([
            'crawl-test.example/sitemap.xml' => Http::response('', 404),
            'crawl-test.example/slot.html' => Http::response('<title>Slot Gacor Maxwin</title><body>Daftar situs slot gacor hari ini.</body>', 200),
            'crawl-test.example' => Http::response('<html><body><a href="/slot.html">Promo</a></body></html>', 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://crawl-test.example']);

        $created = (new JudolScanner)->scan($website);

        $this->assertSame(1, $created);
        $this->assertSame(
            'http://crawl-test.example/slot.html',
            JudolDetection::where('website_id', $website->id)->first()->url
        );
    }

    public function test_manual_url_scan_reports_the_result_immediately_even_if_not_linked_anywhere(): void
    {
        Http::fake([
            'manual-test.example/slot.html' => Http::response('<title>Slot Gacor Maxwin</title>', 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://manual-test.example']);

        $matched = (new JudolScanner)->scanUrlNow($website, 'http://manual-test.example/slot.html');

        $this->assertNotEmpty($matched);
        $this->assertDatabaseHas('judol_detections', [
            'website_id' => $website->id,
            'url' => 'http://manual-test.example/slot.html',
        ]);
    }

    public function test_a_page_is_flagged_when_the_keyword_is_only_in_the_url_slug(): void
    {
        // Sparse/JS-rendered page: no gambling word in the visible text, only in the slug.
        Http::fake([
            'slug-test.example/promo-slot-gacor-hari-ini' => Http::response('<html><body>Loading...</body></html>', 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://slug-test.example']);

        $matched = (new JudolScanner)->scanUrlNow($website, 'http://slug-test.example/promo-slot-gacor-hari-ini');

        $this->assertContains('slot gacor', $matched);
        $this->assertContains('gacor hari ini', $matched);
    }

    public function test_keywords_hidden_only_in_meta_description_are_still_detected(): void
    {
        // Regression: <meta content="..."> text lives in an attribute, not element text,
        // so a naive strip_tags() on the raw HTML silently misses it - this is exactly
        // where SEO/judol spam is very often stuffed (visible body can be near-empty).
        $html = '<html><head>'
            .'<title>Situs Biasa</title>'
            .'<meta name="description" content="Nhà cái uy tín hàng đầu, nổ hũ, bắn cá, đánh bài.">'
            .'</head><body>Halaman ini kelihatannya normal.</body></html>';

        Http::fake([
            'meta-test.example' => Http::response($html, 200),
            'meta-test.example/sitemap.xml' => Http::response('', 404),
        ]);

        $website = Website::factory()->create(['url' => 'http://meta-test.example']);

        $matched = (new JudolScanner)->scanUrlNow($website, 'http://meta-test.example');

        $this->assertContains('nhà cái', $matched);
    }

    public function test_leetspeak_obfuscated_keywords_are_still_detected(): void
    {
        // Simple char-substitution evasion (0 for o) - no exact substring match,
        // but the deobfuscated haystack in JudolScanner::evaluateAndStore should
        // still catch it.
        Http::fake([
            'leet-test.example' => Http::response('<title>Sl0t Gac0r Maxwin</title><body>Daftar t0gel online tepercaya.</body>', 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://leet-test.example']);

        $matched = (new JudolScanner)->scanUrlNow($website, 'http://leet-test.example');

        $this->assertContains('slot gacor', $matched);
        $this->assertContains('togel online', $matched);
    }

    public function test_ai_classifier_can_veto_a_keyword_match_as_a_false_positive(): void
    {
        NotificationFacade::fake();
        Setting::set('judol_ai_enabled', '1');

        $this->app->instance(JudolAiClassifier::class, new class(new JudolAiSettings) extends JudolAiClassifier
        {
            public function classify(string $url, string $text, array $matchedKeywords, array $imageUrls = []): array
            {
                return ['is_judol' => false, 'confidence' => 0.95, 'reasoning' => 'Artikel berita, bukan spam.'];
            }
        });

        Http::fake([
            'ai-veto-test.example' => Http::response('<title>Slot Gacor Maxwin</title><body>Berita tentang bahaya judi online.</body>', 200),
            'ai-veto-test.example/sitemap.xml' => Http::response('', 404),
        ]);

        $website = Website::factory()->create(['url' => 'http://ai-veto-test.example']);

        $created = (new JudolScanner)->scan($website);

        $this->assertSame(0, $created);
        $this->assertSame(0, JudolDetection::where('website_id', $website->id)->count());
    }

    public function test_ai_classifier_confirms_a_keyword_match_and_incident_is_created(): void
    {
        NotificationFacade::fake();
        Setting::set('judol_ai_enabled', '1');

        $this->app->instance(JudolAiClassifier::class, new class(new JudolAiSettings) extends JudolAiClassifier
        {
            public function classify(string $url, string $text, array $matchedKeywords, array $imageUrls = []): array
            {
                return ['is_judol' => true, 'confidence' => 0.98, 'reasoning' => 'Konten promosi slot.'];
            }
        });

        Http::fake([
            'ai-confirm-test.example' => Http::response('<title>Slot Gacor Maxwin</title><body>Bandar togel dan rtp live tertinggi.</body>', 200),
            'ai-confirm-test.example/sitemap.xml' => Http::response('', 404),
        ]);

        $website = Website::factory()->create(['url' => 'http://ai-confirm-test.example']);

        $created = (new JudolScanner)->scan($website);

        $this->assertSame(1, $created);
        $this->assertSame(1, JudolDetection::where('website_id', $website->id)->count());
    }

    public function test_a_failed_ai_call_does_not_suppress_a_keyword_detection(): void
    {
        // Regression: JudolAiClassifier must report failure as is_judol => null
        // ("unknown"), and JudolScanner must keep trusting the keyword match in
        // that case - not treat "AI unavailable" as "AI denies it".
        NotificationFacade::fake();
        Setting::set('judol_ai_enabled', '1');

        $this->app->instance(JudolAiClassifier::class, new class(new JudolAiSettings) extends JudolAiClassifier
        {
            public function classify(string $url, string $text, array $matchedKeywords, array $imageUrls = []): array
            {
                return ['is_judol' => null, 'confidence' => 0.0, 'reasoning' => 'AI unavailable: simulated failure'];
            }
        });

        Http::fake([
            'ai-down-test.example' => Http::response('<title>Slot Gacor Maxwin</title><body>Bandar togel dan rtp live tertinggi.</body>', 200),
            'ai-down-test.example/sitemap.xml' => Http::response('', 404),
        ]);

        $website = Website::factory()->create(['url' => 'http://ai-down-test.example']);

        $created = (new JudolScanner)->scan($website);

        $this->assertSame(1, $created);
        $this->assertSame(1, JudolDetection::where('website_id', $website->id)->count());
    }

    public function test_a_dynamically_added_keyword_not_in_config_is_still_detected(): void
    {
        // A keyword added via the Deteksi Judol page (App\Models\JudolKeyword) that
        // doesn't exist in config/judol.php's static list must still be matched -
        // that's the whole point of making keywords manageable at runtime.
        JudolKeyword::create(['keyword' => 'promo unik buatan tes', 'is_active' => true]);

        Http::fake([
            'dynamic-keyword-test.example' => Http::response('<title>Promo Unik Buatan Tes</title>', 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://dynamic-keyword-test.example']);

        $matched = (new JudolScanner)->scanUrlNow($website, 'http://dynamic-keyword-test.example');

        $this->assertContains('promo unik buatan tes', $matched);
    }

    public function test_an_inactive_keyword_is_not_matched(): void
    {
        JudolKeyword::create(['keyword' => 'frasa nonaktif tes', 'is_active' => false]);

        Http::fake([
            'inactive-keyword-test.example' => Http::response('<title>Frasa Nonaktif Tes</title>', 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://inactive-keyword-test.example']);

        $matched = (new JudolScanner)->scanUrlNow($website, 'http://inactive-keyword-test.example');

        $this->assertNotContains('frasa nonaktif tes', $matched);
    }

    public function test_a_forced_recheck_removes_a_now_stale_detection(): void
    {
        // Regression: removing a too-generic keyword (e.g. a bare "slot") after a page
        // was already flagged used to leave the stale detection sitting forever, since
        // the automatic crawl never re-evaluates an already-flagged URL and a forced
        // recheck that no longer matched just silently left the old row untouched.
        NotificationFacade::fake();
        $keyword = JudolKeyword::create(['keyword' => 'frasa stale tes', 'is_active' => true]);

        Http::fake([
            'stale-detection-test.example' => Http::response('<title>Frasa Stale Tes</title>', 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://stale-detection-test.example']);
        $matched = (new JudolScanner)->scanUrlNow($website, 'http://stale-detection-test.example');
        $this->assertContains('frasa stale tes', $matched);

        $detection = JudolDetection::where('website_id', $website->id)->first();
        $incidentId = $detection->incident_id;
        $this->assertNotNull($detection);

        // The keyword that caused the match gets removed - the page itself never changed.
        $keyword->delete();

        $matchedAfter = (new JudolScanner)->scanUrlNow($website, 'http://stale-detection-test.example');

        $this->assertSame([], $matchedAfter);
        $this->assertDatabaseMissing('judol_detections', ['id' => $detection->id]);
        $this->assertSame('resolved', Incident::find($incidentId)->status);
    }

    public function test_prune_stale_detections_clears_rows_whose_matched_keywords_are_no_longer_active(): void
    {
        // No network fetch involved - matched_keywords was already recorded at scan
        // time, so removing/deactivating a keyword can be reconciled locally and
        // immediately, without waiting for someone to manually "Cek Ulang" every row.
        $website = Website::factory()->create();

        $stillValid = JudolDetection::create([
            'website_id' => $website->id, 'url' => 'http://x.example/a',
            'matched_keywords' => ['slot gacor', 'togel online'], 'status' => 'new', 'detected_at' => now(),
        ]);
        $nowStale = JudolDetection::create([
            'website_id' => $website->id, 'url' => 'http://x.example/b',
            'matched_keywords' => ['frasa dihapus tes'], 'status' => 'new', 'detected_at' => now(),
        ]);

        JudolKeyword::create(['keyword' => 'frasa dihapus tes', 'is_active' => true])->delete();

        $cleared = app(JudolScanner::class)->pruneStaleDetections();

        $this->assertSame(1, $cleared);
        $this->assertDatabaseHas('judol_detections', ['id' => $stillValid->id]);
        $this->assertDatabaseMissing('judol_detections', ['id' => $nowStale->id]);
    }

    public function test_removing_a_keyword_via_the_page_auto_prunes_stale_detections(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();
        $keyword = JudolKeyword::create(['keyword' => 'frasa akan dihapus', 'is_active' => true]);
        JudolDetection::create([
            'website_id' => $website->id, 'url' => 'http://x.example/c',
            'matched_keywords' => ['frasa akan dihapus'], 'status' => 'new', 'detected_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('removeKeyword', $keyword->uuid)
            ->assertDispatched('toast', fn (string $event, array $params) => str_contains($params['message'], '1 temuan yang sudah tidak valid ikut dibersihkan'));

        $this->assertSame(0, JudolDetection::count());
    }

    public function test_a_site_down_less_than_a_day_is_not_offline(): void
    {
        $website = Website::factory()->create(['last_status' => 'down']);
        Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now()->subHours(2),
        ]);

        $this->assertFalse($website->isOffline());
    }

    public function test_a_site_down_for_over_a_day_is_offline(): void
    {
        $website = Website::factory()->create(['last_status' => 'down']);
        Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now()->subDays(2),
        ]);

        $this->assertTrue($website->isOffline());
    }

    public function test_a_site_that_is_up_is_never_offline_even_with_an_old_open_incident(): void
    {
        $website = Website::factory()->create(['last_status' => 'up']);
        Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now()->subDays(2),
        ]);

        $this->assertFalse($website->isOffline());
    }

    public function test_offline_ids_bulk_matches_isoffline_for_a_whole_list(): void
    {
        // One query for the whole list instead of one per row - must agree with the
        // per-record isOffline() it replaces in list views.
        $longDown = Website::factory()->create(['last_status' => 'down']);
        Incident::create([
            'website_id' => $longDown->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now()->subDays(2),
        ]);

        $recentDown = Website::factory()->create(['last_status' => 'down']);
        Incident::create([
            'website_id' => $recentDown->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now()->subHours(2),
        ]);

        $up = Website::factory()->create(['last_status' => 'up']);

        $websites = Website::all();
        $offlineIds = Website::offlineIds($websites);

        $this->assertSame([$longDown->id], array_keys($offlineIds));
        $this->assertTrue($longDown->isOffline());
        $this->assertFalse($recentDown->isOffline());
        $this->assertFalse($up->isOffline());
    }

    public function test_status_key_reflects_up_down_offline_internal_and_unknown(): void
    {
        $this->assertSame('up', (new Website(['last_status' => 'up']))->statusKey());
        $this->assertSame('down', (new Website(['last_status' => 'down']))->statusKey());
        $this->assertSame('offline', (new Website(['last_status' => 'down']))->statusKey(isOffline: true));
        $this->assertSame('unknown', (new Website(['last_status' => 'unknown']))->statusKey());

        // internal_only wins over "offline" even after a long streak - there's no
        // "it's been a while, calm down" distinction to make for a site that was
        // never expected to be reachable from here in the first place.
        $internalDown = new Website(['last_status' => 'down', 'is_internal_only' => true]);
        $this->assertSame('internal', $internalDown->statusKey());
        $this->assertSame('internal', $internalDown->statusKey(isOffline: true));

        // internal_only on an UP site is just... up. The flag only changes how a
        // failure reads, not a success.
        $internalUp = new Website(['last_status' => 'up', 'is_internal_only' => true]);
        $this->assertSame('up', $internalUp->statusKey());
    }

    public function test_check_websites_command_only_dispatches_due_websites(): void
    {
        Queue::fake();

        Website::factory()->create(['last_checked_at' => now(), 'check_interval_minutes' => 60]);
        $due = Website::factory()->create(['last_checked_at' => now()->subHours(2), 'check_interval_minutes' => 60]);

        $this->artisan('websites:check')->assertSuccessful();

        Queue::assertPushed(CheckWebsiteJob::class, 1);
        Queue::assertPushed(fn (CheckWebsiteJob $job) => $job->website->is($due));
    }

    public function test_scan_judol_command_only_dispatches_websites_due_for_a_scan(): void
    {
        // Regression: ScanJudol used to dispatch a full-site crawl for every active
        // website on every schedule tick (no interval gating at all, unlike
        // CheckWebsites) - with the schedule firing every minute, the queue grew
        // unbounded and starved every other job behind it, including uptime checks
        // for newly added sites.
        Queue::fake();

        // last_status 'up' throughout: this covers interval gating, and a crawl is
        // only dispatched for a site the uptime probe has found reachable (see
        // Website::isScannable()).
        Website::factory()->create(['judol_scanned_at' => now(), 'judol_scan_interval_minutes' => 60, 'last_status' => 'up']);
        $due = Website::factory()->create(['judol_scanned_at' => now()->subHours(2), 'judol_scan_interval_minutes' => 60, 'last_status' => 'up']);
        $neverScanned = Website::factory()->create(['judol_scanned_at' => null, 'last_status' => 'up']);

        $this->artisan('websites:scan-judol')->assertSuccessful();

        Queue::assertPushed(ScanWebsiteForJudolJob::class, 2);
        Queue::assertPushed(fn (ScanWebsiteForJudolJob $job) => $job->website->is($due));
        Queue::assertPushed(fn (ScanWebsiteForJudolJob $job) => $job->website->is($neverScanned));
    }

    public function test_a_completed_scan_records_when_it_ran(): void
    {
        Http::fake([
            'scanned-at-test.example' => Http::response('<title>Halaman Biasa</title>', 200),
            'scanned-at-test.example/sitemap.xml' => Http::response('', 404),
        ]);

        $website = Website::factory()->create(['url' => 'http://scanned-at-test.example', 'judol_scanned_at' => null]);
        $this->assertTrue($website->isDueForJudolScan());

        (new JudolScanner)->scan($website);

        $website->refresh();
        $this->assertNotNull($website->judol_scanned_at);
        $this->assertFalse($website->isDueForJudolScan());
    }
}
