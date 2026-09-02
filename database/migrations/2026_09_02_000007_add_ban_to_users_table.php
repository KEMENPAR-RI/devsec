<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banning is a reversible block on an account, kept separate from deletion: the
 * user, their notifications, and the audit trail of what they did all stay, but
 * they cannot sign in and any live session of theirs is ended on its next request
 * (see App\Http\Middleware\EnsureUserIsNotBanned).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('banned_at')->nullable()->after('role_id');
            $table->string('banned_reason')->nullable()->after('banned_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['banned_at', 'banned_reason']);
        });
    }
};
