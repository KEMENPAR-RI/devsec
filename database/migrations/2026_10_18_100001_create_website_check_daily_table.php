<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Permanent per-site daily rollup, written before raw website_checks are pruned.
    public function up(): void
    {
        Schema::create('website_check_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('total_checks');
            $table->unsignedInteger('up_checks');
            $table->unsignedInteger('avg_response_ms')->nullable();
            // Exact sum/count of timed checks, so monthly averages are not built from rounded daily ones.
            $table->unsignedInteger('timed_checks')->default(0);
            $table->unsignedBigInteger('response_ms_sum')->default(0);
            $table->timestamps();

            // Upsert key; its website_id prefix also serves per-site range reads and the FK.
            $table->unique(['website_id', 'day']);
            // Cross-site range reads (monthly report over all sites).
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_check_daily');
    }
};
