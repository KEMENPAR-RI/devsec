<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Generic key-value store for runtime-editable config (Pengaturan page) - things
 * that used to require an .env change + restart, like the Anthropic API key and
 * the AI-classifier toggle. Values that came from config/*.php stay as the
 * fallback default whenever no override has been saved here - see
 * App\Services\Monitoring\JudolAiSettings for the read pattern.
 *
 * Cached forever per key and busted on write, since these are read on every
 * scanned page but change rarely (only when someone edits Pengaturan).
 */
class Setting extends Model
{
    use HasUuid;

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::rememberForever(
            static::cacheKey($key),
            fn () => static::query()->where('key', $key)->value('value')
        );

        return $value ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(static::cacheKey($key));
    }

    public static function forget(string $key): void
    {
        static::where('key', $key)->delete();
        Cache::forget(static::cacheKey($key));
    }

    protected static function cacheKey(string $key): string
    {
        return "setting.{$key}";
    }
}
