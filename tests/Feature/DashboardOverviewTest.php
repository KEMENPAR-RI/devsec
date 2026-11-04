<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Services\Monitoring\DashboardOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function overview(): DashboardOverview
    {
        return app(DashboardOverview::class);
    }

    /** Pin storage + display zones so bucket edges are known. */
    protected function pinClock(string $at, string $displayZone = 'UTC'): void
    {
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        Setting::set('app_timezone', $displayZone);
        $this->travelTo(Carbon::parse($at, 'UTC'));
    }

    protected function check(Website $website, bool $up, ?int $ms, Carbon $at): WebsiteCheck
    {
        return $website->checks()->create([
            'is_up' => $up, 'status_code' => $up ? 200 : null, 'response_time_ms' => $ms, 'checked_at' => $at,
        ]);
    }

    protected function downWith(string $name, array $incident = [], array $site = []): Website
    {
        $website = Website::factory()->create(['name' => $name, 'last_status' => 'down'] + $site);
        Incident::create($incident + [
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now(),
        ]);

        return $website;
    }

    // --- Range -------------------------------------------------------------

    public function test_an_unknown_range_falls_back_to_24h(): void
    {
        $this->assertSame('24h', DashboardOverview::normalizeRange('5y'));
        $this->assertSame('24h', DashboardOverview::normalizeRange(''));
        $this->assertSame('24h', DashboardOverview::normalizeRange(null));
        $this->assertSame('7d', DashboardOverview::normalizeRange('7d'));

        $component = Livewire::actingAs(User::factory()->create())
            ->test(Dashboard::class)
            ->set('range', "1h' OR 1=1")
            ->assertSet('range', '24h')
            ->assertOk();

        $this->assertCount(24, $component->instance()->series);
    }

    public function test_each_range_has_its_own_bucket_count(): void
    {
        $expected = ['1h' => 12, '24h' => 24, '7d' => 28, '30d' => 30];

        foreach ($expected as $range => $count) {
            $this->assertCount($count, $this->overview()->performance($range)['series'], "range {$range}");
        }

        $component = Livewire::actingAs(User::factory()->create())->test(Dashboard::class);
        foreach ($expected as $range => $count) {
            $this->assertCount($count, $component->set('range', $range)->instance()->series, "component range {$range}");
        }
    }

    public function test_hourly_buckets_are_five_minutes_wide(): void
    {
        $this->pinClock('2026-10-18 13:32:00');
        $website = Website::factory()->create();
        $this->check($website, true, 100, now()->setTime(13, 31));
        $this->check($website, true, 300, now()->setTime(13, 26));
        // 61 minutes ago is outside the 12 buckets entirely.
        $this->check($website, false, null, now()->subMinutes(61));

        $result = $this->overview()->performance('1h');

        $this->assertSame(100, $result['series'][11]['ms']);
        $this->assertSame('13:30', $result['series'][11]['label']);
        $this->assertSame(300, $result['series'][10]['ms']);
        $this->assertSame('12:35', $result['series'][0]['label']);
        $this->assertSame(100.0, $result['uptime']);
    }

    public function test_daily_buckets_follow_the_display_timezone_not_utc(): void
    {
        // 03:00 UTC is 10:00 WIB on the 18th; WIB's day started at 17:00 UTC on the 17th.
        $this->pinClock('2026-10-18 03:00:00', 'Asia/Jakarta');
        $website = Website::factory()->create();
        $this->check($website, false, null, Carbon::parse('2026-10-17 18:00:00', 'UTC')); // 01:00 WIB, 18th
        $this->check($website, true, 100, Carbon::parse('2026-10-17 16:00:00', 'UTC'));   // 23:00 WIB, 17th

        $daily = $this->overview()->performance('30d')['series'];

        $this->assertStringStartsWith('18', $daily[29]['label']);
        $this->assertFalse($daily[29]['up']);
        $this->assertStringStartsWith('17', $daily[28]['label']);
        $this->assertTrue($daily[28]['up']);

        $hourly = $this->overview()->performance('24h')['series'];
        $this->assertSame('10:00', $hourly[23]['label']);
    }

    public function test_performance_is_cached_per_range_and_site_until_the_ttl_expires(): void
    {
        $this->pinClock('2026-10-18 13:30:00');
        $a = Website::factory()->create();
        $b = Website::factory()->create();
        $this->check($a, true, 100, now()->subMinute());
        $this->check($b, true, 900, now()->subMinute());

        $this->assertSame(100, $this->overview()->performance('24h', $a->id)['avg_ms']);
        $this->assertSame(900, $this->overview()->performance('24h', $b->id)['avg_ms']);
        $this->assertSame(500, $this->overview()->performance('24h')['avg_ms']);

        // Inside the 60s TTL the new check is not visible yet...
        $this->check($a, true, 300, now());
        $this->assertSame(100, $this->overview()->performance('24h', $a->id)['avg_ms']);
        // ...a different range is a different key...
        $this->assertSame(200, $this->overview()->performance('1h', $a->id)['avg_ms']);

        // ...past the TTL the stale value is served once while a deferred refresh runs...
        $this->travel(61)->seconds();
        $this->assertSame(100, $this->overview()->performance('24h', $a->id)['avg_ms']);
        app(DeferredCallbackCollection::class)->invoke();
        $this->assertSame(200, $this->overview()->performance('24h', $a->id)['avg_ms']);

        // ...and past 2x TTL (no stale copy left) it is recomputed inline.
        $this->check($b, true, 100, now());
        $this->travel(61)->seconds();
        $this->assertSame(500, $this->overview()->performance('24h', $b->id)['avg_ms']);
    }

    // --- Summary -----------------------------------------------------------

    public function test_the_summary_partition_always_sums_to_the_total(): void
    {
        Website::factory()->count(2)->create(['last_status' => 'up']);
        $this->downWith('Down');
        $this->downWith('Attack', ['meta' => ['likely_cause' => 'Dugaan DDoS', 'cause_is_overload' => true]]);
        $this->downWith('Offline', ['detected_at' => now()->subDays(3)]);
        $this->downWith('Internal', [], ['is_internal_only' => true]);
        Website::factory()->create(['last_status' => 'unknown']);

        $summary = Livewire::actingAs(User::factory()->create())->test(Dashboard::class)->instance()->summary;

        $this->assertSame(7, $summary['total']);
        $this->assertSame(2, $summary['online']);
        $this->assertSame(2, $summary['problem']);
        $this->assertSame(3, $summary['offline']);
        $this->assertSame(1, $summary['breakdown']['attack']);
        $this->assertSame(1, $summary['breakdown']['internal']);
        $this->assertSame($summary['total'], $summary['online'] + $summary['problem'] + $summary['offline']);
    }

    public function test_an_unrecognised_status_key_still_lands_in_the_partition(): void
    {
        $summary = $this->overview()->summary([1 => 'up', 2 => 'maintenance', 3 => 'something-new', 4 => 'attack']);

        $this->assertSame(4, $summary['total']);
        $this->assertSame(2, $summary['online']);
        $this->assertSame(1, $summary['problem']);
        $this->assertSame(1, $summary['offline']);
        $this->assertSame(1, $summary['breakdown']['unknown']);
    }

    // --- Live sites --------------------------------------------------------

    public function test_live_sites_list_down_sites_newest_first_then_the_rest(): void
    {
        Website::factory()->create(['name' => 'Alpha', 'last_status' => 'up', 'created_at' => now()->subDays(1)]);
        $this->downWith('Bravo', [], ['created_at' => now()->subDays(2)]);
        Website::factory()->create(['name' => 'Charlie', 'last_status' => 'unknown', 'created_at' => now()->subDays(3)]);
        $this->downWith('Delta', ['meta' => ['likely_cause' => 'Dugaan DDoS', 'cause_is_overload' => true]], ['created_at' => now()->subDays(4)]);
        Website::factory()->create(['name' => 'echo', 'last_status' => 'up', 'created_at' => now()]);

        $sites = $this->overview()->sites();
        $rows = $this->overview()->liveSites(
            $sites['websites'], $sites['statuses'], Website::loadLevels($sites['websites']),
        );

        $this->assertSame(['Bravo', 'Delta', 'echo', 'Alpha', 'Charlie'], array_column($rows, 'name'));
        $this->assertSame(['down', 'attack', 'up', 'up', 'unknown'], array_column($rows, 'status_key'));
    }

    public function test_live_sites_put_down_then_priority_then_the_rest(): void
    {
        Website::factory()->create(['name' => 'Xray', 'last_status' => 'up', 'created_at' => now()]);
        Website::factory()->create(['name' => 'Zulu', 'last_status' => 'up', 'is_priority' => true, 'created_at' => now()->subDays(2)]);
        $this->downWith('Bravo', [], ['created_at' => now()->subDays(3)]);

        $sites = $this->overview()->sites();
        $rows = $this->overview()->liveSites(
            $sites['websites'], $sites['statuses'], Website::loadLevels($sites['websites']),
        );

        $this->assertSame(['Bravo', 'Zulu', 'Xray'], array_column($rows, 'name'));
    }

    public function test_live_sites_down_group_ignores_priority(): void
    {
        Website::factory()->create(['name' => 'Alpha', 'last_status' => 'up', 'is_priority' => true, 'created_at' => now()->subDays(3)]);
        $this->downWith('Yankee', [], ['is_priority' => true, 'created_at' => now()->subDays(2)]);
        $this->downWith('Bravo', [], ['created_at' => now()->subDay()]);
        Website::factory()->create(['name' => 'Charlie', 'last_status' => 'up', 'created_at' => now()]);

        $sites = $this->overview()->sites();
        $rows = $this->overview()->liveSites(
            $sites['websites'], $sites['statuses'], Website::loadLevels($sites['websites']),
        );

        $this->assertSame(['Bravo', 'Yankee', 'Alpha', 'Charlie'], array_column($rows, 'name'));
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('is_priority', $row);
        }
    }

    public function test_live_sites_list_the_most_recently_down_site_first(): void
    {
        $this->downWith('Lama', ['detected_at' => now()->subHours(3)], ['created_at' => now()]);
        $this->downWith('Baru', ['detected_at' => now()->subMinutes(5)], ['created_at' => now()->subDays(5)]);

        $sites = $this->overview()->sites();
        $rows = $this->overview()->liveSites(
            $sites['websites'], $sites['statuses'], Website::loadLevels($sites['websites']),
        );

        $this->assertSame(['Baru', 'Lama'], array_column($rows, 'name'));
    }

    public function test_live_sites_do_not_lead_with_a_down_site_under_maintenance(): void
    {
        Website::factory()->create(['name' => 'Alpha', 'last_status' => 'up', 'is_priority' => true, 'created_at' => now()->subDays(3)]);
        $maintained = $this->downWith('Bravo', [], ['created_at' => now()]);
        MaintenanceWindow::create([
            'website_id' => $maintained->id,
            'reason' => 'Migrasi server',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $sites = $this->overview()->sites();
        $rows = $this->overview()->liveSites(
            $sites['websites'], $sites['statuses'], Website::loadLevels($sites['websites']),
        );

        $this->assertSame(['Alpha', 'Bravo'], array_column($rows, 'name'));
        $this->assertSame('maintenance', $rows[1]['status_key']);
    }

    public function test_live_sites_report_each_load_level(): void
    {
        $light = Website::factory()->create(['name' => 'Aaa light', 'last_status' => 'up']);
        $heavy = Website::factory()->create(['name' => 'Zzz heavy', 'last_status' => 'up']);
        $unchecked = Website::factory()->create(['name' => 'Bbb unchecked', 'last_status' => 'up']);
        $at = now()->subHours(2);
        foreach (range(1, 10) as $i) {
            $at = $at->copy()->addMinutes(5);
            $this->check($light, true, 300, $at);
            $this->check($heavy, true, 300, $at);
        }
        $this->check($light, true, 300, $at->copy()->addMinutes(5));
        $this->check($heavy, true, 900, $at->copy()->addMinutes(5));

        $sites = $this->overview()->sites();
        $rows = $this->overview()->liveSites(
            $sites['websites'], $sites['statuses'], Website::loadLevels($sites['websites']),
        );

        $levels = array_column($rows, 'load_level', 'name');

        $this->assertSame('heavy', $levels['Zzz heavy']);
        $this->assertSame('light', $levels['Aaa light']);
        $this->assertSame('unknown', $levels['Bbb unchecked']);
    }

    public function test_checks_older_than_the_ranked_window_give_no_history_and_an_unknown_load_level(): void
    {
        $stale = Website::factory()->create(['name' => 'Stale', 'last_status' => 'up']);
        $recent = Website::factory()->create(['name' => 'Recent', 'last_status' => 'up']);
        $outside = now()->subDays(WebsiteCheck::RANKED_WINDOW_DAYS)->subHour();
        foreach (range(1, 11) as $i) {
            $this->check($stale, true, 300, $outside->copy()->subMinutes($i * 5));
        }
        $this->check($recent, true, 300, now()->subDays(WebsiteCheck::RANKED_WINDOW_DAYS)->addHour());

        $sites = $this->overview()->sites();
        $rows = collect($this->overview()->liveSites(
            $sites['websites'], $sites['statuses'], Website::loadLevels($sites['websites']),
        ))->keyBy('name');

        $this->assertSame([], $rows['Stale']['history']);
        $this->assertSame('unknown', $rows['Stale']['load_level']);
        $this->assertSame([true], $rows['Recent']['history']);
        $this->assertSame('light', $rows['Recent']['load_level']);
    }

    public function test_live_sites_never_expose_the_numeric_primary_key(): void
    {
        $website = Website::factory()->create(['last_status' => 'up']);

        $component = Livewire::actingAs(User::factory()->create())->test(Dashboard::class);
        $row = $component->instance()->liveSites[0];

        $this->assertArrayNotHasKey('id', $row);
        $this->assertSame($website->uuid, $row['uuid']);
    }

    // --- Incidents ---------------------------------------------------------

    public function test_open_incidents_are_ordered_by_severity_then_newest_and_skip_resolved(): void
    {
        $website = Website::factory()->create();
        $make = fn (string $title, string $severity, int $hoursAgo, string $status = 'open') => Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => $severity,
            'title' => $title, 'status' => $status, 'detected_at' => now()->subHours($hoursAgo),
        ]);

        $make('info-new', 'info', 1);
        $make('warning', 'warning', 5);
        $make('critical-old', 'critical', 10);
        $make('critical-new', 'critical', 2);
        $make('critical-resolved', 'critical', 0, 'resolved');

        $incidents = $this->overview()->openIncidents();

        $this->assertSame(['critical-new', 'critical-old', 'warning', 'info-new'], $incidents->pluck('title')->all());
        $this->assertTrue($incidents->every(fn (Incident $i) => $i->relationLoaded('website')));
        $this->assertSame(4, $this->overview()->openIncidentsCount());
        $this->assertCount(2, $this->overview()->openIncidents(2));
    }

    public function test_the_incident_lists_do_not_query_per_row(): void
    {
        $website = Website::factory()->create();
        $count = function (string $method) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->overview()->{$method}()->each(fn (Incident $i) => $i->website?->name);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'one', 'status' => 'open', 'detected_at' => now(),
        ]);
        $openOne = $count('openIncidents');
        $recentOne = $count('recentIncidents');

        foreach (range(1, 5) as $i) {
            Incident::create([
                'website_id' => Website::factory()->create()->id, 'type' => 'downtime', 'severity' => 'warning',
                'title' => "more {$i}", 'status' => 'open', 'detected_at' => now(),
            ]);
        }

        $this->assertSame($openOne, $count('openIncidents'));
        $this->assertSame($recentOne, $count('recentIncidents'));
    }

    public function test_the_dashboard_query_count_does_not_grow_with_the_number_of_sites(): void
    {
        $user = User::factory()->create();
        $seed = function (int $n) {
            foreach (range(1, $n) as $i) {
                $website = $this->downWith("Site {$i} ".uniqid());
                $this->check($website, true, 200, now()->subMinutes(3));
                $this->check($website, false, null, now()->subMinute());
            }
        };
        $measure = function () use ($user) {
            Cache::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($user)->test(Dashboard::class)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $seed(1);
        $one = $measure();

        $seed(9);
        $ten = $measure();

        $this->assertLessThanOrEqual($one, $ten, "1 site: {$one} queries, 10 sites: {$ten} queries");
    }

    // --- Site picker -------------------------------------------------------

    public function test_an_unknown_uuid_selects_nothing(): void
    {
        Website::factory()->create();

        $component = Livewire::actingAs(User::factory()->create())
            ->test(Dashboard::class)
            ->call('selectWebsite', '00000000-0000-0000-0000-000000000000')
            ->assertSet('selectedWebsiteUuid', null)
            ->assertSee('Semua situs');

        $this->assertNull($component->instance()->selectedWebsite);

        $component->call('selectWebsite', '')->assertSet('selectedWebsiteUuid', null);
        $this->assertNull($component->instance()->selectedWebsite);
    }

    public function test_the_selected_uuid_cannot_be_set_directly_from_the_client(): void
    {
        $website = Website::factory()->create();
        $component = Livewire::actingAs(User::factory()->create())->test(Dashboard::class);

        try {
            $component->set('selectedWebsiteUuid', $website->uuid);
            $this->fail('A locked property accepted a client update.');
        } catch (CannotUpdateLockedPropertyException) {
            // expected
        }

        $component->assertSet('selectedWebsiteUuid', null);
        $component->call('selectWebsite', $website->uuid)->assertSet('selectedWebsiteUuid', $website->uuid);
    }

    // --- Model helpers -----------------------------------------------------

    public function test_history_for_returns_the_newest_n_results_oldest_first(): void
    {
        $a = Website::factory()->create();
        $b = Website::factory()->create();
        $silent = Website::factory()->create();

        $start = now()->subHours(3);
        foreach (range(0, 29) as $i) {
            // Pattern on the newest 24: index 6..29.
            $this->check($a, $i % 3 !== 0, 100, $start->copy()->addMinutes($i * 5));
        }
        $this->check($b, false, null, $start->copy()->addMinute());
        $this->check($b, true, 100, $start->copy()->addMinutes(2));

        $history = WebsiteCheck::historyFor([$a->id, $b->id, $silent->id], 24);

        $this->assertCount(24, $history[$a->id]);
        $this->assertSame(array_map(fn ($i) => $i % 3 !== 0, range(6, 29)), $history[$a->id]);
        $this->assertSame([false, true], $history[$b->id]);
        $this->assertArrayNotHasKey($silent->id, $history);

        $this->assertSame([], WebsiteCheck::historyFor([], 24));
        $this->assertSame([], WebsiteCheck::historyFor([$a->id], 0));
    }

    public function test_bulk_load_levels_agree_with_the_per_record_method_beyond_the_window(): void
    {
        // More than the 11-row window, so a wrong bound changes the baseline.
        $windowed = Website::factory()->create(['last_status' => 'up']);
        $at = now()->subHours(5);
        foreach (range(1, 15) as $i) {
            $this->check($windowed, true, 1000, $at = $at->copy()->addMinutes(5));
        }
        foreach (range(1, 10) as $i) {
            $this->check($windowed, true, 300, $at = $at->copy()->addMinutes(5));
        }
        $this->check($windowed, true, 900, $at->copy()->addMinutes(5));

        // Failed checks inside the window are not part of the baseline.
        $mixed = Website::factory()->create(['last_status' => 'up']);
        $at = now()->subHours(5);
        foreach (range(1, 20) as $i) {
            $this->check($mixed, $i % 2 === 0, $i % 2 === 0 ? 200 : null, $at = $at->copy()->addMinutes(5));
        }
        $this->check($mixed, true, 450, $at->copy()->addMinutes(5));

        $failedLast = Website::factory()->create(['last_status' => 'down']);
        $this->check($failedLast, true, 200, now()->subMinutes(10));
        $this->check($failedLast, false, null, now()->subMinute());

        $unchecked = Website::factory()->create();

        $levels = Website::loadLevels(Website::all());

        // Baseline = the 10 checks before the latest (300ms), so 900ms is 3x -> heavy.
        $this->assertSame('heavy', $levels[$windowed->id]['level']);
        $this->assertSame(900, $levels[$windowed->id]['response_time_ms']);
        foreach ([$windowed, $mixed, $failedLast, $unchecked] as $website) {
            $this->assertSame($website->loadLevel(), $levels[$website->id]['level'], "website {$website->id}");
        }
    }

    public function test_the_per_record_load_level_matches_the_bulk_one_and_bounds_its_baseline(): void
    {
        // Old repro: AVG() before LIMIT averaged all 25 prior checks (640ms), grading 900ms as light.
        $site = Website::factory()->create(['last_status' => 'up']);
        $at = now()->subHours(5);
        foreach (range(1, 15) as $i) {
            $this->check($site, true, 1000, $at = $at->copy()->addMinutes(5));
        }
        foreach (range(1, 10) as $i) {
            $this->check($site, true, 300, $at = $at->copy()->addMinutes(5));
        }
        $latest = $this->check($site, true, 900, $at->copy()->addMinutes(5));
        // Outside the ranked window: must not count toward either path.
        $this->check($site, true, 5, now()->subDays(WebsiteCheck::RANKED_WINDOW_DAYS)->subHour());

        $this->assertSame(300.0, $site->loadBaseline($latest->id));
        $this->assertSame('heavy', $site->loadLevel());
        $this->assertSame('heavy', Website::loadLevels([$site])[$site->id]['level']);

        // Same checked_at: the higher id is the latest in both paths.
        $tied = Website::factory()->create(['last_status' => 'up']);
        $at = now()->subHour();
        foreach (range(1, 10) as $i) {
            $this->check($tied, true, 300, $at = $at->copy()->addMinutes(5));
        }
        $tieAt = $at->copy()->addMinutes(5);
        $this->check($tied, true, 300, $tieAt);
        $this->check($tied, true, 1200, $tieAt);

        $this->assertSame('heavy', $tied->loadLevel());
        $this->assertSame(Website::loadLevels([$tied])[$tied->id]['level'], $tied->loadLevel());

        // Only checks older than the window: unknown in both paths.
        $stale = Website::factory()->create(['last_status' => 'up']);
        $this->check($stale, true, 300, now()->subDays(WebsiteCheck::RANKED_WINDOW_DAYS + 1));

        $this->assertSame('unknown', $stale->loadLevel());
        $this->assertNull($stale->loadBaseline());
        $this->assertSame('unknown', Website::loadLevels([$stale])[$stale->id]['level']);
    }

    // --- Real-time snapshot cache -----------------------------------------

    public function test_the_realtime_snapshot_is_shared_for_15_seconds_without_requerying_checks(): void
    {
        $user = User::factory()->create();
        $site = Website::factory()->create(['name' => 'First', 'last_status' => 'up']);
        $this->check($site, true, 200, now()->subMinute());

        Livewire::actingAs($user)->test(Dashboard::class)->assertSee('First');
        Website::factory()->create(['name' => 'Second', 'last_status' => 'up']);

        $this->travel(10)->seconds();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $component = Livewire::actingAs(User::factory()->create())->test(Dashboard::class)->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertFalse(
            $queries->contains(fn (string $sql) => str_contains($sql, 'website_checks')),
            "website_checks queried within the TTL:\n".$queries->implode("\n"),
        );
        $this->assertSame(1, $component->instance()->summary['total']);
        $component->assertDontSee('Second');

        // Beyond 2x TTL there is no stale copy left, so the next render rebuilds it.
        $this->travel(DashboardOverview::REALTIME_TTL * 2)->seconds();
        $fresh = Livewire::actingAs($user)->test(Dashboard::class)->assertSee('Second');
        $this->assertSame(2, $fresh->instance()->summary['total']);
    }

    public function test_the_shared_realtime_snapshot_holds_no_user_or_permission_data(): void
    {
        $website = Website::factory()->create(['name' => 'Shared Site', 'last_status' => 'up']);
        $user = User::factory()->create(['name' => 'Secret Viewer Name']);

        Livewire::actingAs($user)->test(Dashboard::class)->assertOk();

        $cached = Cache::get('dashboard:realtime');
        $this->assertIsArray($cached);
        $this->assertSame(['summary', 'live_sites', 'load_counts'], array_keys($cached));
        $this->assertSame(
            ['uuid', 'name', 'url', 'status_key', 'status_label', 'load_level', 'history', 'last_checked_at'],
            array_keys($cached['live_sites'][0]),
        );
        $this->assertSame($website->uuid, $cached['live_sites'][0]['uuid']);

        $serialized = serialize($cached);
        $this->assertStringNotContainsString($user->email, $serialized);
        $this->assertStringNotContainsString('Secret Viewer Name', $serialized);
        $this->assertStringNotContainsString(route('situs.show', $website->uuid), $serialized);
    }

    // --- Range payload hardening ------------------------------------------

    public function test_an_array_or_nested_range_payload_is_rejected_with_422(): void
    {
        $user = User::factory()->create();

        foreach ([['7d'], ['a' => 'b']] as $payload) {
            Livewire::actingAs($user)->test(Dashboard::class)
                ->set('range', $payload)
                ->assertStatus(422);
        }

        Livewire::actingAs($user)->test(Dashboard::class)
            ->set('range.foo', '7d')
            ->assertStatus(422);
    }

    public function test_a_non_string_scalar_range_falls_back_to_24h(): void
    {
        $user = User::factory()->create();

        foreach ([5, 1.5, true, false] as $payload) {
            Livewire::actingAs($user)->test(Dashboard::class)
                ->set('range', '7d')
                ->set('range', $payload)
                ->assertOk()
                ->assertSet('range', '24h');
        }

        Livewire::actingAs($user)->test(Dashboard::class)->set('range', '30d')->assertSet('range', '30d');
    }

    // --- Incident visibility ----------------------------------------------

    public function test_incident_panels_and_kpi_are_hidden_without_insiden_view(): void
    {
        $website = Website::factory()->create();
        Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'Hidden incident title', 'status' => 'open', 'detected_at' => now(),
        ]);

        $without = Role::factory()->withPermissions(['dashboard.view'])->create();
        Livewire::actingAs(User::factory()->create(['role_id' => $without->id]))
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Judol baru')
            ->assertDontSee('Insiden terbuka')
            ->assertDontSee('Insiden Belum Selesai')
            ->assertDontSee('Insiden Terbaru')
            ->assertDontSee('Hidden incident title');

        $with = Role::factory()->withPermissions(['dashboard.view', 'insiden.view'])->create();
        Livewire::actingAs(User::factory()->create(['role_id' => $with->id]))
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Insiden terbuka')
            ->assertSee('Insiden Belum Selesai')
            ->assertSee('Insiden Terbaru')
            ->assertSee('Hidden incident title')
            ->assertSee(route('insiden', ['status' => 'open']), escape: false);
    }

    // --- Authorization -----------------------------------------------------

    public function test_a_user_without_dashboard_view_cannot_mount_the_dashboard(): void
    {
        Livewire::actingAs(User::factory()->withoutRole()->create())
            ->test(Dashboard::class)
            ->assertForbidden();

        $role = Role::factory()->withPermissions(['situs.view'])->create();
        $user = User::factory()->create(['role_id' => $role->id]);

        Livewire::actingAs($user)->test(Dashboard::class)->assertForbidden();
        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    }

    public function test_a_banned_user_cannot_mount_the_dashboard(): void
    {
        $user = User::factory()->create(['banned_at' => now()]);

        Livewire::actingAs($user)->test(Dashboard::class)->assertForbidden();
    }

    public function test_a_view_only_role_does_not_see_management_links(): void
    {
        Website::factory()->create();
        $role = Role::factory()->withPermissions(['dashboard.view'])->create();

        $component = Livewire::actingAs(User::factory()->create(['role_id' => $role->id]))
            ->test(Dashboard::class)
            ->assertOk()
            ->assertDontSee('Tambah Situs')
            ->assertDontSee('Lihat semua situs')
            ->assertDontSee(route('insiden'), escape: false)
            ->assertDontSee(route('deteksi-judol'), escape: false);

        $this->assertFalse($component->instance()->canManage);
        $this->assertFalse($component->instance()->canViewSites);
    }

    public function test_live_sites_link_to_the_site_page_only_with_situs_view(): void
    {
        $website = Website::factory()->create(['name' => 'Visible Site', 'last_status' => 'up']);
        $showUrl = route('situs.show', $website->uuid);

        $viewOnly = Role::factory()->withPermissions(['dashboard.view'])->create();
        Livewire::actingAs(User::factory()->create(['role_id' => $viewOnly->id]))
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Visible Site')
            ->assertDontSee($showUrl, escape: false);

        $canView = Role::factory()->withPermissions(['dashboard.view', 'situs.view'])->create();
        Livewire::actingAs(User::factory()->create(['role_id' => $canView->id]))
            ->test(Dashboard::class)
            ->assertSee($showUrl, escape: false);
    }

    public function test_a_role_that_can_create_sites_sees_the_add_button(): void
    {
        $role = Role::factory()->withPermissions(['dashboard.view', 'situs.view', 'situs.create'])->create();

        Livewire::actingAs(User::factory()->create(['role_id' => $role->id]))
            ->test(Dashboard::class)
            ->assertSee('Tambah Situs');
    }

    // --- Markup ------------------------------------------------------------

    public function test_the_dashboard_polls_every_15_seconds_only_while_visible(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Dashboard::class)
            ->assertSeeHtml('wire:poll.15s.visible');
    }

    public function test_the_header_date_is_rendered_in_the_display_timezone(): void
    {
        // 20:00 UTC on Sunday the 18th is 03:00 WIB on Monday the 19th.
        $this->pinClock('2026-10-18 20:00:00', 'Asia/Jakarta');
        $local = Carbon::parse('2026-10-19 03:00:00', 'Asia/Jakarta');
        $utc = Carbon::parse('2026-10-18 20:00:00', 'UTC');

        Livewire::actingAs(User::factory()->create())
            ->test(Dashboard::class)
            ->assertSee($local->translatedFormat('l, d F Y'))
            ->assertSee('03:00:00')
            ->assertDontSee($utc->translatedFormat('l, d F Y'));
    }

    public function test_site_and_incident_names_are_escaped(): void
    {
        $website = Website::factory()->create(['name' => '<script>alert(1)</script>', 'last_status' => 'up']);
        Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => '<img src=x onerror=alert(2)>', 'status' => 'open', 'detected_at' => now(),
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test(Dashboard::class)
            ->call('selectWebsite', $website->uuid)
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertDontSeeHtml('<img src=x onerror=alert(2)>')
            ->assertSee('<script>alert(1)</script>');
    }
}
