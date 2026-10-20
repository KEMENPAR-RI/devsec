<?php

namespace Tests\Feature;

use App\Models\DefacementFinding;
use App\Models\MaintenanceWindow;
use App\Models\PageChange;
use App\Models\PageSnapshot;
use App\Models\Website;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Perubahan Konten rides on the judol crawl: the first crawl is the baseline,
 * later crawls record what was added/removed - never noise, never a failed fetch.
 */
class ContentChangeMonitoringTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{0: int, 1: string, 2?: array<string, string>}> path => [status, html, headers] */
    protected array $pages = [];

    protected Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '/';
            [$status, $html, $headers] = ($this->pages[$path] ?? [404, '']) + [2 => []];

            return Http::response($html, $status, $headers);
        });

        $this->website = Website::factory()->create(['url' => 'https://dinas.example']);
    }

    public function test_the_first_crawl_is_a_baseline_without_events(): void
    {
        $this->serve('/', $this->home(['/profil']));
        $this->serve('/profil', $this->page('Profil Dinas', ['Dinas Pendidikan melayani seluruh sekolah di kabupaten ini.']));

        $this->crawl();

        $this->assertSame(2, PageSnapshot::count());
        $this->assertSame(0, PageChange::count());
    }

    public function test_an_unchanged_recrawl_records_nothing(): void
    {
        $this->serve('/', $this->home());
        $this->crawl();
        $this->crawl();

        $this->assertSame(0, PageChange::count());
    }

    public function test_dates_counters_and_scripts_alone_do_not_count_as_a_change(): void
    {
        $this->serve('/', $this->home([], 'Diperbarui 12 Januari 2026 pukul 10:15 WIB. Dilihat 1.203 kali.', 'var nonce = "a1b2c3";'));
        $this->crawl();

        $this->serve('/', $this->home([], 'Diperbarui 14 Januari 2026 pukul 08:02 WIB. Dilihat 1.988 kali.', 'var nonce = "z9y8x7";'));
        $this->crawl();

        $this->assertSame(0, PageChange::count());
    }

    public function test_a_text_change_records_one_event_with_what_was_added_and_removed(): void
    {
        $this->serve('/', $this->home());
        $this->crawl();
        $baselineAt = PageSnapshot::firstOrFail()->last_crawled_at;

        $this->serve('/', $this->home([], 'Pendaftaran beasiswa prestasi dibuka mulai minggu depan untuk siswa SMA.'), ['Last-Modified' => 'Wed, 14 Jan 2026 08:00:00 GMT']);
        $this->crawl();
        $this->crawl();

        $change = PageChange::sole();

        $this->assertSame('changed', $change->type);
        $this->assertSame('https://dinas.example', $change->url);
        $this->assertSame($this->website->id, $change->website_id);
        $this->assertContains('Pendaftaran beasiswa prestasi dibuka mulai minggu depan untuk siswa SMA.', $change->added);
        $this->assertContains('Pengumuman jadwal ujian sekolah tahun ajaran baru telah terbit.', $change->removed);
        $this->assertNotNull($change->detected_at);
        $this->assertTrue($change->previous_crawled_at->equalTo($baselineAt));
        $this->assertSame('2026-01-14', $change->page_last_modified_at->utc()->format('Y-m-d'));
    }

    public function test_a_new_page_is_marked_added_not_changed(): void
    {
        $this->serve('/', $this->home(['/profil']));
        $this->serve('/profil', $this->page('Profil Dinas', ['Dinas Pendidikan melayani seluruh sekolah di kabupaten ini.']));
        $this->crawl();

        $this->serve('/', $this->home(['/profil', '/agenda']));
        $this->serve('/agenda', $this->page('Agenda', ['Rapat koordinasi kepala sekolah dilaksanakan di aula dinas.']));
        $this->crawl();

        $change = PageChange::where('url', 'https://dinas.example/agenda')->sole();
        $this->assertSame('added', $change->type);
    }

    public function test_a_page_answering_404_is_marked_removed(): void
    {
        $this->serve('/', $this->home(['/profil']));
        $this->serve('/profil', $this->page('Profil Dinas', ['Dinas Pendidikan melayani seluruh sekolah di kabupaten ini.']));
        $this->crawl();

        unset($this->pages['/profil']);
        $this->crawl();

        $change = PageChange::where('type', 'removed')->sole();
        $this->assertSame('http_404', $change->meta_changes['reason']);
    }

    public function test_a_page_no_longer_linked_is_missing_only_after_three_complete_crawls(): void
    {
        $this->serve('/', $this->home(['/profil']));
        $this->serve('/profil', $this->page('Profil Dinas', ['Dinas Pendidikan melayani seluruh sekolah di kabupaten ini.']));
        $this->crawl();

        $this->serve('/', $this->home());
        $this->crawl();
        $this->crawl();
        $this->assertSame(0, PageChange::count());

        $this->crawl();

        $change = PageChange::sole();
        $this->assertSame('removed', $change->type);
        $this->assertSame('not_reached', $change->meta_changes['reason']);
        $this->assertSame('missing', PageSnapshot::where('url', 'https://dinas.example/profil')->value('status'));
    }

    public function test_a_failing_page_is_not_reported_removed_and_keeps_its_baseline(): void
    {
        $this->serve('/', $this->home(['/profil']));
        $this->serve('/profil', $this->page('Profil Dinas', ['Dinas Pendidikan melayani seluruh sekolah di kabupaten ini.']));
        $this->crawl();
        $hash = PageSnapshot::where('url', 'https://dinas.example/profil')->value('content_hash');

        // Timeout-ish 5xx, then a WAF 403, then a 200 bot-challenge page - several crawls in a row.
        foreach ([[503, 'Service Unavailable'], [403, 'Forbidden'], [200, '<html><head><title>Just a moment...</title></head><body><p>Checking your browser before accessing.</p></body></html>'], [500, '']] as [$status, $html]) {
            $this->serve('/profil', $html, [], $status);
            $this->crawl();
        }

        $snapshot = PageSnapshot::where('url', 'https://dinas.example/profil')->sole();

        $this->assertSame(0, PageChange::count());
        $this->assertSame('active', $snapshot->status);
        $this->assertSame($hash, $snapshot->content_hash);
    }

    public function test_a_failing_homepage_does_not_move_anything(): void
    {
        $this->serve('/', $this->home());
        $this->crawl();

        $this->serve('/', '', [], 500);
        $this->crawl();

        $this->assertSame(0, PageChange::count());
        $this->assertSame('active', PageSnapshot::sole()->status);
    }

    public function test_a_site_under_maintenance_is_not_observed(): void
    {
        $this->serve('/', $this->home());
        $this->crawl();

        MaintenanceWindow::create([
            'website_id' => $this->website->id,
            'reason' => 'Migrasi',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);
        $this->serve('/', $this->home([], 'Konten baru yang ditulis saat situs dalam pemeliharaan terjadwal.'));
        $this->crawl();

        $this->assertSame(0, PageChange::count());
    }

    // --- Retention ----------------------------------------------------------

    public function test_prune_drops_history_past_retention_only(): void
    {
        $old = PageChange::factory()->create(['website_id' => $this->website->id, 'detected_at' => now()->subDays(100)]);
        $recent = PageChange::factory()->create(['website_id' => $this->website->id, 'detected_at' => now()->subDays(10)]);
        $staleSnapshot = PageSnapshot::factory()->create(['website_id' => $this->website->id, 'last_crawled_at' => now()->subDays(100)]);
        $freshSnapshot = PageSnapshot::factory()->create(['website_id' => $this->website->id]);
        $closed = DefacementFinding::factory()->recovered()->create(['website_id' => $this->website->id, 'last_seen_at' => now()->subDays(100)]);
        $open = DefacementFinding::factory()->create(['website_id' => $this->website->id, 'url' => 'https://dinas.example/x', 'url_hash' => sha1('https://dinas.example/x'), 'last_seen_at' => now()->subDays(100)]);

        $this->artisan('websites:prune-content')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $this->assertModelMissing($staleSnapshot);
        $this->assertModelExists($freshSnapshot);
        $this->assertModelMissing($closed);
        $this->assertModelExists($open);
    }

    public function test_prune_honours_the_days_option_and_refuses_nonsense(): void
    {
        $change = PageChange::factory()->create(['website_id' => $this->website->id, 'detected_at' => now()->subDays(10)]);

        $this->artisan('websites:prune-content', ['--days' => '0'])->assertFailed();
        $this->artisan('websites:prune-content', ['--days' => 'abc'])->assertFailed();
        $this->assertModelExists($change);

        $this->artisan('websites:prune-content', ['--days' => '5'])->assertSuccessful();
        $this->assertModelMissing($change);
    }

    // --- Helpers ------------------------------------------------------------

    protected function crawl(): void
    {
        (new JudolScanner)->scan($this->website->fresh());
        $this->travel(7)->hours();
    }

    protected function serve(string $path, string $html, array $headers = [], int $status = 200): void
    {
        $this->pages[$path] = [$status, $html, $headers];
    }

    /** @param  array<int, string>  $links */
    protected function home(array $links = [], string $extra = '', string $script = ''): string
    {
        $nav = collect($links)->map(fn (string $l) => "<a href=\"{$l}\">".ltrim($l, '/').'</a>')->implode(' ');

        return $this->page('Dinas Pendidikan Kabupaten', [
            'Selamat datang di portal resmi Dinas Pendidikan Kabupaten.',
            $extra !== '' && str_starts_with($extra, 'Pendaftaran') ? $extra : 'Pengumuman jadwal ujian sekolah tahun ajaran baru telah terbit.',
            'Layanan legalisir ijazah kini dapat diajukan secara daring.',
            $extra !== '' && ! str_starts_with($extra, 'Pendaftaran') ? $extra : '',
        ], "<nav>{$nav}</nav>", $script);
    }

    /** @param  array<int, string>  $paragraphs */
    protected function page(string $title, array $paragraphs, string $nav = '', string $script = ''): string
    {
        $body = collect($paragraphs)->filter()->map(fn (string $p) => "<p>{$p}</p>")->implode('');

        return "<html><head><title>{$title}</title><script>{$script}</script></head><body>{$nav}{$body}</body></html>";
    }
}
