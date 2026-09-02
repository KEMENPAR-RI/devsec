<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds a public uuid to every domain table so nothing outside the server ever has
 * to quote a real primary key. The integer keys stay - they still carry the
 * foreign keys and indexes - but URLs, Livewire action arguments, and anything
 * else the browser can see now use the uuid instead. See App\Models\Concerns\HasUuid.
 *
 * The column is added nullable, backfilled row by row, and only then made unique,
 * so this runs safely against a database that already has data in it. (roles
 * already had a uuid from its own migration and is deliberately absent here.)
 */
return new class extends Migration
{
    /** Tables that get a uuid, in dependency-free order. */
    protected array $tables = [
        'users',
        'websites',
        'website_checks',
        'incidents',
        'judol_detections',
        'judol_keywords',
        'settings',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'uuid')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->uuid('uuid')->nullable()->after('id');
            });

            // chunkById over the query builder: no model events, no memory blow-up
            // on the big tables (website_checks can hold hundreds of thousands).
            DB::table($table)->whereNull('uuid')->orderBy('id')->chunkById(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
                }
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->uuid('uuid')->nullable(false)->unique()->change();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'uuid')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropUnique($table.'_uuid_unique');
                $blueprint->dropColumn('uuid');
            });
        }
    }
};
