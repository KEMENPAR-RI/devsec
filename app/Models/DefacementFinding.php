<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A defacement finding on one page - content defacement (text/structure replaced
 * vs baseline) or a code-injection site. One row per website+url+kind, updated in
 * place by the next scan. reasons lists why it was flagged; the before/after
 * excerpts quote the baseline against the current page.
 */
class DefacementFinding extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'website_id',
        'incident_id',
        'page_snapshot_id',
        'url',
        'url_hash',
        'kind',
        'reasons',
        'change_ratio',
        'before_title',
        'after_title',
        'before_excerpt',
        'after_excerpt',
        'status',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'change_ratio' => 'decimal:4',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PageSnapshot::class, 'page_snapshot_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }
}
