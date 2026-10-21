<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Injection risk verdict per event. Legacy rows keep null severity. The
     * indexes back the default severity filter (warning,critical) on the
     * Perubahan Konten list, per site and across all sites, sorted by detected_at.
     */
    public function up(): void
    {
        Schema::table('page_changes', function (Blueprint $table) {
            // none | info | warning | critical; null = legacy row
            $table->string('severity', 8)->nullable()->after('content_kind');
            $table->unsignedSmallInteger('risk_score')->nullable()->after('severity');
            // list<{code, detail, weight}>, <=50 entries
            $table->json('risk_signals')->nullable()->after('change_ratio');

            $table->index(['website_id', 'severity', 'detected_at']);
            $table->index(['severity', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::table('page_changes', function (Blueprint $table) {
            $table->dropIndex(['website_id', 'severity', 'detected_at']);
            $table->dropIndex(['severity', 'detected_at']);
            $table->dropColumn(['severity', 'risk_score', 'risk_signals']);
        });
    }
};
