<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One recorded content-change event on a page (added | changed | removed). The
 * diff is kept as short chunk lists in added/removed; meta_changes carries the
 * title/description delta and the reason a page counts as removed.
 */
class PageChange extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'website_id',
        'page_snapshot_id',
        'url',
        'type',
        'content_kind',
        'severity',
        'risk_score',
        'previous_crawled_at',
        'detected_at',
        'page_last_modified_at',
        'http_status',
        'final_url',
        'redirect_count',
        'hash_algorithm',
        'body_hash_before',
        'body_hash_after',
        'added',
        'removed',
        'meta_changes',
        'change_ratio',
        'risk_signals',
        'judol_detection_id',
        'defacement_finding_id',
    ];

    protected function casts(): array
    {
        return [
            'added' => 'array',
            'removed' => 'array',
            'meta_changes' => 'array',
            'risk_signals' => 'array',
            'risk_score' => 'integer',
            'change_ratio' => 'decimal:4',
            'http_status' => 'integer',
            'redirect_count' => 'integer',
            'previous_crawled_at' => 'datetime',
            'detected_at' => 'datetime',
            'page_last_modified_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PageSnapshot::class, 'page_snapshot_id');
    }

    public function forensic(): HasOne
    {
        return $this->hasOne(PageChangeForensic::class);
    }

    public function judolDetection(): BelongsTo
    {
        return $this->belongsTo(JudolDetection::class);
    }

    public function defacementFinding(): BelongsTo
    {
        return $this->belongsTo(DefacementFinding::class);
    }

    /**
     * Applies the Perubahan Konten list filters: site (website uuid), date range
     * on detected_at (from/to, Y-m-d), event type, content kind (text | element;
     * mixed matches both), and a URL substring search.
     *
     * @param  array{site?: ?string, from?: ?string, to?: ?string, type?: ?string, kind?: ?string, search?: ?string}  $filters
     */
    public function scopeForFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(filled($filters['site'] ?? null), fn (Builder $q) => $q->whereHas(
                'website',
                fn (Builder $w) => $w->where('uuid', $filters['site'])
            ))
            ->when(filled($filters['from'] ?? null), fn (Builder $q) => $q->where('detected_at', '>=', Carbon::parse($filters['from'])->startOfDay()))
            ->when(filled($filters['to'] ?? null), fn (Builder $q) => $q->where('detected_at', '<=', Carbon::parse($filters['to'])->endOfDay()))
            ->when(filled($filters['type'] ?? null), fn (Builder $q) => $q->where('type', $filters['type']))
            ->when(in_array($filters['kind'] ?? null, ['text', 'element'], true), fn (Builder $q) => $q->whereIn(
                'content_kind',
                [$filters['kind'], 'mixed']
            ))
            // Wildcards are escaped: "%" must not turn into "match everything" (e.g. for deleteFiltered).
            ->when(filled($filters['search'] ?? null), fn (Builder $q) => $q->whereRaw(
                "url like ? escape '!'",
                ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%']
            ));
    }
}
