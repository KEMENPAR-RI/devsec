<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A defacement finding per affected page: either content defacement (text or
     * structure replaced vs baseline) or the existing code-injection kind (foreign
     * script / hidden iframe / obfuscated JS) surfaced here too.
     *
     * One row per website+url+kind: the next scan updates it rather than piling up
     * duplicates. Linking to an incident keeps the operator-facing timeline in the
     * Insiden menu; statuses track its lifecycle (open -> recovered | legitimate |
     * resolved).
     */
    public function up(): void
    {
        Schema::create('defacement_findings', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('page_snapshot_id')->nullable()->constrained()->nullOnDelete();

            $table->string('url', 2048);
            $table->char('url_hash', 40);
            // content | injection
            $table->string('kind', 16);

            // list of {code: signature|replaced|shrink|foreign_script|hidden_frame
            // |obfuscated, detail: string}
            $table->json('reasons')->nullable();
            $table->decimal('change_ratio', 5, 4)->nullable();

            $table->string('before_title')->nullable();
            $table->string('after_title')->nullable();
            $table->text('before_excerpt')->nullable();
            $table->text('after_excerpt')->nullable();

            // open | recovered | legitimate | resolved
            $table->string('status', 16)->default('open');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['website_id', 'url_hash', 'kind']);
            $table->index(['website_id', 'status']);
            $table->index(['status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('defacement_findings');
    }
};
