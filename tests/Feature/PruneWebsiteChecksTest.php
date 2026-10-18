<?php

namespace Tests\Feature;

use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Models\WebsiteCheckDaily;
use App\Services\Reporting\MonthlyReport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * websites:prune-checks: daily rollup first, then a chunked delete of raw
 * checks past retention, without changing what the monthly report says.
 */
class PruneWebsiteChecksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-18 03:15:00'));
    }

    public function test_raw_checks_past_retention_are_rolled_up_then_deleted(): void
    {
        $website = Website::factory()->create();
        // Cutoff with 90 days is 2026-07-20 00:00.
        $this->checkAt($website, '2026-07-19 10:00:00', true, 100);
        $this->checkAt($website, '2026-07-19 23:59:59', false, null);
        $this->checkAt($website, '2026-07-20 00:00:00', true, 300);

        $this->artisan('websites:prune-checks')->assertSuccessful();

        $this->assertSame(0, WebsiteCheck::where('checked_at', '<', '2026-07-20 00:00:00')->count());
        $this->assertSame(1, WebsiteCheck::count(), 'the cutoff day itself is kept');

        $day = $this->daily($website, '2026-07-19');
        $this->assertSame(2, $day->total_checks);
        $this->assertSame(1, $day->up_checks);
        $this->assertSame(100, $day->avg_response_ms, 'untimed checks do not drag the average down');
    }

    public function test_today_is_never_rolled_up(): void
    {
        $website = Website::factory()->create();
        $this->checkAt($website, '2026-10-17 12:00:00', true, 100);
        $this->checkAt($website, '2026-10-18 01:00:00', true, 100);

        $this->artisan('websites:prune-checks')->assertSuccessful();

        $this->assertNotNull($this->daily($website, '2026-10-17'));
        $this->assertNull($this->daily($website, '2026-10-18'));
        $this->assertSame(2, WebsiteCheck::count());
    }

    public function test_the_rollup_is_idempotent(): void
    {
        $website = Website::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->checkAt($website, "2026-10-1{$i} 08:00:00", $i % 2 === 0, 100 * $i);
        }

        $this->artisan('websites:prune-checks')->assertSuccessful();
        $first = $this->snapshot();

        $this->artisan('websites:prune-checks')->assertSuccessful();
        $this->artisan('websites:prune-checks')->assertSuccessful();

        $this->assertSame($first, $this->snapshot());
        $this->assertCount(5, $first);
    }

    public function test_the_last_rolled_day_is_refreshed_on_the_next_run(): void
    {
        $website = Website::factory()->create();
        $this->checkAt($website, '2026-10-16 08:00:00', true, 100);

        $this->artisan('websites:prune-checks')->assertSuccessful();

        // A day later, a check for the 17th (yesterday) arrives and the 17th becomes complete.
        $this->travelTo(Carbon::parse('2026-10-19 03:15:00'));
        $this->checkAt($website, '2026-10-17 08:00:00', false, 400);
        $this->artisan('websites:prune-checks')->assertSuccessful();

        $this->assertSame(1, $this->daily($website, '2026-10-17')->total_checks);
        $this->assertSame(1, $this->daily($website, '2026-10-16')->total_checks);
    }

    public function test_every_deleted_day_has_a_rollup_row(): void
    {
        $a = Website::factory()->create();
        $b = Website::factory()->create();
        $expected = [];

        foreach (range(1, 40) as $back) {
            $day = Carbon::parse('2026-10-18')->subDays($back);
            $this->checkAt($a, $day->copy()->addHours(3)->toDateTimeString(), true, 100);
            $expected[$a->id][$day->toDateString()] = 1;

            if ($back % 3 === 0) {
                $this->checkAt($b, $day->copy()->addHours(5)->toDateTimeString(), false, null);
                $expected[$b->id][$day->toDateString()] = 1;
            }
        }

        $this->artisan('websites:prune-checks', ['--days' => 7])->assertSuccessful();

        foreach ($expected as $websiteId => $days) {
            foreach ($days as $date => $count) {
                $row = WebsiteCheckDaily::where('website_id', $websiteId)->whereDate('day', $date)->first();
                $this->assertNotNull($row, "rollup for site {$websiteId} on {$date}");
                $this->assertSame($count, $row->total_checks);
            }
        }

        $this->assertSame(0, WebsiteCheck::where('checked_at', '<', '2026-10-11 00:00:00')->count());
        $this->assertSame(7, WebsiteCheck::where('website_id', $a->id)->count());
    }

    public function test_deletion_is_chunked_but_complete(): void
    {
        $website = Website::factory()->create();
        $rows = [];

        foreach (range(1, 2500) as $i) {
            $rows[] = [
                // Bulk insert bypasses HasUuid, so the uuid is set here.
                'uuid' => (string) Str::uuid(), 'website_id' => $website->id, 'is_up' => true, 'response_time_ms' => 100,
                'checked_at' => Carbon::parse('2026-01-10 00:00:00')->addSeconds($i * 30)->toDateTimeString(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            WebsiteCheck::insert($chunk);
        }

        $this->artisan('websites:prune-checks')
            ->expectsOutputToContain('2500 hasil cek')
            ->assertSuccessful();

        $this->assertSame(0, WebsiteCheck::count());
        $this->assertSame(2500, (int) WebsiteCheckDaily::sum('total_checks'));
    }

    public function test_the_days_option_overrides_the_config(): void
    {
        config(['monitoring.checks_retention_days' => 90]);
        $website = Website::factory()->create();
        $this->checkAt($website, '2026-10-10 08:00:00', true, 100);

        $this->artisan('websites:prune-checks', ['--days' => 5])->assertSuccessful();

        $this->assertSame(0, WebsiteCheck::count());
        $this->assertNotNull($this->daily($website, '2026-10-10'));
    }

    public function test_the_config_retention_is_used_by_default(): void
    {
        config(['monitoring.checks_retention_days' => 3]);
        $website = Website::factory()->create();
        $this->checkAt($website, '2026-10-14 08:00:00', true, 100);
        $this->checkAt($website, '2026-10-15 08:00:00', true, 100);

        $this->artisan('websites:prune-checks')->assertSuccessful();

        $this->assertSame(1, WebsiteCheck::count());
    }

    public function test_a_negative_retention_is_refused(): void
    {
        $website = Website::factory()->create();
        $this->checkAt($website, '2026-10-17 08:00:00', true, 100);

        $this->artisan('websites:prune-checks', ['--days' => -1])->assertFailed();
        $this->artisan('websites:prune-checks', ['--days' => 'abc'])->assertFailed();

        $this->assertSame(1, WebsiteCheck::count());
    }

    public function test_a_zero_retention_is_refused_not_silently_replaced(): void
    {
        config(['monitoring.checks_retention_days' => 1]);

        // "0" must not quietly fall back to the configured window.
        $this->artisan('websites:prune-checks', ['--days' => 0])->assertFailed();
    }

    /** subDays(PHP_INT_MAX) would wrap to tomorrow, so the cutoff would land past today. */
    public function test_an_absurdly_large_retention_never_deletes_todays_unrolled_checks(): void
    {
        $website = Website::factory()->create();
        $this->checkAt($website, '2026-10-18 01:00:00', true, 100);

        $this->artisan('websites:prune-checks', ['--days' => (string) PHP_INT_MAX])->assertFailed();

        config(['monitoring.checks_retention_days' => PHP_INT_MAX]);
        $this->artisan('websites:prune-checks')->assertFailed();

        $this->assertSame(1, WebsiteCheck::count(), 'today is never rolled up, so deleting it loses it for good');
    }

    public function test_retention_is_capped_at_3650_days(): void
    {
        $this->artisan('websites:prune-checks', ['--days' => 3650])->assertSuccessful();
        $this->artisan('websites:prune-checks', ['--days' => 3651])->assertFailed();

        config(['monitoring.checks_retention_days' => 3651]);
        $this->artisan('websites:prune-checks')->assertFailed();
    }

    public function test_an_empty_table_is_a_no_op(): void
    {
        $this->artisan('websites:prune-checks')
            ->expectsOutputToContain('0 ringkasan')
            ->assertSuccessful();

        $this->assertSame(0, WebsiteCheckDaily::count());
    }

    public function test_it_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'websites:prune-checks'));

        $this->assertNotNull($event);
        $this->assertSame('15 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    // --- MonthlyReport consistency --------------------------------------------

    public function test_a_month_straddling_the_cutoff_reports_the_same_before_and_after(): void
    {
        $a = Website::factory()->create(['name' => 'A']);
        $b = Website::factory()->create(['name' => 'B']);

        foreach (range(1, 31) as $d) {
            $day = Carbon::parse(sprintf('2026-07-%02d', $d));
            $this->checkAt($a, $day->copy()->addHour()->toDateTimeString(), true, 100);
            $this->checkAt($a, $day->copy()->setTime(23, 59, 59)->toDateTimeString(), $d % 2 === 0, $d % 2 === 0 ? 300 : null);
            $this->checkAt($b, $day->copy()->addHours(12)->toDateTimeString(), $d % 5 !== 0, 200);
        }
        // Edges of the neighbouring months must stay out.
        $this->checkAt($a, '2026-06-30 23:59:59', false, 900);
        $this->checkAt($a, '2026-08-01 00:00:00', false, 900);

        $before = $this->reportFor('2026-07');

        $this->artisan('websites:prune-checks')->assertSuccessful();
        $this->assertSame(0, WebsiteCheck::where('checked_at', '<', '2026-07-20 00:00:00')->count());
        $this->assertGreaterThan(0, WebsiteCheck::where('checked_at', '<', '2026-08-01 00:00:00')->count());

        $this->assertSame($before, $this->reportFor('2026-07'));
        $this->assertSame(62, $before['A']['total_cek']);
    }

    public function test_a_fully_pruned_month_still_reports_from_the_rollup(): void
    {
        $a = Website::factory()->create(['name' => 'A']);
        $this->checkAt($a, '2026-05-10 08:00:00', true, 100);
        $this->checkAt($a, '2026-05-11 08:00:00', false, 300);
        $this->checkAt($a, '2026-10-17 08:00:00', true, 100);

        $before = $this->reportFor('2026-05');
        $this->artisan('websites:prune-checks')->assertSuccessful();

        $this->assertSame(1, WebsiteCheck::count());
        $this->assertSame($before, $this->reportFor('2026-05'));
        $this->assertSame(50.0, $before['A']['uptime']);
    }

    public function test_rounded_daily_averages_do_not_shift_the_monthly_average(): void
    {
        $a = Website::factory()->create(['name' => 'A']);

        foreach (range(1, 31) as $d) {
            $day = sprintf('2026-07-%02d', $d);
            // Rolled days average x.5 ms; kept days are whole.
            $this->checkAt($a, "{$day} 08:00:00", true, 100);
            $this->checkAt($a, "{$day} 09:00:00", true, $d < 20 ? 101 : 100);
        }

        $before = $this->reportFor('2026-07');
        $this->artisan('websites:prune-checks')->assertSuccessful();

        $this->assertSame($before['A']['rata_respons_ms'], $this->reportFor('2026-07')['A']['rata_respons_ms']);
    }

    // --- Helpers -----------------------------------------------------------------

    protected function checkAt(Website $website, string $at, bool $up, ?int $ms): void
    {
        WebsiteCheck::create([
            'website_id' => $website->id,
            'is_up' => $up,
            'status_code' => $up ? 200 : 500,
            'response_time_ms' => $ms,
            'checked_at' => Carbon::parse($at),
        ]);
    }

    protected function daily(Website $website, string $day): ?WebsiteCheckDaily
    {
        return WebsiteCheckDaily::where('website_id', $website->id)->whereDate('day', $day)->first();
    }

    protected function snapshot(): array
    {
        return WebsiteCheckDaily::orderBy('website_id')->orderBy('day')
            ->get(['website_id', 'day', 'total_checks', 'up_checks', 'avg_response_ms'])
            ->map(fn ($row) => [$row->website_id, $row->day->toDateString(), $row->total_checks, $row->up_checks, $row->avg_response_ms])
            ->all();
    }

    /** @return array<string, array{uptime: ?float, total_cek: int, rata_respons_ms: ?int}> */
    protected function reportFor(string $month): array
    {
        $start = Carbon::parse($month.'-01');

        return app(MonthlyReport::class)->build($start, $start->copy()->endOfMonth())['rows']
            ->keyBy('nama')
            ->map(fn ($row) => ['uptime' => $row['uptime'], 'total_cek' => $row['total_cek'], 'rata_respons_ms' => $row['rata_respons_ms']])
            ->all();
    }
}
