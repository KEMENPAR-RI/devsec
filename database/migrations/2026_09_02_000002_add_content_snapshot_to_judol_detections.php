<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keeps the flagged page's own text so the dashboard can answer "apa saja yang
     * berubah", not just "ada kata kunci judi di sini".
     *
     * - content_snapshot: the page's visible text, whitespace-normalized and capped
     *   (see JudolScanner::SNAPSHOT_LIMIT). Enough to quote the injected passages
     *   back to the admin without storing whole 500KB spam pages.
     * - content_hash: sha1 of that snapshot, so a re-check can tell "changed" from
     *   "identical" without comparing the whole blob.
     * - content_changes: what a re-check found added/removed versus the snapshot it
     *   replaced. Null until a page is actually re-checked and turns out different -
     *   there is nothing to diff against on the very first detection.
     */
    public function up(): void
    {
        Schema::table('judol_detections', function (Blueprint $table) {
            $table->longText('content_snapshot')->nullable()->after('snippet');
            $table->string('content_hash', 40)->nullable()->after('content_snapshot');
            $table->json('content_changes')->nullable()->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('judol_detections', function (Blueprint $table) {
            $table->dropColumn(['content_snapshot', 'content_hash', 'content_changes']);
        });
    }
};
