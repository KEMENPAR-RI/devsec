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
            'content_kind' => 'text',
            'previous_crawled_at' => now()->subDay(),
            'detected_at' => now(),
            'page_last_modified_at' => null,
            'added' => [fake()->sentence()],
            'removed' => [fake()->sentence()],
            'meta_changes' => null,
            'change_ratio' => fake()->randomFloat(4, 0, 1),
            'severity' => 'info',
            'risk_score' => 0,
            'risk_signals' => null,
        ];
    }

    public function critical(): static
    {
        return $this->state(fn () => [
            'severity' => 'critical',
            'risk_score' => 120,
            'risk_signals' => [
                ['code' => 'hidden_links', 'detail' => 'slot-gacor.example', 'weight' => 120],
            ],
        ]);
    }

    public function added(): static
    {
        return $this->state(fn () => [
            'type' => 'added',
            'content_kind' => null,
            'previous_crawled_at' => null,
            'removed' => null,
            'change_ratio' => null,
        ]);
    }

    public function removed(): static
    {
        return $this->state(fn () => [
            'type' => 'removed',
            'content_kind' => null,
            'added' => null,
            'removed' => null,
            'meta_changes' => ['reason' => 'http_404'],
            'change_ratio' => null,
        ]);
    }
}
