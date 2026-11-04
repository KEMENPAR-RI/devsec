<?php

namespace Tests\Feature;

use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Tv\Display;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\AppSettings;
use App\Services\Monitoring\TvBoard;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

class TvDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function siteWithCheck(string $name, string $status, ?string $error = null): Website
    {
        $website = Website::factory()->create(['name' => $name, 'last_status' => $status]);
        $website->checks()->create([
            'is_up' => $status === 'up',
            'status_code' => $status === 'up' ? 200 : null,
            'response_time_ms' => $status === 'up' ? 120 : null,
            'error_message' => $error,
            'checked_at' => now(),
        ]);

        return $website;
    }

    public function test_wallboard_is_public_without_login_or_token(): void
    {
        $this->assertGuest();

        $this->get(route('tv'))->assertOk();
    }

    public function test_wallboard_links_the_uploaded_favicon(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/favicon.png', 'png');
        Setting::set('app_favicon_path', 'branding/favicon.png');

        $url = app(AppSettings::class)->faviconUrl();

        $this->get(route('tv'))->assertOk()->assertSee('<link rel="icon" href="'.$url.'">', false);
    }

    public function test_cards_show_site_names_and_status_colours(): void
    {
        $this->siteWithCheck('Portal Dinas Down', 'down', 'cURL error 7');
        $this->siteWithCheck('Portal Dinas Up', 'up');

        $this->get(route('tv'))
            ->assertOk()
            ->assertSee('Portal Dinas Down')
            ->assertSee('Portal Dinas Up')
            ->assertSee('from-red-500/15 to-transparent to-60% border-red-400/50 text-red-300', false)
            ->assertSee('from-emerald-500/15 to-transparent to-60% border-emerald-400/50 text-emerald-300', false);
    }

    public function test_board_data_hides_inactive_sites_and_probe_details(): void
    {
        $this->siteWithCheck('Aktif', 'up')->update(['url' => 'https://aktif.example/path']);
        Website::factory()->create(['name' => 'Nonaktif', 'is_active' => false, 'last_status' => 'up']);
        Website::factory()->create(['name' => 'Internal', 'is_internal_only' => true]);

        $cards = collect(app(TvBoard::class)->build()['kartu'])->keyBy('name');

        $this->assertFalse($cards->has('Nonaktif'));
        $this->assertSame('aktif.example', $cards['Aktif']['host']);
        $this->assertNull($cards['Internal']['host']);
        foreach (['url', 'status_code', 'response_time_ms', 'is_up', 'last_checked_at'] as $field) {
            $this->assertArrayNotHasKey($field, $cards['Aktif']);
        }
    }

    public function test_suspected_attack_is_shown_publicly_as_down(): void
    {
        $site = $this->siteWithCheck('Diserang', 'down');
        Incident::create([
            'website_id' => $site->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'Diserang tidak dapat diakses', 'status' => 'open', 'detected_at' => now(),
            'meta' => ['likely_cause' => 'Dugaan DDoS', 'cause_is_overload' => true],
        ]);

        $board = app(TvBoard::class)->build();

        $this->assertSame('down', $board['kartu'][0]['status']);
        $this->assertSame(Website::statusConfig('down')['label'], $board['kartu'][0]['status_label']);
        $this->assertSame('down', $board['latest_event']['status']);
    }

    public function test_down_site_without_open_incident_is_shown_but_never_the_latest_event(): void
    {
        $this->siteWithCheck('Down Tanpa Insiden', 'down');

        $board = app(TvBoard::class)->build();

        $this->assertSame('Down Tanpa Insiden', $board['kartu'][0]['name']);
        $this->assertNull($board['latest_event']);
        $this->assertNull($board['latest_event_at']);

        // Newer check-time fallback must not outrank an older open incident.
        $withIncident = $this->siteWithCheck('Down Dengan Insiden', 'down');
        $detectedAt = now()->subHour();
        Incident::create([
            'website_id' => $withIncident->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'Down Dengan Insiden tidak dapat diakses', 'status' => 'open',
            'detected_at' => $detectedAt,
        ]);
        Cache::flush();

        $board = app(TvBoard::class)->build();

        $this->assertSame('Down Dengan Insiden', $board['latest_event']['name']);
        $this->assertSame($detectedAt->timestamp, $board['latest_event_at']);
    }

    public function test_error_message_is_never_rendered(): void
    {
        $this->siteWithCheck('Internal Host', 'down', 'Failed to connect to 10.20.30.40 port 443');

        $this->get(route('tv'))
            ->assertOk()
            ->assertSee('Internal Host')
            ->assertDontSee('10.20.30.40')
            ->assertDontSee('Failed to connect');
    }

    public function test_site_name_is_escaped(): void
    {
        Website::factory()->create(['name' => '<script>alert(1)</script>', 'last_status' => 'up']);

        $this->get(route('tv'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_latest_event_payload_in_alpine_attribute_is_escaped(): void
    {
        $site = $this->siteWithCheck('"><img src=x onerror=alert(1)>', 'down');
        Incident::create([
            'website_id' => $site->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now(),
        ]);

        $this->get(route('tv'))
            ->assertOk()
            ->assertDontSee('"><img src=x onerror=alert(1)>', false);
    }

    public function test_query_count_does_not_grow_with_number_of_sites(): void
    {
        $count = function (): int {
            Cache::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('tv'))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->siteWithCheck('Down 1', 'down');
        $this->siteWithCheck('Up 1', 'up');
        // Warm-up: settings are memoized in-process after the first request.
        $count();
        $few = $count();

        foreach (range(2, 20) as $i) {
            $this->siteWithCheck("Down {$i}", 'down');
            $this->siteWithCheck("Up {$i}", 'up');
        }

        $this->assertSame($few, $count());
    }

    public function test_up_site_after_resolved_downtime_shows_last_down_and_up_since(): void
    {
        $site = $this->siteWithCheck('Pulih', 'up');
        $detectedAt = now()->subHours(3)->startOfSecond();
        $resolvedAt = now()->subHours(2)->startOfSecond();
        Incident::create([
            'website_id' => $site->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'Pulih tidak dapat diakses', 'status' => 'resolved',
            'detected_at' => $detectedAt, 'resolved_at' => $resolvedAt,
        ]);

        $card = collect(app(TvBoard::class)->build()['kartu'])->keyBy('name')['Pulih'];

        $this->assertSame('up', $card['status']);
        $this->assertSame($resolvedAt->timestamp, $card['up_since_ts']);
        $this->assertSame('2 jam', $card['up_for']);
        $this->assertSame($detectedAt->translatedFormat('d M Y, H:i'), $card['last_down_at']);
    }

    public function test_never_down_site_shows_belum_pernah_and_up_since_first_check(): void
    {
        $site = $this->siteWithCheck('Selalu Up', 'up');
        $firstCheck = now()->subDays(5)->startOfSecond();
        $site->checks()->create(['is_up' => true, 'checked_at' => $firstCheck]);
        $down = $this->siteWithCheck('Mati Sekarang', 'down');

        $cards = collect(app(TvBoard::class)->build()['kartu'])->keyBy('name');

        $this->assertSame('Belum pernah', $cards['Selalu Up']['last_down_at']);
        $this->assertArrayNotHasKey('last_down_ts', $cards['Selalu Up']);
        $this->assertSame($firstCheck->timestamp, $cards['Selalu Up']['up_since_ts']);
        // up_since is only meaningful for up sites.
        $this->assertSame(0, $cards[$down->name]['up_since_ts']);
    }

    public function test_up_since_ignores_older_resolved_at_when_latest_downtime_has_none(): void
    {
        $site = $this->siteWithCheck('Pulih Lagi', 'up');
        $oldResolved = now()->subDays(9)->startOfSecond();
        $latestDetected = now()->subHours(2)->startOfSecond();
        $recoveredAt = now()->subHour()->startOfSecond();
        Incident::create([
            'website_id' => $site->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'Lama', 'status' => 'resolved',
            'detected_at' => now()->subDays(10), 'resolved_at' => $oldResolved,
        ]);
        Incident::create([
            'website_id' => $site->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'Baru', 'status' => 'resolved',
            'detected_at' => $latestDetected, 'resolved_at' => null,
        ]);
        $site->checks()->create(['is_up' => false, 'checked_at' => $latestDetected->copy()->addMinutes(10)]);
        $site->checks()->create(['is_up' => true, 'checked_at' => $recoveredAt]);

        $card = collect(app(TvBoard::class)->build()['kartu'])->keyBy('name')['Pulih Lagi'];

        $this->assertSame($recoveredAt->timestamp, $card['up_since_ts']);
        $this->assertSame($latestDetected->translatedFormat('d M Y, H:i'), $card['last_down_at']);
    }

    public function test_old_json_endpoint_is_gone(): void
    {
        $this->get('/tv/data')->assertNotFound();
    }

    public function test_empty_board_renders_one_slide_of_placeholders(): void
    {
        $html = $this->get(route('tv'))->assertOk()->assertSee('Slide 1 / 1')->getContent();

        $this->assertSame(16, substr_count($html, 'border-dashed border-accent-600'));
    }

    public function test_exactly_sixteen_sites_fit_one_slide(): void
    {
        Website::factory()->count(16)->create(['last_status' => 'up']);

        $html = $this->get(route('tv'))
            ->assertOk()
            ->assertSee('Slide 1 / 1')
            ->assertDontSee('Slide 1 / 2')
            ->getContent();

        $this->assertSame(0, substr_count($html, 'border-dashed border-accent-600'));
    }

    public function test_seventeen_sites_spill_onto_a_second_slide(): void
    {
        Website::factory()->count(17)->create(['last_status' => 'up']);

        // Slide label is Alpine-only; count the server-rendered slide wrappers instead.
        $html = $this->get(route('tv'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'wire:key="tv-slide-'));
        $this->assertStringContainsString('wire:key="tv-slide-1"', $html);
        $this->assertSame(15, substr_count($html, 'border-dashed border-accent-600'));
    }

    public function test_settings_saves_tv_poll_interval_and_board_polls_with_it(): void
    {
        (new RoleSeeder)->run();
        $admin = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id')]);

        Livewire::actingAs($admin)
            ->test(SettingsIndex::class)
            ->assertSet('tvPollSeconds', 300)
            ->set('tvPollSeconds', 45)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('45', Setting::get('tv_poll_seconds'));
        $this->assertSame(45, app(AppSettings::class)->tvPollSeconds());

        Livewire::test(Display::class)
            ->assertSeeHtml('wire:poll.45s')
            ->assertDontSeeHtml('wire:poll.300s');
    }

    public function test_settings_rejects_tv_poll_interval_out_of_range(): void
    {
        (new RoleSeeder)->run();
        $admin = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id')]);

        foreach ([9, 3601] as $seconds) {
            Livewire::actingAs($admin)
                ->test(SettingsIndex::class)
                ->set('tvPollSeconds', $seconds)
                ->call('save')
                ->assertHasErrors(['tvPollSeconds']);
        }

        $this->assertNull(Setting::get('tv_poll_seconds'));
    }

    public function test_settings_save_clears_cached_board(): void
    {
        (new RoleSeeder)->run();
        $admin = User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id')]);
        Cache::put('tv-board', ['stale' => true], 600);

        Livewire::actingAs($admin)->test(SettingsIndex::class)->call('save')->assertHasNoErrors();

        $this->assertFalse(Cache::has('tv-board'));
    }

    public function test_default_poll_interval_is_rendered_when_unset(): void
    {
        $this->get(route('tv'))->assertOk()->assertSee('wire:poll.300s', false);
    }

    public function test_wallboard_is_throttled_after_sixty_requests_per_minute(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->get(route('tv'))->assertOk();
        }

        $this->get(route('tv'))->assertStatus(429);
    }

    public function test_problem_sites_are_listed_before_online_sites(): void
    {
        $this->siteWithCheck('AAA Online', 'up');
        $this->siteWithCheck('ZZZ Down', 'down');

        $this->get(route('tv'))->assertSeeInOrder(['ZZZ Down', 'AAA Online']);
    }

    public function test_priority_sites_come_first_without_exposing_the_flag(): void
    {
        $this->travel(-1)->hours();
        $this->siteWithCheck('YYY Priority Down', 'down')->update(['is_priority' => true]);
        $this->travelBack();
        $this->siteWithCheck('AAA Online', 'up');
        $this->siteWithCheck('BBB Down', 'down');
        $this->siteWithCheck('ZZZ Priority Online', 'up')->update(['is_priority' => true]);

        $cards = app(TvBoard::class)->build()['kartu'];

        // Down outranks priority and is newest first regardless of priority; then priority, then the rest.
        $this->assertSame(['BBB Down', 'YYY Priority Down', 'ZZZ Priority Online', 'AAA Online'], array_column($cards, 'name'));
        $this->assertArrayNotHasKey('priority', $cards[0]);
    }

    public function test_public_page_renders_down_then_priority_then_normal_sites(): void
    {
        $this->siteWithCheck('AAA Online', 'up');
        $this->siteWithCheck('ZZZ Priority Online', 'up')->update(['is_priority' => true]);
        $this->siteWithCheck('MMM Down', 'down');

        $this->get(route('tv'))->assertSeeInOrder(['MMM Down', 'ZZZ Priority Online', 'AAA Online']);
    }

    public function test_down_non_priority_site_comes_before_priority_up_before_normal(): void
    {
        $this->siteWithCheck('AAA Online', 'up');
        $this->siteWithCheck('ZZZ Priority Online', 'up')->update(['is_priority' => true]);
        $this->siteWithCheck('MMM Down', 'down');

        $this->assertSame(
            ['MMM Down', 'ZZZ Priority Online', 'AAA Online'],
            array_column(app(TvBoard::class)->build()['kartu'], 'name'),
        );
    }

    public function test_every_group_is_newest_first_and_down_ignores_priority(): void
    {
        $this->travel(-2)->hours();
        $this->siteWithCheck('P Down Old', 'down')->update(['is_priority' => true]);
        $this->siteWithCheck('P Online Old', 'up')->update(['is_priority' => true]);
        $this->siteWithCheck('N Online Old', 'up');
        $this->travelBack();
        $this->siteWithCheck('N Down New', 'down');
        $this->siteWithCheck('P Online New', 'up')->update(['is_priority' => true]);
        $this->siteWithCheck('N Online New', 'up');

        $cards = app(TvBoard::class)->build()['kartu'];

        $this->assertSame(
            ['N Down New', 'P Down Old', 'P Online New', 'P Online Old', 'N Online New', 'N Online Old'],
            array_column($cards, 'name'),
        );
        $this->assertArrayNotHasKey('sort_created', $cards[0]);
    }

    public function test_down_site_under_maintenance_is_not_in_the_top_group(): void
    {
        $this->siteWithCheck('AAA Online', 'up')->update(['is_priority' => true]);
        $site = $this->siteWithCheck('MMM Down Maintenance', 'down');
        MaintenanceWindow::create([
            'website_id' => $site->id,
            'reason' => 'Migrasi server',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->assertSame(
            ['AAA Online', 'MMM Down Maintenance'],
            array_column(app(TvBoard::class)->build()['kartu'], 'name'),
        );
    }

    public function test_no_public_card_or_event_carries_the_priority_flag(): void
    {
        $this->siteWithCheck('P Down', 'down')->update(['is_priority' => true]);
        $this->siteWithCheck('P Online', 'up')->update(['is_priority' => true]);
        $this->siteWithCheck('N Online', 'up');

        $board = app(TvBoard::class)->build();

        foreach ($board['kartu'] as $card) {
            $this->assertArrayNotHasKey('priority', $card);
            $this->assertArrayNotHasKey('is_priority', $card);
        }
        $this->assertStringNotContainsString('priority', json_encode($board));
    }

    public function test_problem_group_lists_the_newest_incident_first_regardless_of_creation(): void
    {
        $this->travel(-2)->hours();
        $older = $this->siteWithCheck('Down Dibuat Lama', 'down');
        $this->travelBack();
        $newer = $this->siteWithCheck('Down Dibuat Baru', 'down');
        foreach ([[$older, now()->subMinutes(5)], [$newer, now()->subHours(3)]] as [$site, $at]) {
            Incident::create([
                'website_id' => $site->id, 'type' => 'downtime', 'severity' => 'critical',
                'title' => 'x', 'status' => 'open', 'detected_at' => $at,
            ]);
        }

        $this->assertSame(
            ['Down Dibuat Lama', 'Down Dibuat Baru'],
            array_column(app(TvBoard::class)->build()['kartu'], 'name'),
        );
    }

    public function test_rest_group_is_priority_first_then_online_internal_and_dead(): void
    {
        $this->travel(-1)->hours();
        $this->siteWithCheck('N Online', 'up');
        $this->siteWithCheck('P Online', 'up')->update(['is_priority' => true]);
        $this->travelBack();
        $this->siteWithCheck('P Unknown', 'unknown')->update(['is_priority' => true]);
        $this->siteWithCheck('N Internal', 'down')->update(['is_internal_only' => true]);
        $this->siteWithCheck('N Unknown', 'unknown');

        $this->assertSame(
            ['P Online', 'P Unknown', 'N Online', 'N Internal', 'N Unknown'],
            array_column(app(TvBoard::class)->build()['kartu'], 'name'),
        );
    }

    public function test_public_html_never_contains_the_priority_or_sort_fields(): void
    {
        $this->siteWithCheck('P Down', 'down')->update(['is_priority' => true]);
        $this->siteWithCheck('P Online', 'up')->update(['is_priority' => true]);

        $this->get(route('tv'))->assertOk()
            ->assertDontSee('is_priority', false)
            ->assertDontSee('sort_created', false)
            ->assertDontSee('"priority"', false)
            ->assertDontSee('&quot;priority&quot;', false);
    }

    public function test_a_down_site_stays_on_the_first_slide_and_order_carries_across_slides(): void
    {
        $this->travel(-10)->days();
        $this->siteWithCheck('Down Tertua', 'down');
        $this->travelBack();
        foreach (range(1, 17) as $i) {
            $this->travel(-$i)->minutes();
            $this->siteWithCheck(sprintf('Online %02d', $i), 'up');
            $this->travelBack();
        }

        $html = $this->get(route('tv'))->assertOk()->getContent();
        $secondSlide = strpos($html, 'wire:key="tv-slide-1"');

        $this->assertNotFalse($secondSlide);
        $this->assertLessThan($secondSlide, strpos($html, 'Down Tertua'));
        $this->assertLessThan($secondSlide, strpos($html, 'Online 15'));
        $this->assertGreaterThan($secondSlide, strpos($html, 'Online 16'));
        $this->assertGreaterThan($secondSlide, strpos($html, 'Online 17'));
    }

    public function test_all_secondary_sites_keep_the_old_status_order(): void
    {
        $this->siteWithCheck('AAA Online', 'up');
        $this->siteWithCheck('ZZZ Down', 'down');

        $this->assertSame(['ZZZ Down', 'AAA Online'], array_column(app(TvBoard::class)->build()['kartu'], 'name'));
    }

    public function test_livewire_component_renders_with_no_public_properties(): void
    {
        $this->siteWithCheck('Livewire Site', 'up');

        Livewire::test(Display::class)
            ->assertOk()
            ->assertSee('Livewire Site');

        $own = array_filter(
            (new ReflectionClass(Display::class))->getProperties(ReflectionProperty::IS_PUBLIC),
            fn (ReflectionProperty $property) => $property->getDeclaringClass()->getName() === Display::class,
        );

        $this->assertSame([], array_values($own));
    }
}
