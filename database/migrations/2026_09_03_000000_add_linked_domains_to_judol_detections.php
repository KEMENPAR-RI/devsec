<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The gambling sites a flagged page links OUT to, as judged by the AI link
     * classifier (see JudolLinkClassifier).
     *
     * This is a second, independent kind of evidence next to matched_keywords, and
     * it catches a compromise the keyword filter structurally cannot see: backlink
     * injection, where the page keeps its normal content and only gains hidden
     * <a href="https://some-slot-brand.com"> tags. Nothing about the visible text
     * changes, so no keyword ever matches - the only tell is where the page points.
     *
     * Stored per detection rather than per site: which page carries the injected
     * links is exactly what the person cleaning up needs to know.
     */
    public function up(): void
    {
        Schema::table('judol_detections', function (Blueprint $table) {
            $table->json('linked_domains')->nullable()->after('matched_keywords');
        });
    }

    public function down(): void
    {
        Schema::table('judol_detections', function (Blueprint $table) {
            $table->dropColumn('linked_domains');
        });
    }
};
