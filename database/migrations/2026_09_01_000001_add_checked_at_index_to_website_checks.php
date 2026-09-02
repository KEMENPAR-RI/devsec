<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dashboard::weeklyUptime() aggregates across ALL websites (no website_id
     * filter), so the existing (website_id, checked_at) composite index doesn't
     * help it - same leading-column issue as the incidents/judol_detections
     * indexes added alongside this one. This runs on every dashboard render,
     * including every wire:poll tick.
     */
    public function up(): void
    {
        Schema::table('website_checks', function (Blueprint $table) {
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('website_checks', function (Blueprint $table) {
            $table->dropIndex(['checked_at']);
        });
    }
};
