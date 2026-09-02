<?php

namespace Tests\Feature;

use App\Livewire\Websites\Index;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteBulkImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_import_creates_a_website_per_line_with_and_without_a_name(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('bulkInput', "Website Desa Sukamaju, https://sukamaju.desa.id\nhttps://contoh-lain.go.id")
            ->call('bulkImport')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('websites', ['url' => 'https://sukamaju.desa.id', 'name' => 'Website Desa Sukamaju']);
        $this->assertDatabaseHas('websites', ['url' => 'https://contoh-lain.go.id', 'name' => 'contoh-lain.go.id']);
    }

    public function test_bulk_import_uses_default_intervals_and_thresholds(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('bulkInput', 'https://defaults-test.example')
            ->call('bulkImport');

        $website = Website::where('url', 'https://defaults-test.example')->first();

        $this->assertNotNull($website);
        $this->assertTrue($website->is_active);
        $this->assertSame(5, $website->check_interval_minutes);
        $this->assertSame(60, $website->judol_scan_interval_minutes);
        $this->assertSame(3, $website->down_threshold_failures);
        $this->assertSame(15000, $website->response_time_threshold_ms);
    }

    public function test_bulk_import_skips_duplicates_within_the_batch_and_against_existing_websites(): void
    {
        $user = User::factory()->create();
        Website::factory()->create(['url' => 'https://already-exists.example']);

        $component = Livewire::actingAs($user)
            ->test(Index::class)
            ->set('bulkInput', "https://already-exists.example\nhttps://new-one.example\nhttps://new-one.example")
            ->call('bulkImport');

        $result = $component->get('bulkResult');

        $this->assertSame(1, $result['created']);
        $this->assertCount(2, $result['skipped']);
        $this->assertSame(1, Website::where('url', 'https://new-one.example')->count());
    }

    public function test_bulk_import_reports_invalid_lines_without_crashing(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(Index::class)
            ->set('bulkInput', "bukan url sama sekali\nhttps://valid-one.example")
            ->call('bulkImport')
            ->assertHasNoErrors();

        $result = $component->get('bulkResult');

        $this->assertSame(1, $result['created']);
        $this->assertCount(1, $result['invalid']);
        $this->assertDatabaseHas('websites', ['url' => 'https://valid-one.example']);
    }

    public function test_bulk_import_requires_non_empty_input(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('bulkInput', '')
            ->call('bulkImport')
            ->assertHasErrors(['bulkInput']);
    }
}
