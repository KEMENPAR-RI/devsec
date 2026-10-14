<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_the_public_tv_page_but_protected_pages_redirect_to_login(): void
    {
        $this->get('/')->assertOk();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
