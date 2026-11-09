<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // 'warning' = still answering but slower than response_time_threshold_ms.
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->enum('last_status', ['unknown', 'up', 'down', 'warning'])->default('unknown')->change();
        });
    }

    public function down(): void
    {
        // Slow sites were still answering, so they fold back into 'up'.
        DB::table('websites')->where('last_status', 'warning')->update(['last_status' => 'up']);

        Schema::table('websites', function (Blueprint $table) {
            $table->enum('last_status', ['unknown', 'up', 'down'])->default('unknown')->change();
        });
    }
};
