<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_up');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->string('content_hash', 40)->nullable();
            $table->unsignedInteger('content_length')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamp('checked_at');

            $table->index(['website_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_checks');
    }
};
