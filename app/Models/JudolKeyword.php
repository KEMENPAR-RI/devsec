<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A single judol spam keyword, manageable from the Deteksi Judol page instead of
 * a static config/judol.php array - so a new spam pattern can be added without a
 * deploy. See JudolScanner::keywords() for how these are read (cached, since
 * every crawled page checks against the full active list).
 */
class JudolKeyword extends Model
{
    use Auditable, HasUuid;

    protected $fillable = ['keyword', 'is_active'];

    public function auditEventPrefix(): string
    {
        return 'kata-kunci';
    }

    public function auditSubject(): string
    {
        return (string) $this->keyword;
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
        static::saved(fn () => Cache::forget('judol_keywords.active'));
        static::deleted(fn () => Cache::forget('judol_keywords.active'));
    }

    /**
     * @return array<int, string>
     */
    public static function activeList(): array
    {
        return Cache::rememberForever(
            'judol_keywords.active',
            fn () => static::active()->orderBy('keyword')->pluck('keyword')->all()
        );
    }
}
