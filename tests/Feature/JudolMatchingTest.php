<?php

namespace Tests\Feature;

use App\Livewire\JudolDetections\Index;
use App\Models\JudolDetection;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Notifications\JudolContentDetected;
use App\Services\AppSettings;
use App\Services\Monitoring\JudolMatcher;
use App\Services\Monitoring\JudolScanner;
use App\Services\Monitoring\PageText;
use App\Services\Monitoring\PageTextExtractor;
use App\Services\Monitoring\TextNormalizer;
use App\Support\ResponseSizeLimit;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Judol v2: whole-word weighted matching over folded text. Runs against the
 * keyword list and exclusions the migrations seed - the same rules production starts with.
 */
class JudolMatchingTest extends TestCase
{
    use RefreshDatabase;

    // --- Normalizer ---------------------------------------------------------

    public function test_fold_undoes_case_diacritics_invisibles_and_homoglyphs(): void
    {
        $this->assertSame(' siot gacor ', TextNormalizer::fold('SLOT GACOR'));
        $this->assertSame(' siot gacor ', TextNormalizer::fold('ślót gäcor'));
        $this->assertSame(' siot gacor ', TextNormalizer::fold("sl\u{200B}ot ga\u{00AD}cor\u{200D}"));
        $this->assertSame(' siot gacor ', TextNormalizer::fold('&#115;lot gacor'));
        $this->assertSame(' siot gacor ', TextNormalizer::fold('ѕlоt gасоr'));
        $this->assertSame(' siot gacor ', TextNormalizer::fold('ｓｌｏｔ ｇａｃｏｒ'));
    }

    public function test_fold_reads_leetspeak_and_separators(): void
    {
        $this->assertSame(TextNormalizer::foldKeyword('slot'), TextNormalizer::foldKeyword('s1ot'));
        $this->assertSame(TextNormalizer::foldKeyword('situs'), TextNormalizer::foldKeyword('s1tus'));
        $this->assertSame(TextNormalizer::foldKeyword('slot gacor'), TextNormalizer::foldKeyword('5l0t g4c0r'));

        foreach (['s.l.o.t', 's l o t', 's-l-o-t', 's_l_o_t', 's|l|o|t'] as $spaced) {
            $this->assertSame(TextNormalizer::foldKeyword('slot'), TextNormalizer::foldKeyword($spaced), $spaced);
        }
    }

    public function test_fold_leaves_plain_numbers_alone(): void
    {
        // Leet digits apply to mixed tokens only - "2024" is a year, not "zoz4".
        $this->assertSame(' tahun 2024 ', TextNormalizer::fold('Tahun 2024'));
    }

    public function test_mask_dynamic_hides_dates_times_counters_and_tokens(): void
    {
        $a = TextNormalizer::maskDynamic('Diperbarui 12 Januari 2026 pukul 10:15 WIB, dilihat 1.203 kali, token abc123def456ghi789jkl0');
        $b = TextNormalizer::maskDynamic('Diperbarui 13 Januari 2026 pukul 11:42 WIB, dilihat 1.987 kali, token zzz999yyy888xxx777www6');

        $this->assertSame($a, $b);
    }

    // --- Corpus -------------------------------------------------------------

    public static function cleanPages(): array
    {
        return self::fixtures('clean');
    }

    public static function judolPages(): array
    {
        return self::fixtures('obfuscated');
    }

    #[DataProvider('cleanPages')]
    public function test_clean_institution_pages_are_not_flagged(string $file): void
    {
        $result = $this->matchFile($file);

        $this->assertLessThan($this->threshold(), $result['score'], basename($file).' matched: '.implode(', ', $result['keywords']));
    }

    #[DataProvider('judolPages')]
    public function test_every_obfuscated_judol_sample_is_flagged(string $file): void
    {
        $result = $this->matchFile($file);

        $this->assertGreaterThanOrEqual($this->threshold(), $result['score'], basename($file).' was missed');
    }

    public function test_hidden_judol_text_is_labelled_hidden(): void
    {
        foreach (['23-hidden-display-none', '24-hidden-visibility', '25-hidden-font-size', '26-hidden-offscreen', '27-hidden-same-color', '28-hidden-class-rule', '29-hidden-attribute'] as $name) {
            $result = $this->matchFile(base_path("tests/Fixtures/judol/obfuscated/{$name}.html"));

            $this->assertTrue(collect($result['details'])->contains('hidden', true), "{$name} not labelled hidden");
        }
    }

    public function test_visible_judol_text_is_not_labelled_hidden(): void
    {
        $result = $this->matchHtml('<p>slot gacor terbaik</p>');

        $this->assertFalse($result['details'][0]['hidden']);
    }

    // --- Whole words, exclusions, score -------------------------------------

    public function test_a_keyword_inside_a_longer_word_does_not_match(): void
    {
        $this->assertSame(0, $this->matchHtml('<p>Toko sbobetter menjual remix parlaynya</p>')['score']);
        $this->assertSame([], app(JudolMatcher::class)->matchText('pengagen bolanya prejudi onlinesia'));
    }

    public function test_an_exclusion_applies_across_tags(): void
    {
        $this->assertSame(0, $this->matchHtml('<p>Kota <a href="/x">Bandar</a> Lampung</p>')['score']);
    }

    public function test_a_keyword_sharing_a_token_with_an_exclusion_is_dropped(): void
    {
        $this->assertSame(0, $this->matchHtml('<p>Lomba kicau gacor hari ini di alun-alun.</p>')['score']);
        $this->assertSame(0, $this->matchHtml('<p>Kontes burung gacor malam ini</p>')['score']);
    }

    public function test_a_keyword_merely_adjacent_to_an_exclusion_still_counts(): void
    {
        $this->assertSame(['slot online'], $this->matchHtml('<p>kicau gacor slot online</p>')['keywords']);
        $this->assertSame(['maxwin hari ini'], $this->matchHtml('<p>burung gacor maxwin hari ini</p>')['keywords']);
        $this->assertSame(['gacor hari ini'], $this->matchHtml('<p>kicau gacor gacor hari ini</p>')['keywords']);
    }

    public function test_an_exclusion_word_prefixed_to_a_keyword_does_not_hide_it(): void
    {
        // Evasion: "time slot" is an exclusion, but "time slot gacor" is still judol.
        $this->assertGreaterThanOrEqual($this->threshold(), $this->matchHtml('<p>Daftar time slot gacor maxwin</p>')['score']);
    }

    public function test_phrases_do_not_match_across_sentence_or_clause_punctuation(): void
    {
        foreach (['Bapak Toto. Slot pendaftaran beasiswa dibuka.', 'Bapak Toto, slot beasiswa', 'Toto; slot', 'Toto! Slot', 'Toto? Slot'] as $text) {
            $this->assertSame(0, $this->matchHtml("<p>{$text}</p>")['score'], $text);
        }
    }

    public function test_separator_obfuscation_still_matches_after_the_boundary_rule(): void
    {
        foreach (['s.l.o.t gacor', 's. l. o. t gacor', 'slot gacor', 'SLOT GACOR! MAXWIN!', 'toto slot'] as $text) {
            $this->assertGreaterThanOrEqual($this->threshold(), $this->matchHtml("<p>{$text}</p>")['score'], $text);
        }
    }

    // --- Final re-test: single "gacor" keyword, nested hits, remainder check ---

    public function test_gacor_alone_is_a_weak_keyword_below_the_threshold(): void
    {
        $result = $this->matchHtml('<p>Penampilan tim kami sangat gacor musim ini</p>');

        $this->assertSame(['gacor'], $result['keywords']);
        $this->assertSame(1, $result['score']);
        $this->assertLessThan($this->threshold(), $result['score']);
    }

    public function test_a_single_word_hit_nested_in_a_longer_hit_is_not_counted_twice(): void
    {
        $result = $this->matchHtml('<p>Info slot gacor terbaru</p>');

        $this->assertSame(['slot gacor'], $result['keywords']);
        $this->assertSame(3, $result['score']);
    }

    public function test_time_slot_gacor_maxwin_is_flagged_through_the_remainder(): void
    {
        $result = $this->matchHtml('<p>time slot gacor maxwin</p>');

        $this->assertSame(['slot gacor'], $result['keywords']);
        $this->assertGreaterThanOrEqual($this->threshold(), $result['score']);
    }

    public function test_bird_contest_gacor_stays_clean(): void
    {
        foreach (['Lomba kicau gacor', 'Burung gacor juara kelas murai', 'Kontes kicau gacor hari ini', 'Jual burung gacor malam ini'] as $text) {
            $this->assertSame(0, $this->matchHtml("<p>{$text}</p>")['score'], $text);
        }
    }

    public function test_one_weak_keyword_alone_is_not_enough(): void
    {
        $result = $this->matchHtml('<p>Hadiah jackpot undian tabungan</p>');

        $this->assertSame(['jackpot'], $result['keywords']);
        $this->assertLessThan($this->threshold(), $result['score']);
    }

    public function test_weak_and_medium_keywords_together_reach_the_threshold(): void
    {
        $result = $this->matchHtml('<p>Main mahjong ways dan raih jackpot</p>');

        $this->assertSame(3, $result['score']);
        $this->assertGreaterThanOrEqual($this->threshold(), $result['score']);
    }

    public function test_one_strong_keyword_is_enough(): void
    {
        $this->assertGreaterThanOrEqual($this->threshold(), $this->matchHtml('<p>Daftar judi online</p>')['score']);
    }

    public function test_the_admin_threshold_is_respected_by_the_scanner(): void
    {
        Notification::fake();
        Setting::set('judol_score_threshold', '5');
        $website = $this->fakeSite(['/' => '<html><body><p>Daftar judi online sekarang</p></body></html>']);

        (new JudolScanner)->scan($website);

        $this->assertSame(5, app(AppSettings::class)->judolScoreThreshold());
        $this->assertDatabaseCount('judol_detections', 0);
    }

    // --- Long pages ---------------------------------------------------------

    public function test_a_long_page_with_its_keyword_in_the_footer_does_not_flap(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $filler = str_repeat('<p>Berita kegiatan pemerintah daerah tentang pelayanan publik yang ditulis panjang.</p>', 700);
        $website = $this->fakeSite(['/' => "<html><body>{$filler}<footer>slot gacor hari ini</footer></body></html>"]);

        (new JudolScanner)->scan($website);
        $first = JudolDetection::firstOrFail();
        $this->assertGreaterThan(40000, mb_strlen($filler));

        $this->travel(7)->hours();
        (new JudolScanner)->scan($website->fresh());

        // The stored snapshot is cut at 40 KB, before the footer - a recheck against
        // it must not delete and re-raise the detection every crawl.
        $this->assertSame([$first->id], JudolDetection::pluck('id')->all());
        Notification::assertSentToTimes($admin, JudolContentDetected::class, 1);
    }

    public function test_adding_an_exclusion_keeps_a_detection_whose_snapshot_was_truncated(): void
    {
        Notification::fake();
        $filler = str_repeat('<p>Berita kegiatan pemerintah daerah tentang pelayanan publik yang ditulis panjang.</p>', 700);
        $website = $this->fakeSite(['/' => "<html><body>{$filler}<footer>slot gacor hari ini</footer></body></html>"]);
        (new JudolScanner)->scan($website);

        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->set('newExclusion', 'lomba kicau')
            ->call('addExclusion')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('judol_detections', 1);
    }

    public function test_adding_an_exclusion_clears_a_detection_it_now_covers(): void
    {
        Notification::fake();
        $website = $this->fakeSite(['/' => '<html><body><p>Info pendaftaran slot online antrean paspor.</p></body></html>']);
        (new JudolScanner)->scan($website);
        $this->assertDatabaseCount('judol_detections', 1);

        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->set('newExclusion', 'pendaftaran slot online')
            ->call('addExclusion')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('judol_detections', 0);
    }

    // --- Security fix #1: script literals past the PCRE JIT stack limit -------

    public function test_a_script_literal_over_ten_kilobytes_is_still_read(): void
    {
        $padding = str_repeat('lorem ipsum dolor sit amet ', 600);
        $page = app(PageTextExtractor::class)->extract('<html><body><p>Berita desa</p><script>document.write("<div>slot gacor maxwin '.$padding.'</div>"); var t = \'it\\\'s\';</script></body></html>');

        $this->assertTrue($page->complete);
        $this->assertStringContainsString('slot gacor maxwin', $page->plainText());
        $this->assertStringContainsString("it's", $page->plainText());
        $this->assertContains('slot gacor', app(JudolMatcher::class)->match($page)['keywords']);
    }

    public function test_an_unreadable_page_never_clears_an_existing_detection(): void
    {
        Notification::fake();
        $website = $this->fakeSite(['/promo' => '<html><body><p>slot gacor maxwin hari ini</p></body></html>']);
        (new JudolScanner)->scanUrlNow($website, 'https://desa.example/promo');
        $this->assertDatabaseCount('judol_detections', 1);

        // Extraction failed (e.g. a PCRE error): nothing readable, flagged incomplete.
        $this->app->instance(PageTextExtractor::class, new class extends PageTextExtractor
        {
            public function extract(string $html, ?string $url = null, bool $complete = true): PageText
            {
                return new PageText([], complete: false);
            }
        });
        (new JudolScanner)->scanUrlNow($website, 'https://desa.example/promo');

        $this->assertDatabaseCount('judol_detections', 1);
    }

    // --- Security fix 2: depth limit, size limit, JS comments ------------------

    public function test_markup_nested_past_the_parser_depth_limit_is_flagged_incomplete(): void
    {
        $extractor = app(PageTextExtractor::class);

        $deep = $extractor->extract('<html><body>'.str_repeat('<div>', 300).'<p>slot gacor maxwin</p></body></html>');
        $shallow = $extractor->extract('<html><body>'.str_repeat('<div>', 50).'<p>slot gacor maxwin</p></body></html>');

        $this->assertFalse($deep->complete);
        $this->assertTrue($shallow->complete);
        $this->assertStringContainsString('slot gacor maxwin', $shallow->plainText());
    }

    public function test_a_page_nested_past_the_depth_limit_never_clears_an_existing_detection(): void
    {
        Notification::fake();
        $html = '<html><body><p>slot gacor maxwin hari ini</p></body></html>';
        $website = $this->fakeSite(['/promo' => &$html]);
        (new JudolScanner)->scanUrlNow($website, 'https://desa.example/promo');
        $this->assertDatabaseCount('judol_detections', 1);

        // Content past nesting level 256 never reaches the DOM, but a browser renders it.
        $html = '<html><body><p>Berita desa</p>'.str_repeat('<div>', 300).'<p>slot gacor maxwin</p><a href="https://gacor88.example/">daftar</a></body></html>';
        (new JudolScanner)->scanUrlNow($website, 'https://desa.example/promo');

        $this->assertDatabaseCount('judol_detections', 1);
    }

    public function test_a_body_cut_at_the_size_limit_never_clears_an_existing_detection(): void
    {
        Notification::fake();
        $website = $this->fakeSite(['/promo' => '<html><body><p>slot gacor maxwin hari ini</p></body></html>']);
        (new JudolScanner)->scanUrlNow($website, 'https://desa.example/promo');
        $this->assertDatabaseCount('judol_detections', 1);

        // Padding pushes the injection past the limit: the sink keeps only the clean head.
        $sink = new ResponseSizeLimit(1024);
        $sink->write('<html><body><p>Berita desa</p>'.str_repeat(' ', 2048).'<p>slot gacor maxwin</p></body></html>');
        $this->assertTrue($sink->truncated);
        $this->cutResponse = $sink;
        (new JudolScanner)->scanUrlNow($website, 'https://desa.example/promo');

        $this->assertDatabaseCount('judol_detections', 1);
    }

    public function test_a_quote_inside_a_js_comment_does_not_hide_a_script_literal(): void
    {
        $page = app(PageTextExtractor::class)->extract('<html><body><p>x</p><script>/*"*/document.write("<a>slot gacor maxwin</a>")/*"*/'."\n".'// it\'s'."\n".'document.write(\'<b>judi online</b>\');</script></body></html>');

        $this->assertTrue($page->complete);
        $this->assertStringContainsString('slot gacor maxwin', $page->plainText());
        $this->assertStringContainsString('judi online', $page->plainText());
    }

    // --- Helpers ------------------------------------------------------------

    /** When set, every request answers with this (truncated) sink as its body. */
    protected ?ResponseSizeLimit $cutResponse = null;

    /** @return array<string, array{0: string}> */
    protected static function fixtures(string $set): array
    {
        $files = glob(dirname(__DIR__)."/Fixtures/judol/{$set}/*.html") ?: [];

        return collect($files)->mapWithKeys(fn (string $f) => [basename($f) => [$f]])->all();
    }

    protected function matchFile(string $file): array
    {
        return app(JudolMatcher::class)->match(app(PageTextExtractor::class)->extract((string) file_get_contents($file), 'https://desa.example/berita'));
    }

    protected function matchHtml(string $body): array
    {
        return app(JudolMatcher::class)->match(app(PageTextExtractor::class)->extract("<html><body>{$body}</body></html>", 'https://desa.example/berita'));
    }

    protected function threshold(): int
    {
        return app(AppSettings::class)->judolScoreThreshold();
    }

    /** @param  array<string, string>  $pages  path => html; anything else 404 */
    protected function fakeSite(array $pages): Website
    {
        Http::fake(function (Request $request) use ($pages) {
            if ($this->cutResponse) {
                return Create::promiseFor(new PsrResponse(200, [], $this->cutResponse));
            }

            $path = parse_url($request->url(), PHP_URL_PATH) ?: '/';

            return isset($pages[$path]) ? Http::response($pages[$path], 200) : Http::response('', 404);
        });

        return Website::factory()->create(['url' => 'https://desa.example']);
    }
}
