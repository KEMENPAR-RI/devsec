<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Moves the judol keyword list from a static config/judol.php array into a
     * manageable table (add/remove/toggle from the Deteksi Judol page), so admins
     * don't need a deploy to react to a new spam pattern. Backfills from the
     * existing config array so behavior is unchanged right after this migration.
     */
    public function up(): void
    {
        Schema::create('judol_keywords', function (Blueprint $table) {
            $table->id();
            $table->string('keyword')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        $rows = collect(config('judol.keywords', []))
            ->unique()
            ->map(fn (string $keyword) => [
                'keyword' => $keyword,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        foreach ($rows->chunk(100) as $chunk) {
            DB::table('judol_keywords')->insert($chunk->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('judol_keywords');
    }
};
