<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some sites (e.g. behind a hairpin-NAT-affected internal network, or an
     * intranet-only service) only ever resolve/respond from inside their own
     * organization's network, never from wherever this monitor's own server sits -
     * a real, permanent limitation, not a transient outage. Marking a site this way
     * shows it as "restricted" (amber) instead of "down" (red) when checks fail, so
     * admins aren't paged for something monitoring was never going to be able to see.
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->boolean('is_internal_only')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('is_internal_only');
        });
    }
};
