<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebsiteCheck extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'website_id',
        'is_up',
        'status_code',
        'response_time_ms',
        'content_hash',
        'content_length',
        'error_message',
        'error_type',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_up' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    /** Fits varchar(255) as valid UTF-8: transport errors embed remote-controlled URLs, and strict mode rejects overflow. */
    protected function errorMessage(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : Str::limit(mb_scrub($value, 'UTF-8'), 250),
        );
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * Uptime counts a slow-but-answered check as up; is_up itself keeps meaning
     * "passed the check" (see ADR 0001). Shared by every uptime aggregate.
     */
    public const UP_SQL = "(is_up = 1 OR error_type = 'slow')";

    /** Lookback bound for the per-site ROW_NUMBER() queries (index-covered). */
    public const RANKED_WINDOW_DAYS = 7;

    /**
     * Last $size is_up results per site, oldest first, in one windowed query.
     *
     * @param  list<int>  $ids
     * @return array<int, list<bool>>
     */
    public static function historyFor(array $ids, int $size): array
    {
        if ($ids === [] || $size < 1) {
            return [];
        }

        $ranked = static::query()
            ->select(['website_id', 'is_up', 'checked_at', 'id'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY website_id ORDER BY checked_at DESC, id DESC) AS rn')
            ->whereIn('website_id', $ids)
            ->where('checked_at', '>=', now()->subDays(self::RANKED_WINDOW_DAYS));

        $rows = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('rn', '<=', $size)
            ->orderBy('website_id')
            ->orderBy('checked_at')
            ->orderBy('id')
            ->get(['website_id', 'is_up']);

        $history = [];
        foreach ($rows as $row) {
            $history[(int) $row->website_id][] = (bool) $row->is_up;
        }

        return $history;
    }
}
