<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Website extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'name',
        'url',
        'is_active',
        'is_internal_only',
        'check_interval_minutes',
        'judol_scan_interval_minutes',
        'down_threshold_failures',
        'response_time_threshold_ms',
        'last_status',
        'consecutive_failures',
        'last_checked_at',
        'judol_scanned_at',
        'ssl_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_internal_only' => 'boolean',
            'last_checked_at' => 'datetime',
            'judol_scanned_at' => 'datetime',
            'ssl_checked_at' => 'datetime',
        ];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(WebsiteCheck::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function judolDetections(): HasMany
    {
        return $this->hasMany(JudolDetection::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether this website's check interval has elapsed (or it has never been checked).
     */
    public function isDueForCheck(): bool
    {
        return $this->last_checked_at === null
            || $this->last_checked_at->addMinutes($this->check_interval_minutes)->isPast();
    }

    /**
     * Whether this website's judol-scan interval has elapsed (or it has never
     * been scanned) - a full-site crawl is much heavier than an uptime ping, so
     * it needs its own (longer) cadence rather than firing every schedule tick.
     */
    public function isDueForJudolScan(): bool
    {
        return $this->judol_scanned_at === null
            || $this->judol_scanned_at->addMinutes($this->judol_scan_interval_minutes)->isPast();
    }

    /**
     * A site down for a full day straight stops being "urgent, just happened" (red)
     * and becomes "known, long-term offline" (gray) - the same underlying down state,
     * just a calmer color once it's no longer fresh news. Driven off the open
     * downtime incident's detected_at (when the current down streak began), not a
     * separate stored flag.
     */
    public function isOffline(): bool
    {
        if ($this->last_status !== 'down') {
            return false;
        }

        return $this->incidents()
            ->where('type', 'downtime')
            ->open()
            ->where('detected_at', '<=', now()->subDay())
            ->exists();
    }

    /**
     * Bulk version of isOffline() for list views - one query for the whole list
     * instead of one query per row (which isOffline() alone would mean in a loop).
     *
     * @param  iterable<Website>  $websites
     * @return array<int, true> website IDs that are offline, keyed by id for O(1) isset() lookups
     */
    public static function offlineIds(iterable $websites): array
    {
        $downIds = collect($websites)->where('last_status', 'down')->pluck('id');

        if ($downIds->isEmpty()) {
            return [];
        }

        return Incident::where('type', 'downtime')
            ->open()
            ->whereIn('website_id', $downIds)
            ->where('detected_at', '<=', now()->subDay())
            ->pluck('website_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * The status this site should be DISPLAYED as - distinct from last_status
     * (the raw monitoring state used for down_threshold_failures/incidents, which
     * never changes meaning). Consolidates the color/label logic that used to be
     * duplicated across every blade view that renders a status dot - see
     * <x-status-dot>.
     *
     * - 'internal' takes priority over 'offline': a site marked is_internal_only
     *   is expected to look "down" from here forever, so there's no "it's been a
     *   while, calm down" distinction to make for it the way there is for a
     *   normal outage.
     *
     * @param  bool  $isOffline  pass Website::offlineIds() membership for list views
     *                           (avoids an isOffline() query per row) or $this->isOffline() for a single record
     */
    public function statusKey(bool $isOffline = false): string
    {
        return match (true) {
            $this->last_status === 'up' => 'up',
            $this->last_status === 'down' && $this->is_internal_only => 'internal',
            $this->last_status === 'down' && $isOffline => 'offline',
            $this->last_status === 'down' => 'down',
            default => 'unknown',
        };
    }

    /**
     * Uptime percentage over the last N days, based on recorded checks.
     */
    public function uptimePercentage(int $days = 7): float
    {
        $checks = $this->checks()->where('checked_at', '>=', now()->subDays($days))->get(['is_up']);

        if ($checks->isEmpty()) {
            return 100.0;
        }

        return round($checks->where('is_up', true)->count() / $checks->count() * 100, 1);
    }
}
