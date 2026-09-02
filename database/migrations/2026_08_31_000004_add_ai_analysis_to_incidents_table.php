<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores the on-demand AI root-cause analysis for an incident (see
     * IncidentInvestigator) - cached here so it's generated once per "Selidiki
     * Penyebab" click, not re-run on every page view.
     */
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->text('ai_analysis')->nullable()->after('meta');
            $table->timestamp('ai_analyzed_at')->nullable()->after('ai_analysis');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['ai_analysis', 'ai_analyzed_at']);
        });
    }
};
