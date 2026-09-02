<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Deliberately NOT using WithoutModelEvents: that trait mutes Eloquent's
     * "creating" event for the whole seeder run, and that event is what fills in
     * every record's uuid (App\Models\Concerns\HasUuid). With it on, a
     * `migrate:fresh --seed` died on the very first insert with
     * "Field 'uuid' doesn't have a default value".
     *
     * This app has no observers that seeding needs protecting from, so there is
     * nothing left for the trait to buy.
     *
     * Seed the application's database.
     */
    public function run(): void
    {
        // Roles first - the demo account below needs one to see anything at all.
        $this->call(RoleSeeder::class);

        // Demo account for the template (see login page hint).
        User::factory()->create([
            'name' => 'Superadmin',
            'email' => 'superadmin@example.com',
            'password' => bcrypt('123'),
            'role_id' => Role::where('slug', 'superadmin')->value('id'),
        ]);

        // Sample websites so the multi-site monitoring UI has data to show out of the box.
        collect([
            ['name' => 'Laravel', 'url' => 'https://laravel.com'],
            ['name' => 'Example Domain', 'url' => 'https://example.com'],
            ['name' => 'Situs Uji (sengaja tidak bisa diakses)', 'url' => 'https://situs-uji-tidak-ada.test'],
        ])->each(fn (array $site) => Website::firstOrCreate(['url' => $site['url']], [
            'name' => $site['name'],
            'is_active' => true,
        ]));
    }
}
