<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Uptime aggregates now read error_type (WebsiteCheck::UP_SQL); keep them index-only.
    // Explicit name: the generated one is 65 chars, over MySQL's 64 limit.
    private const INDEX = 'website_checks_checked_at_covering_index';

    public function up(): void
    {
        // Create the new index before dropping the old one so range scans are never unindexed.
        Schema::table('website_checks', function (Blueprint $table) {
            $table->index(['checked_at', 'is_up', 'error_type', 'response_time_ms'], self::INDEX);
        });

        Schema::table('website_checks', function (Blueprint $table) {
            $table->dropIndex(['checked_at', 'is_up', 'response_time_ms']);
        });
    }

    public function down(): void
    {
        Schema::table('website_checks', function (Blueprint $table) {
            $table->index(['checked_at', 'is_up', 'response_time_ms']);
        });

        Schema::table('website_checks', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
