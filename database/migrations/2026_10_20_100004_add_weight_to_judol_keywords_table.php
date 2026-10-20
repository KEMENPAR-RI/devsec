<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gives each keyword a weight (Kuat 3 / Sedang 2 / Lemah 1) so scoring can tell
     * a single strong phrase ("slot gacor") from a lone weak one ("jackpot"), which
     * no longer flags a page on its own. Defaults to 3; the handful of weaker terms
     * are backfilled from config('judol.keyword_weights') so behavior matches the
     * weights the architect set, without a manual pass.
     */
    public function up(): void
    {
        Schema::table('judol_keywords', function (Blueprint $table) {
            $table->unsignedTinyInteger('weight')->default(3)->after('keyword');
        });

        foreach (config('judol.keyword_weights', []) as $keyword => $weight) {
            DB::table('judol_keywords')
                ->where('keyword', $keyword)
                ->update(['weight' => (int) $weight]);
        }
    }

    public function down(): void
    {
        Schema::table('judol_keywords', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }
};
