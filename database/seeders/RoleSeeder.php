<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/**
 * The two roles the app ships with. Both are locked: they can be inspected and
 * their permissions reviewed, but not renamed or deleted, so there's always a way
 * back into the app after someone experiments with custom roles.
 *
 * Safe to re-run - it updates the seeded roles in place rather than duplicating
 * them, which is also how a new page added to App\Support\Permissions gets picked
 * up by the Admin role (Superadmin needs no help; is_superadmin covers everything).
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::updateOrCreate(['slug' => 'superadmin'], [
            'name' => 'Superadmin',
            'description' => 'Akses penuh ke seluruh halaman dan seluruh aksi, termasuk halaman yang ditambahkan nanti.',
            'permissions' => Permissions::all(),
            'is_superadmin' => true,
            'is_locked' => true,
        ]);

        Role::updateOrCreate(['slug' => 'admin'], [
            'name' => 'Admin',
            'description' => 'Hanya bisa melihat data - tidak bisa menambah, mengubah, atau menghapus apa pun.',
            'permissions' => Permissions::readOnly(),
            'is_superadmin' => false,
            'is_locked' => true,
        ]);
    }
}
