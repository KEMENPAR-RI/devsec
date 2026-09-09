<?php

namespace Tests\Feature;

use App\Livewire\Websites\Index;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WebsitesPaginationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression: Websites\Index was the only list page missing WithPagination,
     * so its pagination links were plain <a href="?page=2"> (full browser reload)
     * instead of Livewire-aware AJAX navigation - and colliding with wire:poll on
     * the same page caused intermittent errors. gotoPage() only exists (and only
     * actually changes the rendered page) when the trait is present.
     */
    public function test_pagination_navigates_via_livewire_without_a_full_page_reload(): void
    {
        $user = User::factory()->create();
        Website::factory()->count(15)->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSet('paginators.page', 1)
            ->call('gotoPage', 2)
            ->assertOk()
            ->assertSet('paginators.page', 2);
    }

    /** Whether the paginator rendered a control for this page number. */
    protected function showsPage(string $html, int $page): bool
    {
        return str_contains($html, "paginator-page-page{$page}\"");
    }

    /**
     * The page numbers are a fixed window - 2 at the start, 3 around the current
     * page, 2 at the end - rather than Laravel's default window, which widens with
     * the page count until the row runs off the card.
     */
    public function test_the_page_numbers_are_capped_to_a_window(): void
    {
        $user = User::factory()->create();
        Website::factory()->count(200)->create(); // 20 halaman

        $html = Livewire::actingAs($user)
            ->test(Index::class)
            ->call('gotoPage', 10)
            ->html();

        foreach ([1, 2, 9, 10, 11, 19, 20] as $page) {
            $this->assertTrue($this->showsPage($html, $page), "halaman {$page} harus muncul");
        }

        foreach ([3, 8, 12, 18] as $page) {
            $this->assertFalse($this->showsPage($html, $page), "halaman {$page} tidak boleh muncul");
        }

        $this->assertStringContainsString('...', $html, 'nomor yang dilewati ditandai');
    }

    public function test_the_window_keeps_three_middle_numbers_on_the_first_page(): void
    {
        // Without the clamp the middle range would start below page 1 and collapse
        // to two numbers exactly where the operator starts reading.
        $user = User::factory()->create();
        Website::factory()->count(200)->create();

        $html = Livewire::actingAs($user)->test(Index::class)->html();

        $this->assertTrue($this->showsPage($html, 3), 'nomor tengah ketiga tetap ada');
        $this->assertTrue($this->showsPage($html, 19));
        $this->assertFalse($this->showsPage($html, 4));
    }

    public function test_deleting_all_websites_resets_the_page_number(): void
    {
        $user = User::factory()->create();
        Website::factory()->count(15)->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('gotoPage', 2)
            ->assertSet('paginators.page', 2)
            ->call('deleteAll')
            ->assertSet('paginators.page', 1);
    }
}
