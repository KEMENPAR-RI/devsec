<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two checks that catch what an HTTP probe never can.
     *
     * A hijacked domain answers perfectly - it is simply answering from somebody
     * else's server, and the only visible trace is the NS or A record changing.
     * An expired domain, meanwhile, is the single most common way an institutional
     * site actually dies, and it is entirely preventable if anyone is told in time.
     *
     * dns_records holds the last-seen answer set so the next lookup has something
     * to compare against; there is nothing to report on the very first one.
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->json('dns_records')->nullable()->after('ssl_checked_at');
            $table->timestamp('dns_checked_at')->nullable()->after('dns_records');
            $table->date('domain_expires_at')->nullable()->after('dns_checked_at');
            $table->timestamp('domain_checked_at')->nullable()->after('domain_expires_at');
        });

        // The incident type is an enum, so the two new kinds have to be declared
        // before a row can use one. ->change() is used rather than raw ALTER SQL
        // because it is the only form that works on both MySQL (production) and
        // SQLite (the test suite, where the enum is a CHECK constraint and the
        // table has to be rebuilt).
        Schema::table('incidents', function (Blueprint $table) {
            $table->enum('type', ['downtime', 'attack', 'ssl', 'judol', 'dns', 'domain'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['dns_records', 'dns_checked_at', 'domain_expires_at', 'domain_checked_at']);
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->enum('type', ['downtime', 'attack', 'ssl', 'judol'])->change();
        });
    }
};
