<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 3s was too aggressive as a default: it flagged legitimately-reachable-but-slow
     * sites (common for shared-hosting government/institutional sites) as "down"
     * ever since response-time-over-threshold started counting as down (see
     * WebsiteChecker::check()). Raises the column default to 15s and backfills
     * existing rows still sitting at the old 3000 default - not rows an admin
     * deliberately customized to some other value.
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->unsignedInteger('response_time_threshold_ms')->default(15000)->change();
        });

        DB::table('websites')->where('response_time_threshold_ms', 3000)->update(['response_time_threshold_ms' => 15000]);
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->unsignedInteger('response_time_threshold_ms')->default(3000)->change();
        });
    }
};
