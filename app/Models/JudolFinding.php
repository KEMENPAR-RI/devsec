<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
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

    public function scopeNew(Builder $query): Builder
    {
        return $query->where('status', 'new');
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
}
