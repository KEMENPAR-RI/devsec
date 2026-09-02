<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fixes ScanJudol dispatching a full-site crawl for every active website on
     * EVERY schedule tick (every minute), unlike CheckWebsites which already
     * respects check_interval_minutes. A full crawl (up to 60 pages, retries,
     * timeouts) can take minutes - dispatching it every minute regardless of
     * whether the previous one finished makes the queue grow unbounded, starving
     * every other job (including uptime checks for newly added sites) behind it.
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->unsignedSmallInteger('judol_scan_interval_minutes')->default(60)->after('check_interval_minutes');
            $table->timestamp('judol_scanned_at')->nullable()->after('last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['judol_scan_interval_minutes', 'judol_scanned_at']);
        });
    }
};
