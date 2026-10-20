<?php

namespace Database\Factories;

use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\PageSnapshot>
 */
class PageSnapshotFactory extends Factory
{
    public function definition(): array
    {
        $url = 'https://'.fake()->domainName().'/'.fake()->slug();
        $text = fake()->paragraphs(3, true);

        return [
            'website_id' => Website::factory(),
            'url' => $url,
            'url_hash' => sha1($url),
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(8),
            'content_hash' => sha1($text),
            'content_gz' => gzcompress($text, 6),
            'text_length' => mb_strlen($text),
            'block_count' => fake()->numberBetween(5, 40),
            'last_modified_at' => null,
            'status' => 'active',
            'missed_crawls' => 0,
            'first_seen_at' => now()->subDays(7),
            'last_crawled_at' => now(),
        ];
    }

    /** A snapshot carrying a frozen baseline matching its current content. */
    public function withBaseline(): static
    {
        return $this->state(fn (array $attributes) => [
            'baseline_hash' => $attributes['content_hash'] ?? sha1(fake()->paragraph()),
            'baseline_title' => $attributes['title'] ?? fake()->sentence(4),
            'baseline_excerpt' => fake()->paragraph(),
            'baseline_text_length' => $attributes['text_length'] ?? 500,
            'baseline_signatures' => [],
            'baseline_at' => now()->subDays(7),
        ]);
    }

    public function removed(): static
    {
        return $this->state(fn () => ['status' => 'removed']);
    }
}
