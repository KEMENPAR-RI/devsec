<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records what the AI classifier concluded about each detection, so the list can
     * separate "Claude looked at this and agreed" from "keyword filter only".
     *
     * The classifier never creates a detection on its own - it vets what the keyword
     * filter already flagged (see JudolScanner::evaluateAndStore) - so the verdict is
     * about confidence in a finding, not about who found it:
     *
     *   confirmed   - AI agreed it is gambling spam
     *   disputed    - AI leaned "not spam" but under the confidence threshold, so the
     *                 keyword match still stands; worth a human look
     *   unavailable - AI was enabled but the call failed or returned nothing usable
     *   null        - AI was switched off when this page was scanned
     *
     * (A confident denial removes the detection entirely, so it is never stored.)
     */
    public function up(): void
    {
        Schema::table('judol_detections', function (Blueprint $table) {
            $table->string('ai_verdict', 20)->nullable()->after('content_changes');
            $table->decimal('ai_confidence', 3, 2)->nullable()->after('ai_verdict');
            $table->text('ai_reasoning')->nullable()->after('ai_confidence');
            $table->timestamp('ai_checked_at')->nullable()->after('ai_reasoning');
        });
    }

    public function down(): void
    {
        Schema::table('judol_detections', function (Blueprint $table) {
            $table->dropColumn(['ai_verdict', 'ai_confidence', 'ai_reasoning', 'ai_checked_at']);
        });
    }
};
