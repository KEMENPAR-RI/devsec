<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandingComposerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The name saved in Pengaturan has to reach both layouts. config('app.name')
     * is frozen when the container boots, so a view reading that would still show
     * the .env name for the rest of the request that changed it.
     */
    public function test_the_saved_app_name_is_rendered_on_the_login_page(): void
    {
        Setting::set('app_name', 'Monitoring Kemenpar');

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Monitoring Kemenpar', escape: false);
    }

    public function test_the_saved_app_name_is_rendered_in_the_dashboard_layout(): void
    {
        Setting::set('app_name', 'Monitoring Kemenpar');

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Monitoring Kemenpar', escape: false);
    }

    /** The layouts must not have to resolve anything themselves to render. */
    public function test_the_login_page_renders_without_branding_configured(): void
    {
        $this->get(route('login'))->assertOk();
    }
}
