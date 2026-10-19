<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('websites', 'is_priority')) {
            return;
        }

        Schema::table('websites', function (Blueprint $table) {
            $table->boolean('is_priority')->default(false)->index()->after('is_internal_only');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('websites', 'is_priority')) {
            return;
        }

        // SQLite can't drop a column that still has an index on it.
        if (Schema::hasIndex('websites', 'websites_is_priority_index')) {
            Schema::table('websites', function (Blueprint $table) {
                $table->dropIndex('websites_is_priority_index');
            });
        }

        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('is_priority');
        });
    }
};
