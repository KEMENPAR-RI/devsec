<?php

namespace Tests\Unit;

use App\Support\AhoCorasick;
use PHPUnit\Framework\TestCase;

class AhoCorasickTest extends TestCase
{
    public function test_it_finds_every_pattern_including_overlaps(): void
    {
        $matches = (new AhoCorasick(['he' => 'A', 'she' => 'B', 'his' => 'C', 'hers' => 'D']))->search('ushers');

        $found = array_map(fn (array $m) => [$m[1], $m[0] - $m[2]], $matches);

        $this->assertEqualsCanonicalizing([['B', 1], ['A', 2], ['D', 2]], $found);
    }

    public function test_offsets_are_bytes_and_multibyte_text_is_safe(): void
    {
        $matches = (new AhoCorasick([' nổ hũ ' => 'vn']))->search(' chơi nổ hũ ngay ');

        $this->assertCount(1, $matches);
        [$end, $payload, $length] = $matches[0];
        $this->assertSame('vn', $payload);
        $this->assertSame(' nổ hũ ', substr(' chơi nổ hũ ngay ', $end - $length, $length));
    }

    public function test_no_patterns_or_no_match_returns_nothing(): void
    {
        $this->assertSame([], (new AhoCorasick([]))->search('apa saja'));
        $this->assertSame([], (new AhoCorasick(['slot' => 1]))->search('sl0t s-lot'));
    }

    public function test_repeated_occurrences_are_all_reported(): void
    {
        $this->assertCount(3, (new AhoCorasick(['aa' => 1]))->search('aaaa'));
    }
}
