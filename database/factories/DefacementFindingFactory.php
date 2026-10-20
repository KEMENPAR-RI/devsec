<?php

namespace Database\Factories;

use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\DefacementFinding>
 */
class DefacementFindingFactory extends Factory
{
    public function definition(): array
    {
        $url = 'https://'.fake()->domainName().'/';

        return [
            'website_id' => Website::factory(),
            'url' => $url,
            'url_hash' => sha1($url),
            'kind' => 'content',
            'reasons' => [['code' => 'signature', 'detail' => 'hacked by']],
            'change_ratio' => fake()->randomFloat(4, 0.5, 1),
            'before_title' => fake()->sentence(4),
            'after_title' => 'Hacked by '.fake()->userName(),
            'before_excerpt' => fake()->paragraph(),
            'after_excerpt' => 'Hacked by '.fake()->userName(),
            'status' => 'open',
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now(),
        ];
    }

    public function injection(): static
    {
        return $this->state(fn () => [
            'kind' => 'injection',
            'reasons' => [['code' => 'foreign_script', 'detail' => 'evil.example/js']],
            'change_ratio' => null,
        ]);
    }

    public function recovered(): static
    {
        return $this->state(fn () => [
            'status' => 'recovered',
            'resolved_at' => now(),
        ]);
    }

    public function legitimate(): static
    {
        return $this->state(fn () => [
            'status' => 'legitimate',
            'resolved_at' => now(),
        ]);
    }
}
