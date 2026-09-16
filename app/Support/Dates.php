<?php

namespace App\Support;

use App\Services\AppSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Renders timestamps in the timezone chosen on Pengaturan > Konfigurasi Aplikasi,
 * labelled the way Indonesian readers expect: WIB, WITA, WIT.
 *
 * The label matters more than it looks. Everything in this app is a time an
 * incident happened, and "06:12" alone is ambiguous the moment anyone from
 * another island reads the same screen or the same email - so the zone is spelled
 * out rather than left implied.
 *
 * Rows are stored in UTC (Laravel's default) and converted here at display time,
 * which is why changing the timezone setting re-labels history correctly instead
 * of shifting it.
 */
class Dates
{
    /** Timezone identifier => the abbreviation people actually use for it. */
    public const ABBREVIATIONS = [
        'Asia/Jakarta' => 'WIB',
        'Asia/Pontianak' => 'WIB',
        'Asia/Makassar' => 'WITA',
        'Asia/Jayapura' => 'WIT',
    ];

    /** Long form, e.g. "02 September 2026, 15:32 WIB". */
    public const FULL = 'd F Y, H:i';

    /** Compact form for dense lists, e.g. "02 Sep 2026, 15:32 WIB". */
    public const SHORT = 'd M Y, H:i';

    public static function timezone(): string
    {
        return app(AppSettings::class)->timezone();
    }

    /**
     * WIB / WITA / WIT for the Indonesian zones; for anything else the zone's own
     * abbreviation (UTC, GMT+8, ...) rather than a made-up one.
     */
    public static function abbreviation(?string $timezone = null): string
    {
        $timezone ??= static::timezone();

        return static::ABBREVIATIONS[$timezone]
            ?? Carbon::now($timezone)->format('T');
    }

    /**
     * A timestamp in the app's timezone, with its zone label.
     *
     * Null in, empty string out, so a view can call it on a nullable column
     * without an isset() dance around every use.
     */
    public static function display(CarbonInterface|string|null $value, string $format = self::SHORT): string
    {
        if (blank($value)) {
            return '';
        }

        $timezone = static::timezone();

        return Carbon::parse($value)
            ->timezone($timezone)
            ->translatedFormat($format).' '.static::abbreviation($timezone);
    }

    /** The same instant with no zone label - for a column that already has one. */
    public static function plain(CarbonInterface|string|null $value, string $format = self::SHORT): string
    {
        return blank($value)
            ? ''
            : Carbon::parse($value)->timezone(static::timezone())->translatedFormat($format);
    }

    /**
     * A Carbon instance as the value a <input type="datetime-local"> expects,
     * in the app's timezone - the zone the operator is reading the page in.
     */
    public static function toLocalInput(CarbonInterface|string|null $value): string
    {
        return blank($value)
            ? ''
            : Carbon::parse($value)->timezone(static::timezone())->format('Y-m-d\TH:i');
    }

    /**
     * The reverse: a datetime-local value back into a Carbon.
     *
     * The browser sends wall-clock with no zone attached, so it has to be read as
     * app-timezone and not as UTC - otherwise a window scheduled for 02:00 WIB
     * would start at 09:00 WIB and cover nothing anyone meant.
     */
    public static function fromLocalInput(string $value): Carbon
    {
        return Carbon::parse($value, static::timezone());
    }

    /**
     * "3 menit yang lalu" alongside the exact time, e.g. for a tooltip - relative
     * time is easy to scan, but an incident report needs the real clock reading.
     */
    public static function relative(CarbonInterface|string|null $value): string
    {
        return blank($value)
            ? ''
            : Carbon::parse($value)->timezone(static::timezone())->diffForHumans();
    }
}
