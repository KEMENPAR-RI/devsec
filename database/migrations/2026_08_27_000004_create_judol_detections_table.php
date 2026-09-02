<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('judol_detections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url');
            $table->json('matched_keywords');
            $table->text('snippet')->nullable();
            $table->enum('status', ['new', 'reviewed', 'ignored'])->default('new');
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->unique(['website_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('judol_detections');
    }
};
