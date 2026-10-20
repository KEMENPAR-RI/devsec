<?php

namespace Database\Factories;

use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\PageChange>
 */
class PageChangeFactory extends Factory
{
    public function definition(): array
    {
        $url = 'https://'.fake()->domainName().'/'.fake()->slug();

        return [
            'website_id' => Website::factory(),
            'url' => $url,
            'type' => 'changed',
            'previous_crawled_at' => now()->subDay(),
            'detected_at' => now(),
            'page_last_modified_at' => null,
            'added' => [fake()->sentence()],
            'removed' => [fake()->sentence()],
            'meta_changes' => null,
            'change_ratio' => fake()->randomFloat(4, 0, 1),
        ];
    }

    public function added(): static
    {
        return $this->state(fn () => [
            'type' => 'added',
            'previous_crawled_at' => null,
            'removed' => null,
            'change_ratio' => null,
        ]);
    }

    public function removed(): static
    {
        return $this->state(fn () => [
            'type' => 'removed',
            'added' => null,
            'removed' => null,
            'meta_changes' => ['reason' => 'http_404'],
            'change_ratio' => null,
        ]);
    }
}
