<?php

namespace Tests\Unit;

use App\Support\EvidenceMarkup;
use PHPUnit\Framework\TestCase;

/**
 * Evidence is stored on one line and has to be readable on screen. What must hold
 * is that laying it out never turns inert text back into markup, and never
 * misreports the structure an operator is about to go and clean.
 */
class EvidenceMarkupTest extends TestCase
{
    /** Reads an assertion back the way it appears on screen. */
    protected function formatted(string $rawHtml): string
    {
        return html_entity_decode(EvidenceMarkup::format(e($rawHtml)));
    }

    public function test_nesting_is_indented_and_closing_tags_line_up_with_their_openers(): void
    {
        $out = $this->formatted('<div class="w"><p><a href="https://x.example/"><span>keluaran hk</span></a></p></div>');

        $this->assertSame(<<<'TXT'
        <div class="w">
          <p>
            <a href="https://x.example/">
              <span>keluaran hk</span>
            </a>
          </p>
        </div>
        TXT, $out);
    }

    public function test_a_short_element_is_kept_whole_rather_than_split_over_three_lines(): void
    {
        // That anchor is usually the entire finding; splitting it triples its height
        // and reads worse.
        $this->assertSame(
            '<a href="https://x.example/">togel</a>',
            $this->formatted('<a href="https://x.example/">togel</a>')
        );
    }

    public function test_an_attribute_containing_an_ampersand_does_not_swallow_the_next_tag(): void
    {
        // Regression: the tag body was written as "not an &", but a query string
        // legitimately carries &amp; - so the tag ran past its own closing bracket
        // and ate the element after it.
        $out = $this->formatted('<a href="https://x.example/?a=1&b=2"><span>togel</span></a>');

        $this->assertSame(<<<'TXT'
        <a href="https://x.example/?a=1&b=2">
          <span>togel</span>
        </a>
        TXT, $out);
    }

    public function test_a_void_element_does_not_open_a_nesting_level(): void
    {
        $out = $this->formatted('<div><img src="promo.jpg"><p>togel</p></div>');

        // <p> sits beside the image, not inside it.
        $this->assertSame(<<<'TXT'
        <div>
          <img src="promo.jpg">
          <p>togel</p>
        </div>
        TXT, $out);
    }

    public function test_hostile_markup_stays_inert(): void
    {
        // The whole safety contract: this string came off a compromised site, and
        // formatting must never hand any of it back as live HTML.
        $out = EvidenceMarkup::format(e('<script>alert(1)</script><img src=x onerror=alert(2)>'));

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function test_empty_input_produces_nothing(): void
    {
        $this->assertSame('', EvidenceMarkup::format(''));
        $this->assertSame('', EvidenceMarkup::format('   '));
    }

    public function test_plain_text_with_no_tags_survives_unchanged(): void
    {
        $this->assertSame('togel singapore', $this->formatted('togel singapore'));
    }
}
