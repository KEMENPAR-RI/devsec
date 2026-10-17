<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Covers DashboardOverview's range aggregate; supersedes the single-column checked_at index.
    public function up(): void
    {
        // Create the new index before dropping the old one so range scans are never unindexed.
        Schema::table('website_checks', function (Blueprint $table) {
            $table->index(['checked_at', 'is_up', 'response_time_ms']);
        });

        if (Schema::hasIndex('website_checks', 'website_checks_checked_at_index')) {
            Schema::table('website_checks', function (Blueprint $table) {
                $table->dropIndex('website_checks_checked_at_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasIndex('website_checks', 'website_checks_checked_at_index')) {
            Schema::table('website_checks', function (Blueprint $table) {
                $table->index('checked_at');
            });
        }

        Schema::table('website_checks', function (Blueprint $table) {
            $table->dropIndex(['checked_at', 'is_up', 'response_time_ms']);
        });
    }
};
