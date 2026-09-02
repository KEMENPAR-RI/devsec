<?php

use App\Services\Monitoring\IncidentInvestigator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Analyses written before IncidentInvestigator started stripping Markdown still
     * carry "## Heading" / "**bold**" syntax, which the incident panel prints
     * literally. Reuses the same cleaner so old rows read exactly like new ones.
     */
    public function up(): void
    {
        DB::table('incidents')
            ->whereNotNull('ai_analysis')
            ->orderBy('id')
            ->chunkById(100, function ($incidents) {
                foreach ($incidents as $incident) {
                    $clean = IncidentInvestigator::cleanAnalysisText($incident->ai_analysis);

                    if ($clean !== $incident->ai_analysis) {
                        DB::table('incidents')->where('id', $incident->id)->update(['ai_analysis' => $clean]);
                    }
                }
            });
    }

    public function down(): void
    {
        // The original Markdown isn't kept anywhere - nothing to restore.
    }
};
