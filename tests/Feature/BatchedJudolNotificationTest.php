<?php

namespace Tests\Feature;

use App\Models\JudolKeyword;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Notifications\JudolContentDetected;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

class BatchedJudolNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scan_that_flags_several_pages_sends_one_notification_not_one_each(): void
    {
        NotificationFacade::fake();
        JudolKeyword::firstOrCreate(['keyword' => 'slot gacor'], ['is_active' => true]);
        $user = User::factory()->create();

        $spam = '<title>Slot Gacor</title>';
        Http::fake([
            'batch-test.example/sitemap.xml' => Http::response('', 404),
            'batch-test.example/a.html' => Http::response($spam, 200),
            'batch-test.example/b.html' => Http::response($spam, 200),
            'batch-test.example/c.html' => Http::response($spam, 200),
            'batch-test.example' => Http::response(
                '<html><body><a href="/a.html">a</a><a href="/b.html">b</a><a href="/c.html">c</a></body></html>',
                200
            ),
        ]);

        $website = Website::factory()->create(['url' => 'http://batch-test.example']);

        $created = (new JudolScanner)->scan($website);

        $this->assertSame(3, $created, 'three separate pages were flagged');
        NotificationFacade::assertSentToTimes($user, JudolContentDetected::class, 1);
    }

    public function test_the_batched_notification_summarizes_the_pages_it_covers(): void
    {
        NotificationFacade::fake();
        JudolKeyword::firstOrCreate(['keyword' => 'slot gacor'], ['is_active' => true]);
        $user = User::factory()->create();

        $spam = '<title>Slot Gacor</title>';
        Http::fake([
            'summary-test.example/sitemap.xml' => Http::response('', 404),
            'summary-test.example/a.html' => Http::response($spam, 200),
            'summary-test.example/b.html' => Http::response($spam, 200),
            'summary-test.example' => Http::response('<html><body><a href="/a.html">a</a><a href="/b.html">b</a></body></html>', 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://summary-test.example', 'name' => 'Situs Batch']);
        (new JudolScanner)->scan($website);

        NotificationFacade::assertSentTo($user, JudolContentDetected::class, function ($notification) use ($user) {
            $data = $notification->toArray($user);

            return $data['count'] === 2
                && $data['title'] === '2 halaman judol baru di Situs Batch'
                && count($data['items']) === 2;
        });
    }

    public function test_a_single_finding_keeps_the_original_singular_wording(): void
    {
        NotificationFacade::fake();
        JudolKeyword::firstOrCreate(['keyword' => 'slot gacor'], ['is_active' => true]);
        $user = User::factory()->create();

        Http::fake([
            'single-test.example/sitemap.xml' => Http::response('', 404),
            'single-test.example' => Http::response('<title>Slot Gacor</title>', 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://single-test.example', 'name' => 'Situs Tunggal']);
        (new JudolScanner)->scan($website);

        NotificationFacade::assertSentTo($user, JudolContentDetected::class, function ($notification) use ($user) {
            $data = $notification->toArray($user);

            return $data['count'] === 1 && $data['title'] === 'Konten judi online di Situs Tunggal';
        });
    }

    public function test_a_clean_scan_notifies_nobody(): void
    {
        NotificationFacade::fake();
        JudolKeyword::firstOrCreate(['keyword' => 'slot gacor'], ['is_active' => true]);
        User::factory()->create();

        Http::fake([
            'clean-test.example/sitemap.xml' => Http::response('', 404),
            'clean-test.example' => Http::response('<title>Halaman Biasa</title>', 200),
        ]);

        (new JudolScanner)->scan(Website::factory()->create(['url' => 'http://clean-test.example']));

        NotificationFacade::assertNothingSent();
    }

    public function test_one_email_goes_out_listing_every_flagged_page(): void
    {
        // Mailables dispatched through a notification aren't visible to Mail::fake()
        // (see MailNotificationTest), so the email is asserted where it is actually
        // built: the notification's own toMail().
        NotificationFacade::fake();
        Setting::set('mail_enabled', '1');
        Setting::set('mail_host', 'smtp.example.com');
        Setting::set('mail_from_address', 'noreply@example.com');

        JudolKeyword::firstOrCreate(['keyword' => 'slot gacor'], ['is_active' => true]);
        $user = User::factory()->create();

        $spam = '<title>Slot Gacor</title>';
        Http::fake([
            'mail-batch.example/sitemap.xml' => Http::response('', 404),
            'mail-batch.example/a.html' => Http::response($spam, 200),
            'mail-batch.example/b.html' => Http::response($spam, 200),
            'mail-batch.example' => Http::response('<html><body><a href="/a.html">a</a><a href="/b.html">b</a></body></html>', 200),
        ]);

        (new JudolScanner)->scan(Website::factory()->create(['url' => 'http://mail-batch.example']));

        NotificationFacade::assertSentToTimes($user, JudolContentDetected::class, 1);
        NotificationFacade::assertSentTo($user, JudolContentDetected::class, function ($notification) use ($user) {
            $this->assertContains('mail', $notification->via($user), 'email channel is active');

            $body = $notification->toMail($user)->render();

            return str_contains($body, 'mail-batch.example/a.html')
                && str_contains($body, 'mail-batch.example/b.html');
        });
    }
}
