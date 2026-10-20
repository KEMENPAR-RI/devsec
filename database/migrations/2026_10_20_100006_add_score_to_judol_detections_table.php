<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records the v2 scoring result on each detection: the summed score that
     * cleared the threshold, and match_details (per kept keyword: weight, where it
     * was found, and whether it was hidden) so the finding can be re-scored during
     * stale-cleanup and the UI can label hidden matches. Null on legacy rows.
     */
    public function up(): void
    {
        Schema::table('judol_detections', function (Blueprint $table) {
            $table->unsignedSmallInteger('score')->nullable()->after('matched_keywords');
            $table->json('match_details')->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('judol_detections', function (Blueprint $table) {
            $table->dropColumn(['score', 'match_details']);
        });
    }
};
