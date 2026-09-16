<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One scheduled period during which a site's failures are expected.
 *
 * A window with no website_id covers every site - server migrations and network
 * work take everything down at once, and making an operator add one row per site
 * at 2am is how a window ends up covering only half of them.
 */
class MaintenanceWindow extends Model
{
    use Auditable, HasUuid;

    protected $fillable = ['website_id', 'created_by', 'reason', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function auditEventPrefix(): string
    {
        return 'pemeliharaan';
    }

    public function auditSubject(): string
    {
        return ($this->website?->name ?? 'Semua situs').' - '.$this->reason;
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Windows covering a given moment - now, by default. */
    public function scopeCovering(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $query->where('starts_at', '<=', $at)->where('ends_at', '>=', $at);
    }

    /** Windows for this site, plus the all-sites ones that also cover it. */
    public function scopeForWebsite(Builder $query, int $websiteId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('website_id', $websiteId)
            ->orWhereNull('website_id'));
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('ends_at', '>=', now());
    }

    public function isActive(): bool
    {
        return $this->starts_at <= now() && $this->ends_at >= now();
    }

    /** 'berlangsung' | 'terjadwal' | 'selesai' - what the badge says. */
    public function state(): string
    {
        return match (true) {
            $this->isActive() => 'berlangsung',
            $this->starts_at > now() => 'terjadwal',
            default => 'selesai',
        };
    }
}
