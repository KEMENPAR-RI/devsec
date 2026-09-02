<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Re-runs the role seeder so existing installs pick up the reshaped permission
 * set: Admin is now view-only, and the Insiden / Deteksi Judol pages no longer
 * have a "create" ability at all (nothing on either page is created by hand).
 *
 * The seeder is updateOrCreate-based, so this only touches the two built-in roles
 * and leaves any custom role exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RoleSeeder)->run();
    }

    public function down(): void
    {
        // Role permissions are editable data; there is no earlier state worth
        // restoring over whatever an admin has since configured.
    }
};
