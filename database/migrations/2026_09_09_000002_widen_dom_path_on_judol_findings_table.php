<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * dom_path was a plain string() - VARCHAR(255) - and a real breadcrumb overruns it.
 *
 * A WordPress footer widget sits ten wrappers deep, each carrying its own classes,
 * which produced paths of ~270 characters on the first compromised site this met.
 * MySQL does not truncate an overlong value, it raises SQLSTATE[22001], so the
 * INSERT threw and RegionFindings::persist() died with it - every finding for that
 * site lost, on every crawl. The symptom was a site with dozens of judol detections
 * and an empty Deteksi Judol list.
 *
 * PageRegion::MAX_PATH now caps the path at 500 as well; this widens the column
 * past that cap so the two cannot collide again at the boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('judol_findings', function (Blueprint $table) {
            $table->string('dom_path', 1000)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('judol_findings', function (Blueprint $table) {
            $table->string('dom_path')->nullable()->change();
        });
    }
};
