<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Every page is permission-gated, so a factory user with no role could
            // not open anything - which is almost never what a test means by "a
            // logged-in user". Superadmin is the neutral default; pass
            // ['role_id' => ...] (or null) explicitly to test a narrower role.
            'role_id' => fn () => Role::where('slug', 'superadmin')->value('id')
                ?? Role::factory()->superadmin()->create()->id,
        ];
    }

    /** A signed-in account that holds no permissions at all. */
    public function withoutRole(): static
    {
        return $this->state(['role_id' => null]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
