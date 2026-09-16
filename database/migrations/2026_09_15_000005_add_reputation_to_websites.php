<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What Google says about a monitored domain.
     *
     * A site on Safe Browsing's list is, for practical purposes, as unreachable
     * as one that is down: Chrome puts a full-page red warning in front of it and
     * almost nobody clicks through. Nothing else this app measures catches that -
     * the server is up, the certificate is fine, the content may even be clean
     * again after a cleanup that Google has not re-crawled yet.
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('reputation_status', 16)->nullable()->after('security_checked_at');
            $table->json('reputation_threats')->nullable()->after('reputation_status');
            $table->timestamp('reputation_checked_at')->nullable()->after('reputation_threats');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->enum('type', ['downtime', 'attack', 'ssl', 'judol', 'dns', 'domain', 'reputation'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['reputation_status', 'reputation_threats', 'reputation_checked_at']);
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->enum('type', ['downtime', 'attack', 'ssl', 'judol', 'dns', 'domain'])->change();
        });
    }
};
