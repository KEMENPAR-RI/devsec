<?php

namespace Database\Factories;

use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Website>
 */
class WebsiteFactory extends Factory
{
    protected $model = Website::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'url' => 'https://'.fake()->unique()->domainName(),
            'is_active' => true,
            'check_interval_minutes' => 5,
            'down_threshold_failures' => 3,
            'response_time_threshold_ms' => 15000,
            'last_status' => 'unknown',
            'consecutive_failures' => 0,
        ];
    }
}
