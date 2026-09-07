<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsMemoTest extends TestCase
{
    use RefreshDatabase;

    /** The whole point of the refactor: repeated reads must not re-query. */
    public function test_a_setting_is_read_from_the_database_only_once_per_request(): void
    {
        Setting::set('app_timezone', 'Asia/Makassar');
        Setting::flush();

        DB::enableQueryLog();

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame('Asia/Makassar', app(AppSettings::class)->timezone());
        }

        $queries = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'settings'))
            ->count();

        $this->assertSame(1, $queries, 'ten reads must cost one query, not ten');
    }

    /** The risk the memo introduces: a saved value must still take effect at once. */
    public function test_saving_a_setting_is_visible_immediately_in_the_same_request(): void
    {
        $settings = app(AppSettings::class);

        Setting::set('app_timezone', 'Asia/Jakarta');
        $this->assertSame('Asia/Jakarta', $settings->timezone());

        Setting::set('app_timezone', 'Asia/Jayapura');
        $this->assertSame('Asia/Jayapura', $settings->timezone(), 'the memo must not outlive the write');

        Setting::forget('app_timezone');
        $this->assertSame(config('app.timezone'), $settings->timezone(), 'and a delete falls back');
    }

    /** A key with no row must memoize too, rather than being re-asked each call. */
    public function test_a_missing_setting_is_not_re_queried(): void
    {
        DB::enableQueryLog();

        for ($i = 0; $i < 5; $i++) {
            $this->assertNull(Setting::get('never_saved_key'));
        }

        $queries = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'settings'))
            ->count();

        $this->assertSame(1, $queries, 'a miss is still an answer worth remembering');
    }

    /** Registering them as singletons is what makes the per-instance memo pay off. */
    public function test_the_settings_services_are_shared_instances(): void
    {
        $this->assertSame(app(AppSettings::class), app(AppSettings::class));
    }
}
