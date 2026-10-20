<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Global phrase exclusions, manageable from the Deteksi Judol page: a keyword
     * hit whose span sits entirely inside an exclusion ("bandar" inside "bandar
     * lampung") is dropped. Seeded from config('judol.default_exclusions') so the
     * obvious ambiguous phrases are covered from day one.
     */
    public function up(): void
    {
        Schema::create('judol_exclusions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('phrase', 150)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        $rows = collect(config('judol.default_exclusions', []))
            ->unique()
            ->map(fn (string $phrase) => [
                'uuid' => (string) Str::uuid(),
                'phrase' => $phrase,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        foreach ($rows->chunk(100) as $chunk) {
            DB::table('judol_exclusions')->insert($chunk->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('judol_exclusions');
    }
};
