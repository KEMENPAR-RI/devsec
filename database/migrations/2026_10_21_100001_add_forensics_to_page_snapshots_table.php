<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Forensic state of the last complete crawl: hash/size of the raw body (the
     * raw copy itself lives on disk, see ADR 0001), HTTP status and final URL, and
     * the normalized non-text element inventory. No new index: rows are always
     * reached by id or the (website_id, url_hash) unique key.
     */
    public function up(): void
    {
        Schema::table('page_snapshots', function (Blueprint $table) {
            // SHA-256 hex of the raw body; also guards the on-disk raw copy.
            $table->char('raw_sha256', 64)->nullable()->after('last_modified_at');
            $table->unsignedInteger('raw_bytes')->nullable()->after('raw_sha256');
            $table->boolean('raw_stored')->default(false)->after('raw_bytes');
            $table->unsignedSmallInteger('http_status')->nullable()->after('raw_stored');
            $table->string('final_url', 2048)->nullable()->after('http_status');
            $table->unsignedTinyInteger('redirect_count')->nullable()->after('final_url');
            // sha1 of the normalized inventory; null = no non-text baseline yet.
            $table->char('elements_hash', 40)->nullable()->after('redirect_count');
            // {key: value}, <=500 entries; never preloaded by the crawl worker.
            $table->json('elements')->nullable()->after('elements_hash');
        });
    }

    public function down(): void
    {
        Schema::table('page_snapshots', function (Blueprint $table) {
            $table->dropColumn([
                'raw_sha256',
                'raw_bytes',
                'raw_stored',
                'http_status',
                'final_url',
                'redirect_count',
                'elements_hash',
                'elements',
            ]);
        });
    }
};
