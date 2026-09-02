<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => null,
            'permissions' => [],
            'is_superadmin' => false,
            'is_locked' => false,
        ];
    }

    public function superadmin(): static
    {
        return $this->state(['name' => 'Superadmin', 'slug' => 'superadmin', 'is_superadmin' => true, 'is_locked' => true]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->state(['permissions' => $permissions]);
    }
}
