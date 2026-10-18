<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Failure category (timeout, dns, connect, ssl, http, slow, blocked); display only, so unindexed.
    public function up(): void
    {
        Schema::table('website_checks', function (Blueprint $table) {
            $table->string('error_type', 16)->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('website_checks', function (Blueprint $table) {
            $table->dropColumn('error_type');
        });
    }
};
