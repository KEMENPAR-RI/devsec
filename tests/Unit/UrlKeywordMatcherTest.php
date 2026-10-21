<?php

namespace Tests\Unit;

use App\Services\Monitoring\JudolMatcher;
use App\Services\Monitoring\UrlKeywordMatcher;
use Tests\TestCase;

class UrlKeywordMatcherTest extends TestCase
{
    protected function matcher(array $keywords = ['slot gacor' => 3, 'togel4d' => 3, 'toto' => 1, 'bandar' => 2], array $exclusions = ['bandar lampung']): UrlKeywordMatcher
    {
        $rules = ['keywords' => $keywords, 'exclusions' => $exclusions];

        return new UrlKeywordMatcher(new class($rules) extends JudolMatcher
        {
            public function __construct(protected array $fixed) {}

            public function rules(): array
            {
                return $this->fixed;
            }
        });
    }

    public function test_normalize_drops_scheme_userinfo_and_fragment_and_decodes_twice(): void
    {
        $normalized = $this->matcher()->normalize('https://user:pw@Situs.Example/%2573lot_Gacor/togel4d?q=a+b#frag');

        $this->assertSame(' situs example slot gacor togel 4 d q a b ', $normalized['tokens']);
        $this->assertSame('situsexampleslotgacortogel4dqab', $normalized['compact']);
    }

    public function test_token_boundaries_catch_separators_and_letter_digit_runs(): void
    {
        $matcher = $this->matcher();

        $this->assertSame(['slot gacor' => 3], $matcher->match('https://a.example/slot-gacor'));
        $this->assertSame(['slot gacor' => 3], $matcher->match('https://a.example/x?p=slot_gacor'));
        $this->assertArrayHasKey('togel4d', $matcher->match('https://situs-togel4d.example/'));
    }

    public function test_compact_match_only_for_long_keywords(): void
    {
        $matcher = $this->matcher();

        $this->assertArrayHasKey('slot gacor', $matcher->match('https://situsslotgacor88.example/'));
        // "toto" is shorter than min_compact_length: never matched inside a word.
        $this->assertSame([], $matcher->match('https://photostotoro.example/'));
        $this->assertSame(['toto' => 1], $matcher->match('https://toto.example/'));
    }

    public function test_an_exclusion_covering_the_keyword_drops_it(): void
    {
        $matcher = $this->matcher();

        $this->assertSame([], $matcher->match('https://bandar-lampung.example/'));
        $this->assertSame(['bandar' => 2], $matcher->match('https://bandar-judi.example/'));
    }

    public function test_disabled_matching_returns_nothing(): void
    {
        config(['judol.url_match.enabled' => false]);

        $this->assertSame([], $this->matcher()->match('https://a.example/slot-gacor'));
    }
}
