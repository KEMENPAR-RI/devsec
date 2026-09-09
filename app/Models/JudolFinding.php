<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One injection site: a region of the site's markup carrying gambling spam, and
 * every page that renders it.
 *
 * The counterpart to JudolDetection. A detection describes a PAGE that is dirty;
 * a finding describes the EDIT that made pages dirty. When an attacker adds
 * backlinks to a shared footer, the useful unit of work is "clean the footer",
 * not "clean these forty pages" - and forty near-identical rows actively hide
 * that, which is why this exists.
 */
class JudolFinding extends Model
{
    use HasUuid;

    /**
     * Affected URLs kept per finding. A sitewide injection on a large site could
     * otherwise store thousands, and the list stops being informative long before
     * that - affected_count carries the real total.
     */
    public const MAX_STORED_URLS = 200;

    protected $fillable = [
        'website_id',
        'incident_id',
        'region_key',
        'region_label',
        'region_selector',
        'attack_type',
        'linked_domains',
        'anchor_texts',
        'matched_keywords',
        'image_urls',
        'evidence_html',
        'dom_path',
        'evidence_nodes',
        'affected_urls',
        'affected_count',
        'status',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'linked_domains' => 'array',
            'anchor_texts' => 'array',
            'matched_keywords' => 'array',
            'image_urls' => 'array',
            'evidence_nodes' => 'array',
            'affected_urls' => 'array',
            'affected_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * Whether this injection sits in shared markup rather than on one page.
     *
     * The distinction changes the remedy: a template edit is one fix for every
     * affected page, while a single dirty page is usually a file the attacker
     * added and the site owner can simply delete.
     */
    public function isSitewide(): bool
    {
        return $this->affected_count > 1;
    }

    /**
     * What happened, in the words an operator needs to act on it.
     */
    public function summary(): string
    {
        $domains = $this->linked_domains ?? [];
        $where = $this->region_label;

        if ($this->region_selector) {
            $where .= " ({$this->region_selector})";
        }

        $what = match ($this->attack_type) {
            'backlink_injection' => $domains === []
                ? 'Tautan ke situs judi disisipkan'
                : 'Tautan ke '.implode(', ', array_slice($domains, 0, 3))
                    .(count($domains) > 3 ? ' dan '.(count($domains) - 3).' domain lain' : '')
                    .' disisipkan',
            'keyword_injection' => 'Teks promosi judi disisipkan',
            default => 'Konten judi terdeteksi',
        };

        return $this->isSitewide()
            ? "{$what} di {$where}, muncul di {$this->affected_count} halaman."
            : "{$what} di {$where}.";
    }

    /**
     * The remedy, phrased for what was actually found. Sitewide findings point at
     * the template, because editing forty pages by hand is the wrong answer.
     */
    public function remediation(): string
    {
        if ($this->isSitewide()) {
            return "Periksa template yang merender {$this->region_label} - satu perbaikan di sana membersihkan semua {$this->affected_count} halaman sekaligus. "
                .'Cari tautan keluar yang tidak Anda pasang, lalu periksa log akses dan integritas berkas tema/plugin.';
        }

        return 'Periksa halaman ini dan hapus konten yang disisipkan. Bila halamannya sendiri tidak Anda buat, hapus berkasnya dan periksa jalur masuk penyerang.';
    }

    /** Human label for the attack type, for the UI. */
    public function attackLabel(): string
    {
        return match ($this->attack_type) {
            'backlink_injection' => 'Penyisipan backlink',
            'keyword_injection' => 'Penyisipan teks promosi',
            default => 'Konten judi',
        };
    }

    /**
     * What was found, reduced to a value two findings can be compared on.
     *
     * One edit by an attacker routinely lands in several regions at once - the same
     * link block pasted into the footer AND the sidebar. Those are separate rows
     * here, correctly, because each is a separate place to clean. But they are one
     * FINDING, and listing them as two repeats the whole evidence twice over. This
     * is what the UI groups on: identical evidence, one entry, the regions
     * underneath it as the list of places to go.
     *
     * Sorted before hashing so the order the crawler happened to see the domains in
     * cannot split a group.
     */
    public function evidenceSignature(): string
    {
        $domains = $this->linked_domains ?? [];
        $keywords = $this->matched_keywords ?? [];

        sort($domains);
        sort($keywords);

        return sha1(implode('|', [$this->attack_type, implode(',', $domains), implode(',', $keywords)]));
    }

    /**
     * Every place this injection was found, grouped by the markup around it.
     *
     * The list is what makes the finding actionable. One example of an eight-item
     * injection reads as the whole problem, so an operator cleans it, sees the page
     * flagged again on the next crawl, and stops trusting the report.
     *
     * Grouped rather than listed flat because several elements inside one block
     * resolve to the SAME container markup (see PageRegion::snippet(), which
     * deliberately returns the container): printing an identical footer eight times
     * buries the eight paths that are the actual difference between them.
     *
     * @return array<int, array{html: string, paths: array<int, string>}>
     */
    public function evidenceOccurrences(): array
    {
        $nodes = collect($this->evidence_nodes ?? []);

        // Findings recorded before evidence_nodes existed carry their single
        // sighting in the two older columns. Read here so those rows still show
        // what they have instead of going blank until the next crawl.
        if ($nodes->isEmpty() && filled($this->evidence_html)) {
            $nodes = collect([['path' => $this->dom_path, 'html' => $this->evidence_html]]);
        }

        return $nodes
            ->filter(fn ($node) => is_array($node) && filled($node['html'] ?? null))
            ->groupBy(fn (array $node) => sha1((string) $node['html']))
            ->map(fn ($group) => [
                'html' => (string) $group->first()['html'],
                'paths' => $group->pluck('path')->filter()->unique()->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** How many distinct elements this finding points at, for the count in the UI. */
    public function affectedElementCount(): int
    {
        return collect($this->evidenceOccurrences())
            ->sum(fn (array $occurrence) => max(1, count($occurrence['paths'])));
    }

    /**
     * The strings worth picking out of evidence_html when it is shown.
     *
     * A footer's markup is mostly the site's own, and the injected part is a few
     * characters in the middle of it - without this the operator reads 2000
     * characters looking for the one that matters.
     *
     * @return array<int, string>
     */
    public function highlightTerms(): array
    {
        return collect([$this->linked_domains, $this->matched_keywords, $this->anchor_texts])
            ->flatten()
            ->filter(fn ($term) => is_string($term) && trim($term) !== '')
            ->map(fn (string $term) => trim($term))
            ->unique()
            // Longest first, so "situs slot gacor" is marked whole rather than being
            // broken up by an earlier pass over "slot gacor".
            ->sortByDesc(fn (string $term) => mb_strlen($term))
            ->values()
            ->all();
    }
}
