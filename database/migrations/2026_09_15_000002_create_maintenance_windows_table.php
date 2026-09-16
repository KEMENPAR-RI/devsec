<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Planned downtime, so the monitor stops paging people about an outage
     * somebody scheduled on purpose.
     *
     * False alarms are not a cosmetic problem: an operator who has learned that
     * the red dot is usually a migration window is an operator who will miss the
     * one that isn't. Checks keep running and keep being recorded through a
     * window - the history stays honest - only the incidents and the emails stop.
     *
     * website_id is nullable: a null one covers every site, which is what a
     * server-wide or network-wide maintenance actually looks like.
     */
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('website_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('reason');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            $table->timestamps();

            // The only question ever asked of this table: "is anything covering
            // this site right now" - one index per half of that.
            $table->index(['website_id', 'starts_at', 'ends_at']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_windows');
    }
};
