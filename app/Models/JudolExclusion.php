<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A global phrase that must not count as a judol match - "bandar lampung", "slot
 * waktu". A keyword hit whose span lies entirely inside an active exclusion is
 * dropped (see JudolMatcher). Cached like keywords, since the full list is read
 * on every crawled page.
 */
class JudolExclusion extends Model
{
    use Auditable, HasUuid;

    protected $fillable = ['phrase', 'is_active'];

    public function auditEventPrefix(): string
    {
        return 'pengecualian';
    }

    public function auditSubject(): string
    {
        return (string) $this->phrase;
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function booted(): void
    {
        // The automaton is built from keywords AND exclusions, so a change to
        // either must drop the shared rules cache too. No auth()/request() here -
        // this fires from the crawl worker as well.
        $forget = function () {
            Cache::forget('judol_exclusions.active');
            Cache::forget('judol_rules.active');
        };

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * @return array<int, string>
     */
    public static function activeList(): array
    {
        return Cache::rememberForever(
            'judol_exclusions.active',
            fn () => static::active()->orderBy('phrase')->pluck('phrase')->all()
        );
    }
}
