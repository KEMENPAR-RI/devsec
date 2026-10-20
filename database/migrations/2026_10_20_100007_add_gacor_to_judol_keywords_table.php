<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const MARKER = '2026-10-20 10:00:07';

    /**
     * Adds the single weak keyword "gacor" (weight 1) to existing installs. Fresh
     * installs already get it from config('judol.keywords'), so this is a no-op there.
     * An admin-edited row is left untouched.
     */
    public function up(): void
    {
        DB::table('judol_keywords')->insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'keyword' => 'gacor',
            'weight' => 1,
            'is_active' => true,
            'created_at' => self::MARKER,
            'updated_at' => self::MARKER,
        ]);
    }

    // Only the untouched row this migration inserted carries the marker timestamps.
    public function down(): void
    {
        DB::table('judol_keywords')
            ->where('keyword', 'gacor')
            ->where('weight', 1)
            ->where('created_at', self::MARKER)
            ->where('updated_at', self::MARKER)
            ->delete();
    }
};
