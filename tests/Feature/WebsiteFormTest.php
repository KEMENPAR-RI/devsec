<?php

namespace Tests\Feature;

use App\Livewire\Websites\Index;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_website_can_mark_it_internal_only(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('openCreate')
            ->set('name', 'Portal Internal')
            ->set('url', 'https://internal.example')
            ->set('isInternalOnly', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('websites', ['name' => 'Portal Internal', 'is_internal_only' => true]);
    }

    public function test_editing_a_website_preloads_its_internal_only_flag(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['is_internal_only' => true]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('openEdit', $website->uuid)
            ->assertSet('isInternalOnly', true);
    }

    public function test_open_create_resets_internal_only_to_false(): void
    {
        $user = User::factory()->create();
        $internalSite = Website::factory()->create(['is_internal_only' => true]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('openEdit', $internalSite->uuid)
            ->assertSet('isInternalOnly', true)
            ->call('openCreate')
            ->assertSet('isInternalOnly', false);
    }
}
