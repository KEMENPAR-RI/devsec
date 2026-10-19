<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Default only; existing rows keep their current value.
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->unsignedSmallInteger('judol_scan_interval_minutes')->default(360)->change();
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->unsignedSmallInteger('judol_scan_interval_minutes')->default(60)->change();
        });
    }
};
