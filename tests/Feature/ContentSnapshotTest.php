<?php

namespace Tests\Feature;

use App\Models\JudolDetection;
use App\Models\JudolKeyword;
use App\Models\Website;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

class ContentSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        NotificationFacade::fake();
        JudolKeyword::firstOrCreate(['keyword' => 'slot gacor'], ['is_active' => true]);
    }

    public function test_a_flagged_page_stores_a_snapshot_of_its_text(): void
    {
        Http::fake(['snap.example' => Http::response('<body>Selamat datang di situs slot gacor terbaik.</body>', 200)]);

        $website = Website::factory()->create(['url' => 'http://snap.example']);
        (new JudolScanner)->scanUrlNow($website, 'http://snap.example');

        $detection = JudolDetection::firstOrFail();

        $this->assertStringContainsString('situs slot gacor terbaik', $detection->content_snapshot);
        $this->assertSame(sha1($detection->content_snapshot), $detection->content_hash);
    }

    public function test_excerpts_quote_the_gambling_passage_with_surrounding_context(): void
    {
        $filler = str_repeat('Halaman biasa tentang layanan publik. ', 8);
        Http::fake(['excerpt.example' => Http::response(
            "<body>{$filler} Kunjungi bandar slot gacor sekarang juga. {$filler}</body>",
            200
        )]);

        $website = Website::factory()->create(['url' => 'http://excerpt.example']);
        (new JudolScanner)->scanUrlNow($website, 'http://excerpt.example');

        $excerpts = JudolDetection::firstOrFail()->matchedExcerpts();

        $this->assertNotEmpty($excerpts);
        $this->assertStringContainsString('slot gacor', $excerpts[0]);
        $this->assertStringContainsString('Kunjungi bandar', $excerpts[0], 'context around the match is included');
    }

    public function test_a_recheck_records_what_the_page_added_and_removed(): void
    {
        Http::fakeSequence()
            ->push('<body>Promo slot gacor edisi lama. Kalimat pembuka yang cukup panjang untuk dihitung.</body>', 200)
            ->push('<body>Promo slot gacor edisi baru dengan bonus. Kalimat tambahan yang juga cukup panjang.</body>', 200);

        $website = Website::factory()->create(['url' => 'http://changed.example']);
        $scanner = new JudolScanner;

        $scanner->scanUrlNow($website, 'http://changed.example');
        $this->assertNull(JudolDetection::firstOrFail()->content_changes, 'nothing to diff against on first sight');

        $scanner->scanUrlNow($website, 'http://changed.example');
        $changes = JudolDetection::firstOrFail()->content_changes;

        $this->assertNotNull($changes);
        $this->assertNotEmpty($changes['added']);
        $this->assertStringContainsString('edisi baru', implode(' ', $changes['added']));
        $this->assertStringContainsString('edisi lama', implode(' ', $changes['removed']));
    }

    public function test_an_unchanged_page_is_not_reported_as_changed(): void
    {
        $page = '<body>Promo slot gacor yang isinya tidak berubah sama sekali antar pengecekan.</body>';
        Http::fake(['same.example' => Http::response($page, 200)]);

        $website = Website::factory()->create(['url' => 'http://same.example']);
        $scanner = new JudolScanner;

        $scanner->scanUrlNow($website, 'http://same.example');
        $scanner->scanUrlNow($website, 'http://same.example');

        $this->assertNull(JudolDetection::firstOrFail()->content_changes);
    }

    public function test_rows_stored_before_snapshots_existed_fall_back_to_the_old_snippet(): void
    {
        $website = Website::factory()->create();
        $legacy = JudolDetection::create([
            'website_id' => $website->id,
            'url' => 'http://legacy.example',
            'matched_keywords' => ['slot gacor'],
            'snippet' => 'cuplikan lama tanpa snapshot',
            'status' => 'new',
            'detected_at' => now(),
        ]);

        $this->assertSame(['cuplikan lama tanpa snapshot'], $legacy->matchedExcerpts());
    }
}
