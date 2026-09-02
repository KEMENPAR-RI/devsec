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
