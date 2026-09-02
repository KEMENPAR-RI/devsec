<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SafeImageUrlsTest extends TestCase
{
    public function test_a_single_safe_url_is_returned_as_is(): void
    {
        $this->assertSame(
            'https://cdn.example.com/logo.png',
            safe_image_urls('https://cdn.example.com/logo.png')
        );
    }

    public function test_a_scheme_relative_or_root_relative_url_is_kept(): void
    {
        $this->assertSame('//cdn.example.com/logo.png', safe_image_urls('//cdn.example.com/logo.png'));
        $this->assertSame('/storage/logo.png', safe_image_urls('/storage/logo.png'));
    }

    public function test_a_dangerous_scheme_is_rejected(): void
    {
        $this->assertNull(safe_image_urls('javascript:alert(1)'));
        $this->assertNull(safe_image_urls('data:text/html,<script>alert(1)</script>'));
        $this->assertNull(safe_image_urls('vbscript:msgbox(1)'));
    }

    public function test_null_and_empty_input_return_null_for_a_single_url(): void
    {
        $this->assertNull(safe_image_urls(null));
        $this->assertNull(safe_image_urls(''));
        $this->assertNull(safe_image_urls('   '));
    }

    public function test_an_iterable_of_urls_returns_an_array_with_unsafe_and_empty_entries_dropped(): void
    {
        $result = safe_image_urls([
            'https://cdn.example.com/a.png',
            'javascript:alert(1)',
            null,
            '',
            'https://cdn.example.com/b.png',
        ]);

        $this->assertSame([
            'https://cdn.example.com/a.png',
            'https://cdn.example.com/b.png',
        ], $result);
    }

    public function test_an_empty_iterable_returns_an_empty_array(): void
    {
        $this->assertSame([], safe_image_urls([]));
    }
}
