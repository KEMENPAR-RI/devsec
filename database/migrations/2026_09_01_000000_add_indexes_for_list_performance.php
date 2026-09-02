<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Insiden and Deteksi Judol list pages sort/filter across ALL websites
     * (no website_id in the query at all), so incidents' existing
     * (website_id, type, status) index doesn't help them - MySQL can't use a
     * composite index efficiently when its leading column isn't filtered on.
     * Adds indexes matching the actual list-page access patterns (global sort by
     * detected_at, optionally filtered by type/status) so those stay fast as the
     * tables grow.
     */
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->index('detected_at');
            $table->index(['type', 'status', 'detected_at']);
        });

        Schema::table('judol_detections', function (Blueprint $table) {
            $table->index('detected_at');
            $table->index('status');
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['detected_at']);
            $table->dropIndex(['type', 'status', 'detected_at']);
        });

        Schema::table('judol_detections', function (Blueprint $table) {
            $table->dropIndex(['detected_at']);
            $table->dropIndex(['status']);
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->dropIndex(['name']);
        });
    }
};
