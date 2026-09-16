<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How well a monitored site defends its own visitors.
     *
     * This app already sets a careful set of browser-hardening headers on itself
     * (see App\Http\Middleware\SecurityHeaders) but never looked at whether the
     * sites it watches set any. That is a cheap thing to measure - the headers
     * arrive on every response it already fetches - and for an institution it is
     * the part of a monitoring report that leads to actual remediation work.
     *
     * Informational by design: no incident is raised from a grade. Headers change
     * about once a year, and a nightly "still a C" alert is noise.
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->unsignedTinyInteger('security_score')->nullable()->after('domain_checked_at');
            $table->string('security_grade', 2)->nullable()->after('security_score');
            $table->json('security_findings')->nullable()->after('security_grade');
            $table->timestamp('security_checked_at')->nullable()->after('security_findings');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['security_score', 'security_grade', 'security_findings', 'security_checked_at']);
        });
    }
};
