<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The current text of a crawled page plus its frozen defacement baseline. The
 * full clean text lives compressed in content_gz; read it through text(), never
 * the raw column. The baseline columns move only on a clean crawl (or a manual
 * "legitimate" mark), which is what keeps defacement comparing against a known
 * good version rather than the previous crawl.
 */
class PageSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'url',
        'url_hash',
        'title',
        'description',
        'content_hash',
        'content_gz',
        'text_length',
        'block_count',
        'last_modified_at',
        'raw_sha256',
        'raw_bytes',
        'raw_stored',
        'http_status',
        'final_url',
        'redirect_count',
        'elements_hash',
        'elements',
        'risk_hash',
        'risk_profile',
        'status',
        'missed_crawls',
        'baseline_hash',
        'baseline_fingerprint',
        'baseline_title',
        'baseline_excerpt',
        'baseline_text_length',
        'baseline_signatures',
        'baseline_at',
        'first_seen_at',
        'last_crawled_at',
    ];

    // Blobs never belong in JSON responses or Livewire payloads.
    protected $hidden = ['content_gz', 'baseline_fingerprint', 'elements', 'risk_profile'];

    protected function casts(): array
    {
        return [
            'baseline_signatures' => 'array',
            'elements' => 'array',
            'risk_profile' => 'array',
            'raw_bytes' => 'integer',
            'raw_stored' => 'boolean',
            'http_status' => 'integer',
            'redirect_count' => 'integer',
            'text_length' => 'integer',
            'block_count' => 'integer',
            'missed_crawls' => 'integer',
            'baseline_text_length' => 'integer',
            'last_modified_at' => 'datetime',
            'baseline_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_crawled_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(PageChange::class);
    }

    /** The decompressed clean text, or null when no blob is stored. */
    public function text(): ?string
    {
        if (blank($this->content_gz)) {
            return null;
        }

        $text = @gzuncompress($this->content_gz);

        return $text === false ? null : $text;
    }
}
