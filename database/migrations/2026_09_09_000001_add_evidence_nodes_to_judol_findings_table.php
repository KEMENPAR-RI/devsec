<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every element an injection touched, not just the first one seen.
 *
 * dom_path and evidence_html (added the day before this) hold exactly one sighting
 * per finding: RegionFindings::rememberEvidence() took the first and kept it, and
 * the scanner had already narrowed to one before that - the first flagged link per
 * region, the first element per keyword. On a footer carrying eight injected list
 * items that reported one, which reads as "here is the problem" when it is really
 * "here is one eighth of it". An operator cleaning from that list leaves seven
 * behind, and the next crawl flags the site again.
 *
 * So: a list of {path, html} pairs, capped at RegionFindings::MAX_STORED_NODES.
 * The two older columns stay - they are the first entry of this list, and findings
 * recorded before this migration have nothing else to show until they are
 * re-crawled (see JudolFinding::evidenceOccurrences(), which falls back to them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('judol_findings', function (Blueprint $table) {
            $table->json('evidence_nodes')->nullable()->after('dom_path');
        });
    }

    public function down(): void
    {
        Schema::table('judol_findings', function (Blueprint $table) {
            $table->dropColumn('evidence_nodes');
        });
    }
};
