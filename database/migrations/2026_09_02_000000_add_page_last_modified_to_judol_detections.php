<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The flagged page's own Last-Modified header, captured when it was fetched.
     * detected_at only says when WE noticed the spam; this says when the page
     * itself last changed - i.e. roughly when the injection actually happened,
     * which is usually much earlier and is the thing an admin needs to correlate
     * against their access logs / backups. Nullable: plenty of servers (and any
     * dynamically rendered page) don't send the header at all.
     */
    public function up(): void
    {
        Schema::table('judol_detections', function (Blueprint $table) {
            $table->timestamp('page_last_modified_at')->nullable()->after('snippet');
        });
    }

    public function down(): void
    {
        Schema::table('judol_detections', function (Blueprint $table) {
            $table->dropColumn('page_last_modified_at');
        });
    }
};
