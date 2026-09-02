<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            // Public identifier for the role, shown in the UI and safe to put in
            // URLs/exports without leaking how many roles exist.
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            // Granted permission names ("situs.update", ...) - see App\Support\Permissions.
            $table->json('permissions')->nullable();
            // A superadmin role bypasses the list above and holds every permission,
            // including ones added by a future release.
            $table->boolean('is_superadmin')->default(false);
            // Guards the seeded roles from being renamed or deleted out from under
            // the app, which would lock everyone out.
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('email')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });

        Schema::dropIfExists('roles');
    }
};
