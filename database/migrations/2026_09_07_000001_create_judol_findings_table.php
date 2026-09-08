<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per PLACE the spam was injected, rather than one per URL carrying it.
 *
 * judol_detections stays as it is: it answers "what is on this page", which is
 * still the right question for a page that was created by the attacker (a stray
 * /slot-gacor.html) or defaced on its own. This table answers the other one -
 * "what was changed" - for the far more common case where a single edit to a
 * shared template puts the same backlinks into every page on the site.
 *
 * Recording those per URL produced forty rows that all said the same thing and
 * buried the only actionable fact: it is in the footer, so fixing the footer
 * fixes all forty. Here the affected URLs are a list inside one finding.
 *
 * Existing detections are left alone. The region of a past finding was never
 * recorded, so grouping them retroactively would be guesswork - see the answer
 * to that question in the conversation this came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('judol_findings', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();

            // The region signature (PageRegion::locate()'s key) - 'footer',
            // 'header', 'widget', ... - and the human label shown in the UI.
            $table->string('region_key', 64);
            $table->string('region_label');

            // A CSS-ish pointer at the container, e.g. "footer.site-footer", so an
            // operator can find it in the page source. Null when the markup gave
            // nothing to anchor to.
            $table->string('region_selector')->nullable();

            // What kind of injection this is. Kept as a string rather than an enum
            // so a new attack shape does not need a migration to be reportable.
            $table->string('attack_type', 32)->default('backlink_injection');

            // The evidence: gambling domains linked to, the anchor text used, and
            // any keywords that matched inside this region.
            $table->json('linked_domains')->nullable();
            $table->json('anchor_texts')->nullable();
            $table->json('matched_keywords')->nullable();

            // Every URL where this same region was seen carrying the injection,
            // plus the count - so the UI can say "40 halaman" without loading the
            // whole list, and cap what it stores on a very large site.
            $table->json('affected_urls');
            $table->unsignedInteger('affected_count')->default(0);

            $table->enum('status', ['new', 'reviewed', 'ignored'])->default('new');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            // One finding per region per site: a second crawl updates the row it
            // already has instead of creating a parallel one.
            $table->unique(['website_id', 'region_key', 'attack_type'], 'judol_findings_region_unique');
            $table->index(['website_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('judol_findings');
    }
};
