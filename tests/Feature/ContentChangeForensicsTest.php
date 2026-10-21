<?php

namespace Tests\Feature;

use App\Livewire\ContentChanges\Index as ContentChangesIndex;
use App\Livewire\ContentChanges\Show as ContentChangesShow;
use App\Models\AuditLog;
use App\Models\PageChange;
use App\Models\PageChangeForensic;
use App\Models\PageSnapshot;
use App\Models\Role;
use App\Models\User;
use App\Models\Website;
use App\Services\Monitoring\ContentForensics;
use App\Services\Monitoring\JudolScanner;
use App\Services\Monitoring\PageContentMonitor;
use App\Services\Monitoring\PageRawStore;
use App\Services\Monitoring\PageTextExtractor;
use App\Services\Monitoring\TextNormalizer;
use App\Support\ElementIdentity;
use App\Support\VisibleText;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Detail forensik Perubahan Konten: locations, raw snippets, non-text changes,
 * chained hashes, limits, raw copies on disk, and the detail/evidence access.
 */
class ContentChangeForensicsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{0: int, 1: string, 2: array<string, mixed>}> */
    protected array $pages = [];

    protected Website $website;

    protected const URL = 'https://dinas.example';

    protected const WELCOME = 'Selamat datang di portal resmi Dinas Pendidikan Kabupaten.';

    protected const EXAM = 'Pengumuman jadwal ujian sekolah tahun ajaran baru telah terbit.';

    protected const PROMO = 'Daftar program beasiswa terpercaya hari ini juga sangat mudah.';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('local');
        // These tests cover the plain text/element diff events, off by default (ADR 0002).
        config(['monitoring.record_text_only_changes' => true]);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '/';
            [$status, $html, $headers] = $this->pages[$path] ?? [404, '', []];

            return Http::response($html, $status, $headers);
        });

        $this->website = Website::factory()->create(['url' => self::URL]);
    }

    // --- Location, line, snippet ---------------------------------------------

    public static function lineEndings(): array
    {
        return ['LF' => ["\n"], 'CRLF' => ["\r\n"]];
    }

    #[DataProvider('lineEndings')]
    public function test_text_changes_are_located_with_unique_path_line_and_raw_snippets(string $eol): void
    {
        $before = $this->doc([
            '<div id="main">',
            '  <p>'.self::WELCOME.'</p>',
            '  <p>'.self::EXAM.'</p>',
            '</div>',
        ], eol: $eol);
        $after = $this->doc([
            '<div id="main">',
            '  <p>'.self::WELCOME.'</p>',
            '  <p class="promo">Daftar <b>program beasiswa</b> terpercaya hari ini juga sangat mudah.</p>',
            '</div>',
        ], eol: $eol);

        $this->serve($before);
        $this->crawl();
        $this->serve($after);
        $this->crawl();

        $change = PageChange::sole();
        $payload = $change->forensic->payload();

        $this->assertSame('text', $change->content_kind);
        $this->assertSame('SHA-256', $change->hash_algorithm);
        $this->assertSame(hash('sha256', $before), $change->body_hash_before);
        $this->assertSame(hash('sha256', $after), $change->body_hash_after);
        $this->assertSame(200, $change->http_status);
        $this->assertNull($change->final_url);
        $this->assertSame(0, $change->redirect_count);

        $added = $this->item($payload, 'text', 'added');
        $this->assertSame(self::PROMO, $added['text']);
        $this->assertNull($added['before']);
        $this->assertSame('not_applicable', $added['before_unavailable']);
        $this->assertLocates($after, $added['after'], self::PROMO);
        $this->assertSame('p', $added['after']['tag']);
        $this->assertSame(['class' => 'promo'], $added['after']['attrs']);
        $this->assertSame('#main > p:nth-of-type(2)', $added['after']['css']);
        $this->assertSame($this->lineOf($after, 'Daftar <b>'), $added['after']['line']);
        $this->assertSame('exact', $added['after']['line_precision']);
        $this->assertSame('<p class="promo">Daftar <b>program beasiswa</b> terpercaya hari ini juga sangat mudah.</p>', $added['after']['snippet']);
        $this->assertFalse($added['after']['snippet_truncated']);
        $this->assertSame('raw', $added['after']['snippet_source']);

        $removed = $this->item($payload, 'text', 'removed');
        $this->assertNull($removed['after']);
        $this->assertNull($removed['before_unavailable']);
        $this->assertLocates($before, $removed['before'], self::EXAM);
        $this->assertSame($this->lineOf($before, self::EXAM), $removed['before']['line']);
        $this->assertSame('<p>'.self::EXAM.'</p>', $removed['before']['snippet']);
    }

    public function test_lines_are_exact_on_a_minified_page_and_past_line_65535(): void
    {
        $minified = '<html><head><title>Dinas</title></head><body><div><p>'.self::WELCOME.'</p></div></body></html>';
        $this->serve($minified);
        $this->crawl();

        $padding = str_repeat("\n", 70000);
        $long = "<html><head><title>Dinas</title></head><body>{$padding}<div><p>".self::WELCOME."</p>\n<p>".self::PROMO.'</p></div></body></html>';
        $this->serve($long);
        $this->crawl();

        $payload = PageChange::sole()->forensic->payload();
        $added = $this->item($payload, 'text', 'added');

        $this->assertSame(70002, $added['after']['line']);
        $this->assertSame($this->lineOf($long, self::PROMO), $added['after']['line']);
        $this->assertSame('exact', $added['after']['line_precision']);
        $this->assertLocates($long, $added['after'], self::PROMO);

        // And back to one line: everything sits on line 1.
        $this->serve(str_replace(self::WELCOME, self::EXAM, $minified));
        $this->crawl();

        $removed = $this->item(PageChange::latest('id')->first()->forensic->payload(), 'text', 'removed');
        $this->assertSame(70001, $removed['before']['line']);
        $added = $this->item(PageChange::latest('id')->first()->forensic->payload(), 'text', 'added');
        $this->assertSame(1, $added['after']['line']);
    }

    public function test_a_long_element_is_cut_at_1000_characters_around_the_text(): void
    {
        $filler = str_repeat('Kalimat pengisi panjang tanpa arti khusus apapun. ', 60);
        $this->serve($this->doc(['<div id="artikel"><p>'.self::WELCOME.'</p></div>']));
        $this->crawl();
        $after = $this->doc(['<div id="artikel"><p>'.self::WELCOME.'</p><p>'.$filler.self::PROMO.'</p></div>']);
        $this->serve($after);
        $this->crawl();

        $location = $this->item(PageChange::sole()->forensic->payload(), 'text', 'added')['after'];

        $this->assertSame(1000, mb_strlen($location['snippet']));
        $this->assertTrue($location['snippet_truncated']);
        $this->assertGreaterThan(1000, $location['snippet_length']);
    }

    // --- Non-text --------------------------------------------------------------

    public function test_cache_busters_nonces_and_attribute_order_do_not_make_an_event(): void
    {
        $this->serve($this->doc(['<p>'.self::WELCOME.'</p>'], [
            '<script src="/js/app.js?ver=6.4" nonce="a1b2" async></script>',
            '<script src="https://cdn.lib.example/jquery/3.7.1/jquery.min.js"></script>',
            '<script src="/build/app.3f9a1c2b.js"></script>',
            '<link rel="stylesheet" href="https://cdn.lib.example/css/site.css?v=1">',
        ]));
        $this->crawl();

        $this->serve($this->doc(['<p>'.self::WELCOME.'</p>'], [
            '<script async nonce="z9y8" src="/js/app.js?ver=6.5"></script>',
            '<script src="https://cdn.lib.example/jquery/3.7.2/jquery.min.js" integrity="sha384-x" crossorigin="anonymous"></script>',
            '<script src="/build/app.77aa0c9e.js"></script>',
            '<link href="https://cdn.lib.example/css/site.css?v=2" rel="stylesheet">',
        ]));
        $this->crawl();

        $this->assertSame(0, PageChange::count());
    }

    public function test_script_iframe_link_and_meta_changes_make_an_element_event(): void
    {
        $this->serve($this->doc(
            ['<p>'.self::WELCOME.'</p>', '<a href="https://partner.example/info">Partner</a>'],
            ['<meta name="robots" content="index, follow">']
        ));
        $this->crawl();

        $after = $this->doc(
            [
                '<p>'.self::WELCOME.'</p>',
                '<div style="display:none"><a href="https://promo-partner.example/daftar?ref=1">x</a></div>',
                '<iframe src="https://ads.example/frame"></iframe>',
            ],
            [
                '<meta name="robots" content="noindex,nofollow">',
                '<meta http-equiv="refresh" content="5; url=https://parked.example/landing?x=1">',
                '<script src="https://cdn.asing.example/x.js"></script>',
            ]
        );
        $this->serve($after);
        $this->crawl();

        $change = PageChange::sole();
        $payload = $change->forensic->payload();

        // A foreign script was added: the event is classified as injection (ADR 0002).
        $this->assertSame('injection', $change->content_kind);
        $this->assertNull($change->added);
        $this->assertSame(
            ['meta/added', 'iframe/added', 'script/added', 'link/added', 'link/removed', 'meta/modified'],
            array_map(fn (array $i) => $i['kind'].'/'.$i['change'], $payload['items'])
        );

        $refresh = $payload['items'][0];
        $this->assertSame('refresh', $refresh['identity']);
        $this->assertSame(['before' => null, 'after' => '5;parked.example/landing'], $refresh['value']);
        $this->assertSame('meta', $refresh['after']['tag']);

        $script = $this->item($payload, 'script', 'added');
        $this->assertSame('cdn.asing.example/x.js', $script['identity']);
        $this->assertSame('<script src="https://cdn.asing.example/x.js"></script>', $script['after']['snippet']);
        $this->assertSame($this->lineOf($after, '<script src="https://cdn.asing'), $script['after']['line']);
        $this->assertSame('exact', $script['after']['line_precision']);
        $this->assertLocates($after, $script['after']);

        $hiddenLink = $this->item($payload, 'link', 'added');
        $this->assertSame('promo-partner.example/daftar', $hiddenLink['identity']);
        $this->assertTrue($hiddenLink['after']['hidden']);

        $removedLink = $this->item($payload, 'link', 'removed');
        $this->assertSame('a', $removedLink['before']['tag']);
        $this->assertSame('<a href="https://partner.example/info">Partner</a>', $removedLink['before']['snippet']);

        $robots = $this->item($payload, 'meta', 'modified');
        $this->assertSame(['before' => 'follow,index', 'after' => 'nofollow,noindex'], $robots['value']);
        $this->assertNotNull($robots['before']);
        $this->assertNotNull($robots['after']);
        $this->assertSame(['elements_added' => 4, 'elements_removed' => 1, 'elements_modified' => 1], array_intersect_key($payload['counts'], array_flip(['elements_added', 'elements_removed', 'elements_modified'])));
    }

    public function test_the_final_url_moving_to_another_domain_is_a_redirect_item(): void
    {
        $page = $this->doc(['<p>'.self::WELCOME.'</p>']);
        $this->pages['/beranda'] = [200, $page, []];
        $this->pages['/landing'] = [200, $page, []];
        $this->serve('', ['Location' => 'https://dinas.example/beranda'], 302);
        $this->crawl();

        $this->pages['/beranda'] = [301, '', ['Location' => 'https://parked.example/landing']];
        $this->crawl();

        $change = PageChange::sole();
        $redirect = $change->forensic->payload()['items'][0];

        $this->assertSame('element', $change->content_kind);
        $this->assertSame('https://parked.example/landing', $change->final_url);
        $this->assertSame(2, $change->redirect_count);
        $this->assertSame('redirect', $redirect['kind']);
        $this->assertSame(['before' => 'https://dinas.example/beranda', 'after' => 'https://parked.example/landing'], $redirect['value']);
        $this->assertSame('not_applicable', $redirect['before_unavailable']);
    }

    public function test_a_redirect_history_header_sent_by_the_site_itself_is_ignored(): void
    {
        $this->serve($this->doc(['<p>'.self::WELCOME.'</p>']));
        $this->crawl();
        $this->serve($this->doc(['<p>'.self::WELCOME.'</p>']), ['X-Guzzle-Redirect-History' => ['https://parked.example/landing', self::URL]]);
        $this->crawl();

        $this->assertSame(0, PageChange::count());
        $this->assertSame(0, PageSnapshot::sole()->redirect_count);
        $this->assertSame(self::URL, PageSnapshot::sole()->final_url);
    }

    public function test_a_spoofed_history_behind_a_real_redirect_does_not_inflate_the_count(): void
    {
        $page = $this->doc(['<p>'.self::WELCOME.'</p>']);
        $this->pages['/beranda'] = [200, $page, ['X-Guzzle-Redirect-History' => ['https://a.example/', 'https://b.example/', 'https://dinas.example/beranda']]];
        $this->serve('', ['Location' => 'https://dinas.example/beranda'], 302);
        $this->crawl();

        $snapshot = PageSnapshot::sole();
        $this->assertSame(1, $snapshot->redirect_count);
        $this->assertSame('https://dinas.example/beranda', $snapshot->final_url);
    }

    public function test_identity_normalization_rules(): void
    {
        $this->assertSame('cdn.example/js/{v}/app.{h}.js', ElementIdentity::normalizeUrl('HTTPS://user:pw@CDN.example:443/js/v1.2.3/app-3f9a1c2b77.js?ver=1#x', null));
        $this->assertSame('site.example/a/c', ElementIdentity::normalizeUrl('../c/?q=1', 'https://site.example/a/b/page'));
        $this->assertSame('site.example:8080/x', ElementIdentity::normalizeUrl('//site.example:8080//x/', 'https://dinas.example'));
        $this->assertNull(ElementIdentity::normalizeUrl('javascript:alert(1)', 'https://dinas.example'));
        $this->assertSame('0;judi.example/', ElementIdentity::metaValue('refresh', "0;URL='https://judi.example/?a=b'"));
        $this->assertSame('follow,index', ElementIdentity::metaValue('robots', 'INDEX, follow, index'));
    }

    // --- Integrity ---------------------------------------------------------------

    public function test_the_before_hash_chains_to_the_previous_after_hash(): void
    {
        $pages = [
            $this->doc(['<p>'.self::WELCOME.'</p>']),
            $this->doc(['<p>'.self::WELCOME.'</p>', '<p>'.self::EXAM.'</p>']),
            $this->doc(['<p>'.self::WELCOME.'</p>', '<p>'.self::PROMO.'</p>']),
        ];

        foreach ($pages as $html) {
            $this->serve($html);
            $this->crawl();
        }

        [$first, $second] = PageChange::orderBy('id')->get()->all();

        $this->assertSame(hash('sha256', $pages[0]), $first->body_hash_before);
        $this->assertSame(hash('sha256', $pages[1]), $first->body_hash_after);
        $this->assertSame($first->body_hash_after, $second->body_hash_before);
        $this->assertSame(hash('sha256', $pages[2]), $second->body_hash_after);
        $this->assertSame(hash('sha256', $pages[2]), PageSnapshot::sole()->raw_sha256);
    }

    public function test_forensic_rows_cannot_be_updated(): void
    {
        $forensic = PageChangeForensic::factory()->create();
        $original = $forensic->payload_gz;

        $forensic->payload_bytes = 1;
        $this->assertFalse($forensic->save());
        $this->assertSame($original, $forensic->fresh()->payload_gz);
    }

    // --- Limits ----------------------------------------------------------------------

    public function test_at_most_50_items_are_detailed_and_the_rest_are_counted(): void
    {
        $this->serve($this->doc(['<p>'.self::WELCOME.'</p>']));
        $this->crawl();
        $this->serve($this->doc(['<p>'.self::WELCOME.'</p>', ...array_map(fn (int $i) => '<p>'.$this->sentence($i).'</p>', range(0, 59))]));
        $this->crawl();

        $change = PageChange::sole();
        $payload = $change->forensic->payload();

        $this->assertCount(50, $payload['items']);
        $this->assertSame(60, $payload['counts']['text_added']);
        $this->assertSame(10, $payload['undetailed']);
        $this->assertSame(10, $change->forensic->undetailed_count);
        $this->assertCount(8, $change->added);
    }

    public function test_the_payload_is_trimmed_to_64_kb_by_dropping_the_lowest_priority(): void
    {
        $this->serve($this->doc(['<p>'.self::WELCOME.'</p>']));
        $this->crawl();
        $long = fn (int $i) => '<p>'.$this->sentence($i).' '.str_repeat('Uraian kegiatan panjang sekali. ', 40).'</p>';
        $this->serve($this->doc(['<p>'.self::WELCOME.'</p>', ...array_map($long, range(0, 39))], ['<script src="https://cdn.asing.example/x.js"></script>']));
        $this->crawl();

        $forensic = PageChange::sole()->forensic;
        $payload = $forensic->payload();

        $this->assertLessThanOrEqual(65536, $forensic->payload_bytes);
        $this->assertLessThanOrEqual(65000, strlen($forensic->payload_gz));
        $this->assertLessThan(41, count($payload['items']));
        $this->assertSame($payload['counts']['total'], count($payload['items']) + $payload['undetailed']);
        $this->assertSame('script', $payload['items'][0]['kind']);
    }

    public function test_a_page_over_the_html_limit_is_hashed_but_not_stored_or_located(): void
    {
        config(['monitoring.forensics_max_html_bytes' => 3000]);
        $big = $this->doc(['<p>'.self::WELCOME.'</p>', '<div>'.str_repeat('Isi halaman yang sangat panjang. ', 120).'</div>']);
        $this->serve($big);
        $this->crawl();

        $snapshot = PageSnapshot::sole();
        $this->assertSame(hash('sha256', $big), $snapshot->raw_sha256);
        $this->assertFalse($snapshot->raw_stored);
        $this->assertFalse(Storage::disk('local')->exists(app(PageRawStore::class)->path($snapshot)));

        $this->serve(str_replace(self::WELCOME, self::PROMO, $big));
        $this->crawl();
        $payload = PageChange::sole()->forensic->payload();

        $this->assertSame('too_large', $payload['unavailable']);
        $this->assertSame('too_large', $payload['before_unavailable']);
        $this->assertNull($this->item($payload, 'text', 'added')['after']);
    }

    // --- Baseline rules ---------------------------------------------------------------

    public function test_an_incomplete_page_moves_neither_the_baseline_nor_the_raw_copy(): void
    {
        $html = $this->doc(['<p>'.self::WELCOME.'</p>']);
        $this->serve($html);
        $this->crawl();
        $snapshot = PageSnapshot::sole();
        $snapshot->update(['status' => 'missing', 'missed_crawls' => 2]);
        $this->travel(1)->hours();
        $path = app(PageRawStore::class)->path($snapshot);

        $cut = $this->doc(['<p>'.self::PROMO.'</p>'], ['<script src="https://cdn.asing.example/x.js"></script>']);
        $result = app(PageContentMonitor::class)->observe(
            $this->website,
            self::URL,
            app(PageTextExtractor::class)->extract($cut, self::URL, false),
            response: ['body' => $cut, 'status' => 200, 'final_url' => self::URL, 'redirects' => 0],
        );

        $fresh = $snapshot->fresh();
        $this->assertTrue($fresh->last_crawled_at->gt($snapshot->last_crawled_at));
        $this->assertSame('active', $fresh->status);
        $this->assertSame(0, $fresh->missed_crawls);
        $this->assertNull($result);
        $this->assertSame(0, PageChange::count());
        $this->assertSame($snapshot->content_hash, $fresh->content_hash);
        $this->assertSame($snapshot->elements_hash, $fresh->elements_hash);
        $this->assertSame(hash('sha256', $html), $fresh->raw_sha256);
        $this->assertSame($html, gzdecode(Storage::disk('local')->get($path)));
    }

    public function test_the_first_crawl_with_the_feature_only_seeds_and_marks_before_as_unavailable(): void
    {
        $this->serve($this->doc(['<p>'.self::WELCOME.'</p>']));
        $this->crawl();
        $this->assertSame(0, PageChange::count());

        // A snapshot from before this feature: no inventory, no raw copy.
        PageSnapshot::query()->update(['elements_hash' => null, 'elements' => null, 'raw_sha256' => null, 'raw_stored' => false]);

        $this->serve($this->doc(['<p>'.self::PROMO.'</p>'], ['<script src="https://cdn.asing.example/x.js"></script>']));
        $this->crawl();

        $change = PageChange::sole();
        $payload = $change->forensic->payload();
        // A foreign script was added: the event is classified as injection (ADR 0002).
        $this->assertSame('injection', $change->content_kind);
        $this->assertNull($change->body_hash_before);
        $this->assertSame('no_baseline', $payload['before_unavailable']);
        $this->assertNull($this->item($payload, 'text', 'removed')['before']);
        $this->assertSame('no_baseline', $this->item($payload, 'text', 'removed')['before_unavailable']);
        $this->assertNotNull(PageSnapshot::sole()->elements_hash);
    }

    public function test_a_tampered_raw_copy_is_reported_as_mismatch(): void
    {
        $this->serve($this->doc(['<p>'.self::WELCOME.'</p>']));
        $this->crawl();
        Storage::disk('local')->put(app(PageRawStore::class)->path(PageSnapshot::sole()), gzencode('<p>palsu</p>'));

        $this->serve($this->doc(['<p>'.self::PROMO.'</p>']));
        $this->crawl();

        $this->assertSame('mismatch', PageChange::sole()->forensic->payload()['before_unavailable']);
    }

    // --- Atomic snapshot update ---------------------------------------------------

    public function test_a_failed_forensic_build_keeps_the_event_and_moves_the_whole_snapshot_once(): void
    {
        $this->serve($this->doc(['<p>'.self::WELCOME.'</p>']));
        $this->crawl();

        $this->mock(ContentForensics::class, function ($mock) {
            $mock->shouldReceive('build')->andThrow(new RuntimeException('boom'));
            $mock->shouldReceive('store')->andReturnUsing(fn (...$args) => (new ContentForensics(new PageTextExtractor))->store(...$args));
        });

        $after = $this->doc(['<p>'.self::PROMO.'</p>'], ['<script src="https://cdn.asing.example/x.js"></script>']);
        $this->serve($after);
        $this->crawl();
        $this->crawl();

        $change = PageChange::sole();
        $snapshot = PageSnapshot::sole();
        $this->assertSame('error', $change->forensic->payload()['unavailable']);
        $this->assertSame(hash('sha256', $after), $snapshot->raw_sha256);
        $this->assertSame($change->body_hash_after, $snapshot->raw_sha256);
        $this->assertSame($after, gzdecode(Storage::disk('local')->get(app(PageRawStore::class)->path($snapshot))));
    }

    public function test_a_crash_before_the_event_is_saved_rolls_the_snapshot_back(): void
    {
        $before = $this->doc(['<p>'.self::WELCOME.'</p>']);
        $this->serve($before);
        $this->crawl();
        $original = PageSnapshot::sole();

        $fail = true;
        PageChange::creating(function () use (&$fail) {
            if ($fail) {
                throw new RuntimeException('killed mid-write');
            }
        });

        $after = $this->doc(['<p>'.self::PROMO.'</p>'], ['<script src="https://cdn.asing.example/x.js"></script>']);
        $this->serve($after);
        $this->crawl();

        $snapshot = PageSnapshot::sole();
        $this->assertSame(0, PageChange::count());
        $this->assertSame($original->content_hash, $snapshot->content_hash);
        $this->assertSame($original->raw_sha256, $snapshot->raw_sha256);
        $this->assertSame($original->elements_hash, $snapshot->elements_hash);
        $this->assertSame($original->final_url, $snapshot->final_url);

        // Next crawl records it once; the overwritten copy no longer matches, so "before" is not guessed.
        $fail = false;
        $this->crawl();

        $change = PageChange::sole();
        // A foreign script was added: the event is classified as injection (ADR 0002).
        $this->assertSame('injection', $change->content_kind);
        $this->assertSame(hash('sha256', $before), $change->body_hash_before);
        $this->assertSame('mismatch', $change->forensic->payload()['before_unavailable']);
    }

    public function test_a_lost_raw_copy_of_an_unchanged_page_is_written_again(): void
    {
        $html = $this->doc(['<p>'.self::WELCOME.'</p>']);
        $this->serve($html);
        $this->crawl();
        $path = app(PageRawStore::class)->path(PageSnapshot::sole());
        Storage::disk('local')->delete($path);

        $this->crawl();

        $this->assertSame($html, gzdecode(Storage::disk('local')->get($path)));
        $this->assertTrue(PageSnapshot::sole()->raw_stored);
    }

    public function test_the_payload_is_encoded_once_and_stored_as_built(): void
    {
        $forensics = new ContentForensics(new PageTextExtractor);
        [$payload, $json, $gz] = $forensics->build([], ['added' => [self::PROMO], 'added_total' => 1], [], null, $this->doc(['<p>'.self::PROMO.'</p>']));
        $change = PageChange::factory()->create(['website_id' => $this->website->id]);

        $stored = $forensics->store($change, $payload, $json, $gz);

        $this->assertSame($gz, $stored->fresh()->payload_gz);
        $this->assertSame(strlen($json), $stored->payload_bytes);
        $this->assertSame($payload, $stored->fresh()->payload());
    }

    // --- Prune -----------------------------------------------------------------------

    public function test_prune_removes_forensics_with_their_events_and_sweeps_orphan_files(): void
    {
        $this->serve($this->doc(['<p>'.self::WELCOME.'</p>']));
        $this->crawl();
        $snapshot = PageSnapshot::sole();
        $disk = Storage::disk('local');
        $kept = app(PageRawStore::class)->path($snapshot);

        $orphan = 'page-bodies/'.$this->website->id.'/'.sha1('gone').'.html.gz';
        $goneSite = 'page-bodies/999999/'.sha1('x').'.html.gz';
        $oldTmp = 'page-bodies/'.$this->website->id.'/'.sha1('t').'.html.gz.abcdEF12.tmp';
        $freshTmp = 'page-bodies/'.$this->website->id.'/'.sha1('u').'.html.gz.abcdEF13.tmp';
        $youngOrphan = 'page-bodies/'.$this->website->id.'/'.sha1('crawl-in-progress').'.html.gz';
        foreach ([$orphan, $goneSite, $oldTmp, $freshTmp, $youngOrphan] as $path) {
            $disk->put($path, 'x');
            // Against the (travelled) app clock the sweep uses.
            touch($disk->path($path), now()->subHours(2)->getTimestamp());
        }
        touch($disk->path($freshTmp), now()->getTimestamp());
        touch($disk->path($youngOrphan), now()->subMinutes(10)->getTimestamp());

        $old = PageChange::factory()->create(['website_id' => $this->website->id, 'detected_at' => now()->subDays(100)]);
        PageChangeForensic::factory()->create(['page_change_id' => $old->id]);

        $this->artisan('websites:prune-content')->expectsOutputToContain('3 salinan HTML mentah yatim dihapus.')->assertSuccessful();

        $this->assertSame(0, PageChangeForensic::count());
        $this->assertTrue($disk->exists($kept));
        $this->assertTrue($disk->exists($freshTmp));
        $this->assertTrue($disk->exists($youngOrphan));
        $this->assertFalse($disk->exists($orphan));
        $this->assertFalse($disk->exists($goneSite));
        $this->assertFalse($disk->exists($oldTmp));
    }

    // --- Detail & evidence access --------------------------------------------------

    public function test_detail_and_evidence_are_forbidden_without_view_permission(): void
    {
        $change = PageChange::factory()->create(['website_id' => $this->website->id]);
        $user = $this->userWith(['dashboard.view']);

        $this->actingAs($user)->get(route('perubahan-konten.detail', $change))->assertForbidden();
        $this->actingAs($user)->get(route('perubahan-konten.bukti', $change))->assertForbidden();
        $this->assertSame(0, AuditLog::where('event', 'perubahan-konten.bukti-diunduh')->count());
    }

    public function test_an_unknown_event_is_not_found(): void
    {
        $this->actingAs($this->userWith(['perubahan-konten.view']))
            ->get('/perubahan-konten/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }

    public function test_evidence_is_streamed_as_inert_text_audited_and_never_stored(): void
    {
        $this->serve($this->doc(['<p>'.self::WELCOME.'</p>']));
        $this->crawl();
        $this->serve($this->doc(['<p>'.self::PROMO."\u{200B}</p>"], ['<script src="https://cdn.asing.example/x.js"></script>']));
        $this->crawl();
        $change = PageChange::sole();
        $filesBefore = Storage::disk('local')->allFiles();
        $user = $this->userWith(['perubahan-konten.view']);

        $response = $this->actingAs($user)->get(route('perubahan-konten.bukti', $change));

        $response->assertOk();
        $this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('bukti-perubahan-konten-'.$change->uuid, $response->headers->get('Content-Disposition'));

        $text = $response->streamedContent();
        $this->assertStringStartsWith("BUKTI PERUBAHAN KONTEN\nVersi format: 1\n", $text);
        $this->assertStringContainsString('URL diminta: '.self::URL, $text);
        $this->assertStringContainsString('Hash body sebelum: '.$change->body_hash_before, $text);
        $this->assertStringContainsString('Hash body sesudah: '.$change->body_hash_after, $text);
        $this->assertStringContainsString('oleh '.$user->name.' ('.$user->email.')', $text);
        $this->assertStringContainsString('[U+200B]', $text);
        $this->assertStringContainsString('    | <script src="https://cdn.asing.example/x.js"></script>', $text);
        $this->assertStringNotContainsString("\u{200B}", $text);

        // The closing digest covers everything above it.
        [$body, $digest] = explode('SHA-256 isi di atas: ', $text);
        $this->assertSame(hash('sha256', $body), trim($digest));

        $this->assertSame($filesBefore, Storage::disk('local')->allFiles());
        $audit = AuditLog::where('event', 'perubahan-konten.bukti-diunduh')->sole();
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame(['id' => $change->uuid], $audit->changes);
    }

    public function test_an_event_from_before_the_feature_shows_and_exports_as_unavailable(): void
    {
        $change = PageChange::factory()->create(['website_id' => $this->website->id, 'content_kind' => 'text']);
        $user = $this->userWith(['perubahan-konten.view']);

        $this->actingAs($user)->get(route('perubahan-konten.detail', $change))
            ->assertOk()
            ->assertSee('Detail forensik tidak tersedia');

        $text = $this->actingAs($user)->get(route('perubahan-konten.bukti', $change))->streamedContent();
        $this->assertStringContainsString('Detail forensik tidak tersedia (event dibuat sebelum fitur aktif)', $text);
        $this->assertStringContainsString('Hash body sebelum: tidak tersedia', $text);
        $this->assertStringContainsString('Algoritma hash: tidak tersedia', $text);
    }

    public static function unavailableReasons(): array
    {
        return [
            'legacy' => ['legacy', ['type' => 'changed'], false, 'sebelum fitur forensik aktif', 'event dibuat sebelum fitur aktif'],
            'new page' => ['new_page', ['type' => 'added', 'content_kind' => null, 'hash_algorithm' => 'SHA-256'], false, 'belum ada pembanding', 'halaman baru'],
            'removed page' => ['removed_page', ['type' => 'removed', 'content_kind' => null], false, 'tidak ada konten sesudah', 'halaman hilang'],
            'corrupt' => ['corrupt', ['type' => 'changed', 'hash_algorithm' => 'SHA-256'], true, 'rusak/tidak terbaca', 'data forensik rusak'],
        ];
    }

    #[DataProvider('unavailableReasons')]
    public function test_each_unavailable_reason_is_told_apart_in_the_detail_and_the_export(string $reason, array $attributes, bool $corrupt, string $label, string $exported): void
    {
        $change = PageChange::factory()->create(['website_id' => $this->website->id] + $attributes);

        if ($corrupt) {
            PageChangeForensic::factory()->corrupt()->create(['page_change_id' => $change->id]);
        }

        $user = $this->userWith(['perubahan-konten.view']);

        Livewire::actingAs($user)->test(ContentChangesShow::class, ['change' => $change])
            ->assertViewHas('unavailableReason', $reason)
            ->assertViewHas('detail', null);

        $this->actingAs($user)->get(route('perubahan-konten.detail', $change))
            ->assertOk()
            ->assertSee('Detail forensik tidak tersedia')
            ->assertSee($label);

        $text = $this->actingAs($user)->get(route('perubahan-konten.bukti', $change))->streamedContent();
        $this->assertStringContainsString('Detail forensik tidak tersedia ('.$exported, $text);
    }

    public function test_a_detailed_event_has_no_unavailable_reason(): void
    {
        $change = PageChange::factory()->create(['website_id' => $this->website->id]);
        PageChangeForensic::factory()->create(['page_change_id' => $change->id]);

        Livewire::actingAs($this->userWith(['perubahan-konten.view']))
            ->test(ContentChangesShow::class, ['change' => $change])
            ->assertViewHas('unavailableReason', null);
    }

    public function test_a_complete_event_renders_its_detail(): void
    {
        $this->serve($this->doc(['<p>'.self::WELCOME.'</p>']));
        $this->crawl();
        $this->serve($this->doc(['<p class="promo">'.self::PROMO.'</p>']));
        $this->crawl();
        $change = PageChange::sole();

        $this->actingAs($this->userWith(['perubahan-konten.view']))
            ->get(route('perubahan-konten.detail', $change))
            ->assertOk()
            ->assertSee($change->body_hash_after)
            ->assertSee('/html[1]/body[1]/p[1]')
            ->assertSee(self::PROMO)
            ->assertSee(route('perubahan-konten.bukti', $change));
    }

    public function test_hostile_snippets_render_escaped_with_visible_markers(): void
    {
        $change = PageChange::factory()->create(['website_id' => $this->website->id, 'final_url' => 'javascript:alert(2)']);
        $location = [
            'xpath' => '/html[1]/body[1]/div[1]', 'css' => 'html > body > div:nth-of-type(1)', 'tag' => 'div',
            'attrs' => ['class' => "x\u{202E}y"], 'line' => 3, 'line_precision' => 'exact', 'hidden' => true,
            'snippet' => "<div><script>alert(1)</script><img src=x onerror=alert(3)>abc\u{202E}def</div>",
            'snippet_length' => 70, 'snippet_truncated' => false, 'snippet_starts_inside' => false, 'snippet_source' => 'raw',
        ];
        PageChangeForensic::factory()->payload([
            'v' => 1, 'unavailable' => null, 'before_unavailable' => null,
            'counts' => ['text_added' => 1, 'text_removed' => 0, 'elements_added' => 0, 'elements_removed' => 0, 'elements_modified' => 0, 'total' => 1],
            'undetailed' => 0,
            'items' => [['n' => 1, 'kind' => 'text', 'field' => 'body', 'change' => 'added', 'text' => "abc\u{202E}def", 'identity' => null, 'value' => null, 'before' => null, 'after' => $location, 'before_unavailable' => 'not_applicable']],
        ])->create(['page_change_id' => $change->id]);

        $response = $this->actingAs($this->userWith(['perubahan-konten.view']))->get(route('perubahan-konten.detail', $change));

        $response->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror', false)
            ->assertDontSee("\u{202E}", false)
            ->assertDontSee('href="javascript:alert(2)"', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('U+202E');
    }

    public function test_view_tokens_mark_invisible_and_control_characters(): void
    {
        $this->assertSame([['a', false], ['U+200B', true], ['b', false], ['CR', true], ["\n\t", false], ['U+202E', true]], VisibleText::tokens("a\u{200B}b\r\n\t\u{202E}"));
        $this->assertSame('a[U+FFFD]b', VisibleText::plain("a\xFFb"));
    }

    public function test_delete_from_the_detail_needs_the_delete_permission_and_is_audited(): void
    {
        $change = PageChange::factory()->create(['website_id' => $this->website->id]);
        PageChangeForensic::factory()->create(['page_change_id' => $change->id]);

        Livewire::actingAs($this->userWith(['perubahan-konten.view']))
            ->test(ContentChangesShow::class, ['change' => $change])
            ->call('delete')
            ->assertForbidden();
        $this->assertModelExists($change);

        Livewire::actingAs($this->userWith(['perubahan-konten.view', 'perubahan-konten.delete']))
            ->test(ContentChangesShow::class, ['change' => $change])
            ->call('delete')
            ->assertRedirect(route('perubahan-konten'));

        $this->assertModelMissing($change);
        $this->assertSame(0, PageChangeForensic::count());
        $this->assertSame(1, AuditLog::where('event', 'perubahan-konten.dihapus')->count());
    }

    public function test_an_unknown_detail_filter_falls_back_to_all(): void
    {
        $change = PageChange::factory()->create(['website_id' => $this->website->id]);

        Livewire::actingAs($this->userWith(['perubahan-konten.view']))
            ->test(ContentChangesShow::class, ['change' => $change])
            ->set('filter', 'bogus')
            ->assertSet('filter', '')
            ->set('filter', 'hidden')
            ->assertSet('filter', 'hidden');
    }

    public function test_the_list_filters_by_content_kind(): void
    {
        $make = fn (string $kind) => PageChange::factory()->create(['website_id' => $this->website->id, 'content_kind' => $kind, 'url' => self::URL.'/'.$kind, 'detected_at' => now()]);
        $make('text');
        $make('element');
        $make('mixed');

        $urls = fn (string $kind) => Livewire::actingAs($this->userWith(['perubahan-konten.view']))
            ->test(ContentChangesIndex::class)
            ->set('severity', 'all')
            ->set('kind', $kind)
            ->viewData('changes')
            ->pluck('url')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([self::URL.'/element', self::URL.'/mixed'], $urls('element'));
        $this->assertSame([self::URL.'/mixed', self::URL.'/text'], $urls('text'));
        $this->assertCount(3, $urls('bogus'));
    }

    // --- Helpers ---------------------------------------------------------------------

    protected function crawl(): void
    {
        (new JudolScanner)->scan($this->website->fresh());
        $this->travel(7)->hours();
    }

    protected function serve(string $html, array $headers = [], int $status = 200): void
    {
        $this->pages['/'] = [$status, $html, $headers];
    }

    /**
     * @param  array<int, string>  $body
     * @param  array<int, string>  $head
     */
    protected function doc(array $body, array $head = [], string $eol = "\n"): string
    {
        return implode($eol, ['<!DOCTYPE html>', '<html>', '<head>', '<title>Dinas Pendidikan Kabupaten</title>', ...$head, '</head>', '<body>', ...$body, '</body>', '</html>']);
    }

    /** Letters only: digits are masked before diffing, so they would not make chunks unique. */
    protected function sentence(int $i): string
    {
        $code = chr(97 + $i % 26).chr(97 + intdiv($i, 26) % 26).chr(97 + intdiv($i, 676) % 26);

        return "Kegiatan sekolah berkode {$code} berjalan lancar dan tertib.";
    }

    protected function lineOf(string $html, string $needle): int
    {
        return substr_count(substr($html, 0, strpos($html, $needle)), "\n") + 1;
    }

    /** The XPath selects exactly one element, which holds the chunk. */
    protected function assertLocates(string $html, ?array $location, ?string $chunk = null): void
    {
        $this->assertNotNull($location);
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $nodes = (new DOMXPath($dom))->query($location['xpath']);

        $this->assertSame(1, $nodes->length, $location['xpath']);

        if ($chunk !== null) {
            $this->assertStringContainsString($chunk, TextNormalizer::clean($nodes->item(0)->textContent));
        }
    }

    protected function item(array $payload, string $kind, string $change): array
    {
        foreach ($payload['items'] as $item) {
            if ($item['kind'] === $kind && $item['change'] === $change) {
                return $item;
            }
        }

        $this->fail("No {$kind}/{$change} item.");
    }

    protected function userWith(array $permissions): User
    {
        return User::factory()->create(['role_id' => Role::factory()->withPermissions($permissions)->create()->id]);
    }
}
