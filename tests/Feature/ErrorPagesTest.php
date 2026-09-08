<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Error pages are the views nobody opens until the day they matter, which makes
 * them the easiest thing in the app to break without noticing - a renamed
 * variable in the shared layout takes all six down at once, and the only symptom
 * is that a 404 becomes a 500.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_error_page_renders(): void
    {
        // One loop rather than a data provider: the suite has no providers
        // elsewhere, and what matters here is that none of the six is missing or
        // broken - the failure message names the code either way.
        foreach ([403, 404, 419, 429, 500, 503] as $code) {
            $html = View::make("errors.{$code}")->render();

            $this->assertStringContainsString((string) $code, $html, "errors.{$code} lost its status code");
            $this->assertStringContainsString('Kembali ke beranda', $html, "errors.{$code} lost its home link");
        }
    }

    public function test_a_missing_route_renders_the_styled_404(): void
    {
        $response = $this->get('/halaman-yang-tidak-ada');

        $response->assertNotFound();
        // The glass card, not Laravel's default black-and-white page.
        $response->assertSee('Halaman tidak ditemukan');
    }

    public function test_the_home_link_is_root_relative(): void
    {
        // url('/') would need a booted container, and errors/503 is rendered
        // during maintenance mode when there is not one. It would also inherit a
        // misconfigured APP_URL and send people to the wrong host.
        $this->assertStringContainsString('href="/"', View::make('errors.503')->render());
    }

    public function test_error_pages_do_not_depend_on_the_vite_build(): void
    {
        // A 500 is often thrown *because* the asset pipeline is broken (a missing
        // manifest after a failed deploy). An error page that calls @vite in that
        // state throws its own exception instead of rendering.
        // Strip Blade comments first: the layout's own docblock explains that it
        // uses no @vite, and matching that sentence made this test fail on the
        // very file it was passing.
        $layout = preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            file_get_contents(resource_path('views/errors/layout.blade.php'))
        );

        $this->assertStringNotContainsString('@vite', $layout);
        $this->assertStringNotContainsString('@livewire', $layout);
    }

    public function test_error_pages_are_not_indexable(): void
    {
        $this->assertStringContainsString(
            'name="robots" content="noindex"',
            View::make('errors.404')->render()
        );
    }
}
