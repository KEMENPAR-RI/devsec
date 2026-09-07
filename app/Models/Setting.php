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
 * Reads are served by two layers, because these keys are read constantly and
 * written almost never:
 *
 *  1. A per-request memo (self::$memo). Settings are read many times within a
 *     single request - Dates::timezone() alone runs twice per rendered timestamp,
 *     and a dashboard renders dozens - and without this each of those is a
 *     round-trip to the cache store.
 *  2. The cache, forever, so the first read of a key in a new request is the only
 *     one that can reach the database.
 *
 * Both are dropped whenever a row changes, so a saved setting takes effect
 * immediately in the same request rather than one refresh later.
 */
class Setting extends Model
{
    use HasUuid;

    protected $fillable = ['key', 'value'];

    /**
     * Values already resolved during this request, keyed by setting key. A key
     * that is absent from the table memoizes as null, so a miss is not re-asked
     * either.
     *
     * @var array<string, mixed>
     */
    protected static array $memo = [];

    protected static function booted(): void
    {
        // Covers writes that bypass set()/forget() - a save through the model
        // itself, a factory, a seeder - so no path can leave a stale value behind.
        static::saved(fn (self $setting) => static::flush($setting->key));
        static::deleted(fn (self $setting) => static::flush($setting->key));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        // array_key_exists, not ??/isset: a key with no row memoizes as null, and
        // isset() would treat that as a miss and re-resolve it on every call.
        if (! array_key_exists($key, static::$memo)) {
            static::$memo[$key] = Cache::rememberForever(
                static::cacheKey($key),
                fn () => static::query()->where('key', $key)->value('value')
            );
        }

        return static::$memo[$key] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        static::flush($key);
    }

    public static function forget(string $key): void
    {
        static::where('key', $key)->delete();
        static::flush($key);
    }

    /**
     * Drops both layers for one key, or for everything when no key is given.
     *
     * The no-argument form exists for the test suite, which rolls the database
     * back between tests while this class's memo - being static - would otherwise
     * survive into the next one.
     */
    public static function flush(?string $key = null): void
    {
        if ($key === null) {
            foreach (array_keys(static::$memo) as $memoized) {
                Cache::forget(static::cacheKey($memoized));
            }

            static::$memo = [];

            return;
        }

        unset(static::$memo[$key]);
        Cache::forget(static::cacheKey($key));
    }

    protected static function cacheKey(string $key): string
    {
        return "setting.{$key}";
    }
}
