<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable forensic detail of one content-change event (1:1). The payload is
     * gzipped JSON v1, capped at 65,000 bytes upstream so it fits a BLOB. Deleted
     * with its event through the FK cascade (manual delete, filter delete, prune).
     */
    public function up(): void
    {
        Schema::create('page_change_forensics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_change_id')->unique()->constrained()->cascadeOnDelete();
            $table->binary('payload_gz');
            // JSON size before compression
            $table->unsignedInteger('payload_bytes');
            $table->unsignedSmallInteger('item_count');
            $table->unsignedInteger('undetailed_count');
            $table->unsignedTinyInteger('schema_version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_change_forensics');
    }
};
