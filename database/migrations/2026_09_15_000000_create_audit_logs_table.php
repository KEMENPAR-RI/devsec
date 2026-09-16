<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who did what, and from where.
     *
     * The actor is stored twice on purpose: a foreign key for linking, and a
     * snapshot of the name/email for reading. Deleting a user must not turn every
     * line they left behind into "pengguna tidak dikenal" - that is precisely the
     * trail an attacker would want erased.
     *
     * There is no updated_at: a row that can be edited is not evidence.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();

            // e.g. 'login.berhasil', 'situs.dihapus' - kept as free text so a new
            // event needs no migration.
            $table->string('event');
            $table->string('subject')->nullable();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            // Before/after for the fields that actually changed; secrets redacted.
            $table->json('changes')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('created_at')->index();

            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
