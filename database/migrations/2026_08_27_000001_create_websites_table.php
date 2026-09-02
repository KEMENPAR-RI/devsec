<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('check_interval_minutes')->default(5);
            $table->unsignedTinyInteger('down_threshold_failures')->default(3);
            $table->unsignedInteger('response_time_threshold_ms')->default(3000);
            $table->enum('last_status', ['unknown', 'up', 'down'])->default('unknown');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('ssl_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
