<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the injected markup looks like and where exactly it sits, so a finding
 * points at a node instead of at a page.
 *
 * region_label already said "Footer", which is the right unit for the FIX but too
 * coarse for the person who has to go and find it - a themed footer runs to
 * hundreds of lines. These columns answer "letak persisnya" without a headless
 * browser: the path walks straight down to the element, and the snippet is the
 * markup itself.
 *
 * - image_urls: promo banners sitting inside the injected region. Gambling spam
 *   routinely carries its pitch as an image, which a text keyword filter cannot
 *   see at all. Absolute URLs, served through JudolEvidenceController rather than
 *   hotlinked - this app's CSP is img-src 'self' data:.
 * - evidence_html: the region container's own markup, capped. Rendered escaped,
 *   never as live HTML: it came off a compromised site.
 * - dom_path: a breadcrumb like "body > footer.site-footer > ul > li:nth-child(4)",
 *   to paste into devtools or grep for in the theme file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('judol_findings', function (Blueprint $table) {
            $table->json('image_urls')->nullable()->after('matched_keywords');
            $table->text('evidence_html')->nullable()->after('image_urls');
            $table->string('dom_path')->nullable()->after('evidence_html');
        });
    }

    public function down(): void
    {
        Schema::table('judol_findings', function (Blueprint $table) {
            $table->dropColumn(['image_urls', 'evidence_html', 'dom_path']);
        });
    }
};
