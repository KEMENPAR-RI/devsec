<?php

namespace Tests\Feature;

use App\Livewire\Websites\Index;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\ComponentAttributeBag;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteLoadLevelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A site whose recent checks all took $baseline ms, with one final check at
     * $latest ms - the shape loadLevel() grades.
     */
    protected function siteWithLatency(int $baseline, int $latest, bool $lastIsUp = true): Website
    {
        $website = Website::factory()->create(['last_status' => 'up']);
        $at = now()->subMinutes(20);

        foreach (range(1, 5) as $i) {
            $website->checks()->create([
                'is_up' => true,
                'status_code' => 200,
                'response_time_ms' => $baseline,
                'checked_at' => $at,
            ]);
            $at = $at->copy()->addMinutes(2);
        }

        $website->checks()->create([
            'is_up' => $lastIsUp,
            'status_code' => $lastIsUp ? 200 : null,
            'response_time_ms' => $latest,
            'checked_at' => $at,
        ]);

        return $website;
    }

    public function test_a_site_answering_at_its_usual_speed_is_light(): void
    {
        $website = $this->siteWithLatency(baseline: 300, latest: 320);

        $this->assertSame('light', $website->loadLevel());
    }

    public function test_twice_the_baseline_reads_as_moderate(): void
    {
        $website = $this->siteWithLatency(baseline: 300, latest: 700);

        $this->assertSame('moderate', $website->loadLevel());
    }

    public function test_three_times_the_baseline_reads_as_heavy(): void
    {
        $website = $this->siteWithLatency(baseline: 300, latest: 1200);

        $this->assertSame('heavy', $website->loadLevel());
    }

    public function test_a_slow_but_steady_site_is_not_punished_for_being_slow(): void
    {
        // The reason the grading is relative: an institutional site on shared
        // hosting that always answers in 2 seconds is healthy for what it is, and a
        // fixed threshold would paint it red forever while saying nothing about
        // whether anything actually changed.
        $website = $this->siteWithLatency(baseline: 2000, latest: 2100);

        $this->assertSame('light', $website->loadLevel());
    }

    public function test_a_near_instant_baseline_never_reads_as_loaded(): void
    {
        // 12ms to 40ms is more than tripling, and means nothing at all.
        $website = $this->siteWithLatency(baseline: 12, latest: 40);

        $this->assertSame('light', $website->loadLevel());
    }

    public function test_a_failed_last_check_has_no_load_to_report(): void
    {
        // The status column already says the site is down; inventing a load level
        // from a timeout would just be noise beside it.
        $website = $this->siteWithLatency(baseline: 300, latest: 20000, lastIsUp: false);

        $this->assertSame('unknown', $website->loadLevel());
    }

    public function test_a_site_never_checked_has_no_load_to_report(): void
    {
        $website = Website::factory()->create(['last_status' => 'unknown']);

        $this->assertSame('unknown', $website->loadLevel());
    }

    public function test_the_bulk_lookup_agrees_with_the_per_record_method(): void
    {
        $light = $this->siteWithLatency(baseline: 300, latest: 310);
        $moderate = $this->siteWithLatency(baseline: 300, latest: 700);
        $heavy = $this->siteWithLatency(baseline: 300, latest: 1500);
        $down = $this->siteWithLatency(baseline: 300, latest: 20000, lastIsUp: false);

        $levels = Website::loadLevels(Website::query()->paginate(10));

        foreach ([$light, $moderate, $heavy, $down] as $website) {
            $this->assertSame(
                $website->loadLevel(),
                $levels[$website->id]['level'],
                "bulk and per-record disagree for website {$website->id}",
            );
        }
    }

    public function test_the_bulk_lookup_carries_the_measurement_it_graded(): void
    {
        // So the view can show the actual milliseconds without another query.
        $website = $this->siteWithLatency(baseline: 300, latest: 1500);

        $levels = Website::loadLevels(Website::all());

        $this->assertSame(1500, $levels[$website->id]['response_time_ms']);
    }

    public function test_every_load_level_has_a_label_and_colours(): void
    {
        foreach (['light', 'moderate', 'heavy', 'unknown'] as $level) {
            $config = Website::loadConfig($level);

            $this->assertNotSame('', $config['label'], "{$level} needs a label");
            $this->assertNotSame('', $config['badge'], "{$level} needs badge colours");
        }
    }

    public function test_an_unrecognised_level_falls_back_instead_of_throwing(): void
    {
        $this->assertSame(Website::LOAD_LEVELS['unknown'], Website::loadConfig('something_else'));
    }

    public function test_the_three_levels_are_green_yellow_and_red(): void
    {
        $this->assertStringContainsString('emerald', Website::loadConfig('light')['badge']);
        $this->assertStringContainsString('yellow', Website::loadConfig('moderate')['badge']);
        $this->assertStringContainsString('red', Website::loadConfig('heavy')['badge']);
    }

    public function test_the_badge_names_the_level_and_quotes_the_measurement(): void
    {
        $badge = (string) view('components.load-badge', [
            'level' => 'moderate',
            'responseTime' => 1500,
            'attributes' => new ComponentAttributeBag,
        ]);

        $this->assertStringContainsString('Sedang', $badge);
        $this->assertStringContainsString('bg-yellow-50', $badge);
        $this->assertStringContainsString('1,500ms', $badge, 'the number behind the word');
    }

    public function test_the_site_list_shows_the_load_column(): void
    {
        $user = User::factory()->create();
        $this->siteWithLatency(baseline: 300, latest: 1500);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Berat');
    }
}
