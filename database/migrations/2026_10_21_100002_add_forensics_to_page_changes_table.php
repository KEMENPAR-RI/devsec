<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Summary forensic columns per event for the list and detail header. Legacy
     * rows keep nulls (shown as "detail forensik tidak tersedia"), except
     * content_kind, which is backfilled: every existing "changed" event was a
     * text change. No new index: the kind filter always runs together with the
     * already-indexed detected_at range.
     */
    public function up(): void
    {
        Schema::table('page_changes', function (Blueprint $table) {
            // text | element | mixed; null for added/removed
            $table->string('content_kind', 12)->nullable()->after('type');
            $table->unsignedSmallInteger('http_status')->nullable()->after('page_last_modified_at');
            // null when equal to url
            $table->string('final_url', 2048)->nullable()->after('http_status');
            $table->unsignedTinyInteger('redirect_count')->nullable()->after('final_url');
            $table->string('hash_algorithm', 16)->nullable()->after('redirect_count');
            $table->char('body_hash_before', 64)->nullable()->after('hash_algorithm');
            $table->char('body_hash_after', 64)->nullable()->after('body_hash_before');
        });

        DB::table('page_changes')->where('type', 'changed')->update(['content_kind' => 'text']);
    }

    public function down(): void
    {
        Schema::table('page_changes', function (Blueprint $table) {
            $table->dropColumn([
                'content_kind',
                'http_status',
                'final_url',
                'redirect_count',
                'hash_algorithm',
                'body_hash_before',
                'body_hash_after',
            ]);
        });
    }
};
