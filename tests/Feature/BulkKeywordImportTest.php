<?php

namespace Tests\Feature;

use App\Livewire\JudolDetections\Index;
use App\Models\JudolKeyword;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A new gambling-spam wave never shows up one phrase at a time - it arrives as a
 * list, pasted out of a report, a spreadsheet column, or straight out of
 * config/judol.php. Adding those one at a time through the single-keyword form is
 * the difference between a minute's work and forty clicks.
 */
class BulkKeywordImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new RoleSeeder)->run();

        // The keywords migration seeds config/judol.php's ~87 phrases, several of
        // which these cases paste on purpose. Starting from empty keeps each
        // assertion about what THIS import did.
        JudolKeyword::query()->delete();
    }

    public function test_keywords_are_added_one_per_line(): void
    {
        $this->bulk("slot gacor hari ini\nbandar togel terpercaya\nrtp live");

        $this->assertSame(
            ['bandar togel terpercaya', 'rtp live', 'slot gacor hari ini'],
            JudolKeyword::orderBy('keyword')->pluck('keyword')->all()
        );
        $this->assertTrue(JudolKeyword::first()->is_active, 'a pasted list is meant to take effect');
    }

    /**
     * The most likely paste of all: a block lifted straight out of the config
     * array, quotes and trailing commas included.
     */
    public function test_a_config_array_block_can_be_pasted_verbatim(): void
    {
        $this->bulk(<<<'PASTE'
                'slot maxwin',
                'link alternatif toto',
                "gates of olympus",
        PASTE);

        $this->assertEqualsCanonicalizing(
            ['slot maxwin', 'link alternatif toto', 'gates of olympus'],
            JudolKeyword::pluck('keyword')->all()
        );
    }

    public function test_commas_and_bullets_separate_and_do_not_survive(): void
    {
        $this->bulk("- Slot Demo, • toto slot\nprediksi   togel");

        $this->assertEqualsCanonicalizing(
            ['Slot Demo', 'toto slot', 'prediksi togel'],
            JudolKeyword::pluck('keyword')->all(),
            'inner whitespace is squished and list punctuation is stripped'
        );
    }

    public function test_keywords_already_on_the_list_are_reported_not_duplicated(): void
    {
        JudolKeyword::create(['keyword' => 'slot gacor', 'is_active' => true]);

        $component = $this->bulk("Slot Gacor\nslot gacor\ntogel online");

        $this->assertSame(1, JudolKeyword::where('keyword', 'slot gacor')->count());
        $this->assertSame(2, JudolKeyword::count());

        $result = $component->get('bulkKeywordResult');
        $this->assertSame(1, $result['added']);
        // Both the one already stored and the repeat inside the paste itself.
        $this->assertCount(2, $result['duplicate']);
    }

    public function test_an_over_long_phrase_is_rejected_without_taking_the_batch_down(): void
    {
        $component = $this->bulk("slot gacor\n".str_repeat('a', 101));

        $this->assertSame(['slot gacor'], JudolKeyword::pluck('keyword')->all());

        $result = $component->get('bulkKeywordResult');
        $this->assertSame(1, $result['added']);
        $this->assertCount(1, $result['invalid']);
    }

    public function test_the_new_keywords_reach_the_scanner_immediately(): void
    {
        // activeList() is cached forever, so a bulk insert that forgot to bust that
        // cache would leave the scanner matching the old list until the next deploy.
        JudolKeyword::activeList();

        $this->bulk('mahjong wins 3');

        $this->assertContains('mahjong wins 3', JudolKeyword::activeList());
    }

    public function test_a_read_only_role_cannot_bulk_add(): void
    {
        // The seeded "admin" role is read-only in this app - see RoleSeeder.
        $readOnly = $this->userWith('admin');

        Livewire::actingAs($readOnly)
            ->test(Index::class)
            ->set('bulkKeywords', 'slot gacor')
            ->call('bulkAddKeywords')
            ->assertForbidden();

        $this->assertSame(0, JudolKeyword::count());
    }

    protected function bulk(string $input): Testable
    {
        return Livewire::actingAs($this->userWith('superadmin'))
            ->test(Index::class)
            ->set('bulkKeywords', $input)
            ->call('bulkAddKeywords');
    }

    protected function userWith(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id')]);
    }
}
