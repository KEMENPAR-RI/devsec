<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One event per detected content change on a page: added, changed, or removed.
     * The baseline crawl of a page makes no event; only a real difference against
     * the previous successful crawl does. Dynamic-only differences (dates, counters,
     * tokens) are masked away upstream so they never land here.
     *
     * Events can point at a judol detection or a defacement finding they coincide
     * with, so the UI can cross-link. Pruned daily by detected_at.
     */
    public function up(): void
    {
        Schema::create('page_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url', 2048);

            // added | changed | removed
            $table->string('type', 16);
            $table->timestamp('previous_crawled_at')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('page_last_modified_at')->nullable();

            // <=8 chunks x <=500 chars each
            $table->json('added')->nullable();
            $table->json('removed')->nullable();
            // {title:{before,after}, description:{before,after}, reason: http_404|
            // http_410|not_reached}
            $table->json('meta_changes')->nullable();
            $table->decimal('change_ratio', 5, 4)->nullable();

            $table->foreignId('judol_detection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('defacement_finding_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['website_id', 'detected_at']);
            // Standalone detected_at index for the retention prune, which scans
            // across all sites by date.
            $table->index('detected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_changes');
    }
};
