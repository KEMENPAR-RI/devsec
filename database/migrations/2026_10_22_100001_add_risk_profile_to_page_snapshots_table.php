<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Structural risk baseline of the page (ADR 0002). No new index: rows are
     * always reached by id or the (website_id, url_hash) unique key.
     */
    public function up(): void
    {
        Schema::table('page_snapshots', function (Blueprint $table) {
            // sha1 of the sorted risk keys; null = no risk baseline yet (seed silently).
            $table->char('risk_hash', 40)->nullable()->after('elements');
            // {items, stats}, <=300 items; never preloaded by the crawl worker.
            $table->json('risk_profile')->nullable()->after('risk_hash');
        });
    }

    public function down(): void
    {
        Schema::table('page_snapshots', function (Blueprint $table) {
            $table->dropColumn(['risk_hash', 'risk_profile']);
        });
    }
};
