<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Matches the database queue worker's pop query (queue + reserved_at/available_at filter).
    public function up(): void
    {
        if (Schema::hasIndex('jobs', 'jobs_queue_reserved_at_available_at_index')) {
            return;
        }

        Schema::table('jobs', function (Blueprint $table) {
            $table->index(['queue', 'reserved_at', 'available_at']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('jobs', 'jobs_queue_reserved_at_available_at_index')) {
            return;
        }

        Schema::table('jobs', function (Blueprint $table) {
            $table->dropIndex(['queue', 'reserved_at', 'available_at']);
        });
    }
};
