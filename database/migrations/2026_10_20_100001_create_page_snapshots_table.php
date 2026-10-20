<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per crawled page per site: the current text snapshot plus a frozen
     * defacement baseline. Content-change detection diffs against the snapshot;
     * defacement detection compares against the baseline, which only moves when a
     * page is clean (or is marked legitimate).
     *
     * Storage is kept small: content_gz is the gzcompressed clean text (capped),
     * and the baseline keeps only a fingerprint + excerpt, never a second full
     * copy. The crawl worker preloads snapshots WITHOUT the blob columns and reads
     * a blob per page only when the hash changed - so one page sits in memory at a
     * time (see architect §3 / RAM note).
     */
    public function up(): void
    {
        Schema::create('page_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            // sha1 of the normalized URL: a fixed-width key for the unique index,
            // since url itself is too long to index on MySQL.
            $table->char('url_hash', 40);

            $table->string('title')->nullable();
            $table->string('description', 500)->nullable();

            // sha1 of the masked text, and the compressed clean text it hashes.
            $table->char('content_hash', 40);
            $table->binary('content_gz')->nullable();
            $table->unsignedInteger('text_length')->default(0);
            $table->unsignedSmallInteger('block_count')->default(0);

            $table->timestamp('last_modified_at')->nullable();
            // active | removed | missing
            $table->string('status', 16)->default('active');
            $table->unsignedTinyInteger('missed_crawls')->default(0);

            // Frozen defacement baseline (no full second copy of the text).
            $table->char('baseline_hash', 40)->nullable();
            $table->binary('baseline_fingerprint')->nullable();
            $table->string('baseline_title')->nullable();
            $table->string('baseline_excerpt', 1000)->nullable();
            $table->unsignedInteger('baseline_text_length')->nullable();
            $table->json('baseline_signatures')->nullable();
            $table->timestamp('baseline_at')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'url_hash']);
            $table->index(['website_id', 'last_crawled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_snapshots');
    }
};
