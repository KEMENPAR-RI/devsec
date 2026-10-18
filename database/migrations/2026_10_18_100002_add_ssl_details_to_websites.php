<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            // Last daily certificate snapshot; ssl_checked_at stays the gate.
            $table->timestamp('ssl_expires_at')->nullable()->after('ssl_checked_at');
            $table->string('ssl_issuer')->nullable()->after('ssl_expires_at');
            $table->json('ssl_names')->nullable()->after('ssl_issuer');
            $table->boolean('ssl_chain_valid')->nullable()->after('ssl_names');
            $table->boolean('ssl_hostname_match')->nullable()->after('ssl_chain_valid');
            $table->string('ssl_error', 255)->nullable()->after('ssl_hostname_match');

            // CDN/LB sites: IP changes downgrade to warning; drift_at throttles forced re-checks.
            $table->boolean('dns_ip_rotates')->default(false)->after('dns_checked_at');
            $table->timestamp('dns_ip_drift_at')->nullable()->after('dns_ip_rotates');

            // 'rdap' | 'manual'
            $table->string('domain_expiry_source', 10)->nullable()->after('domain_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn([
                'ssl_expires_at', 'ssl_issuer', 'ssl_names', 'ssl_chain_valid', 'ssl_hostname_match', 'ssl_error',
                'dns_ip_rotates', 'dns_ip_drift_at',
                'domain_expiry_source',
            ]);
        });
    }
};
