<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Access control is opt-out for accounts that predate it: without this, every
 * existing user would wake up with no role, no permissions, and an empty sidebar
 * the moment the feature ships. They were the only administrators of this install
 * before roles existed, so Superadmin is the honest mapping - individual accounts
 * can be demoted afterwards from the Users page.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RoleSeeder)->run();

        User::whereNull('role_id')->update([
            'role_id' => Role::where('slug', 'superadmin')->value('id'),
        ]);
    }

    public function down(): void
    {
        User::query()->update(['role_id' => null]);
        Role::whereIn('slug', ['superadmin', 'admin'])->delete();
    }
};
