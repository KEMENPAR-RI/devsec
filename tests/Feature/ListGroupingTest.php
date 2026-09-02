<?php

namespace Tests\Feature;

use App\Livewire\Incidents\Index as IncidentsIndex;
use App\Livewire\JudolDetections\Index as JudolDetectionsIndex;
use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Models\Incident;
use App\Models\JudolDetection;
use App\Models\User;
use App\Models\Website;
use App\Notifications\WebsiteDownNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ListGroupingTest extends TestCase
{
    use RefreshDatabase;

    public function test_incidents_from_one_site_on_one_day_collapse_into_a_single_group(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['name' => 'Situs Uji']);

        // Three incidents, same site, same day - plus one the day before.
        $this->incident($website, now()->setTime(9, 0));
        $this->incident($website, now()->setTime(10, 0));
        $this->incident($website, now()->setTime(11, 0));
        $this->incident($website, now()->subDay()->setTime(8, 0));

        $groups = Livewire::actingAs($user)
            ->test(IncidentsIndex::class)
            ->viewData('groups');

        $this->assertSame(2, $groups->total(), 'two (site, day) groups, not four rows');
        $this->assertSame(3, (int) $groups->first()->total, 'today\'s group holds all three');
    }

    public function test_two_sites_on_the_same_day_stay_in_separate_groups(): void
    {
        $user = User::factory()->create();
        $this->incident(Website::factory()->create(), now());
        $this->incident(Website::factory()->create(), now());

        $groups = Livewire::actingAs($user)->test(IncidentsIndex::class)->viewData('groups');

        $this->assertSame(2, $groups->total());
    }

    public function test_pagination_counts_groups_rather_than_rows(): void
    {
        $user = User::factory()->create();

        // 12 groups (one site each) of 3 incidents - 36 rows, but only 12 groups,
        // so page one holds the default 10 groups and page two the remaining 2.
        for ($i = 0; $i < 12; $i++) {
            $website = Website::factory()->create();

            for ($j = 0; $j < 3; $j++) {
                $this->incident($website, now()->subMinutes($i * 10 + $j));
            }
        }

        $groups = Livewire::actingAs($user)->test(IncidentsIndex::class)->viewData('groups');

        $this->assertSame(36, Incident::count());
        $this->assertSame(12, $groups->total());
        $this->assertCount(10, $groups->items());
    }

    public function test_a_group_carries_every_row_that_belongs_to_it(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();
        $this->incident($website, now()->setTime(9, 0));
        $this->incident($website, now()->setTime(10, 0));

        $component = Livewire::actingAs($user)->test(IncidentsIndex::class);
        $rows = $component->viewData('incidentsByGroup');

        $this->assertCount(1, $rows, 'one group key');
        $this->assertCount(2, $rows->first(), 'both incidents live under it');
    }

    public function test_judol_detections_group_by_site_and_day(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();

        JudolDetection::create(['website_id' => $website->id, 'url' => 'http://x.example/a', 'matched_keywords' => ['slot gacor'], 'status' => 'new', 'detected_at' => now()]);
        JudolDetection::create(['website_id' => $website->id, 'url' => 'http://x.example/b', 'matched_keywords' => ['slot gacor'], 'status' => 'new', 'detected_at' => now()]);

        $groups = Livewire::actingAs($user)->test(JudolDetectionsIndex::class)->viewData('groups');

        $this->assertSame(1, $groups->total());
        $this->assertSame(2, (int) $groups->first()->total);
    }

    public function test_notifications_group_by_the_website_id_inside_their_payload(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();
        $incident = $this->incident($website, now());

        $user->notify(new WebsiteDownNotification($website, $incident));
        $user->notify(new WebsiteDownNotification($website, $incident));
        $user->notify(new WebsiteDownNotification(Website::factory()->create(), $incident));

        $groups = Livewire::actingAs($user)->test(NotificationsIndex::class)->viewData('groups');

        $this->assertSame(3, $user->notifications()->count());
        $this->assertSame(2, $groups->total(), 'two sites, so two groups');
    }

    public function test_grouping_respects_the_active_filter(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();
        $this->incident($website, now(), type: 'downtime');
        $this->incident($website, now(), type: 'ssl');

        $groups = Livewire::actingAs($user)
            ->test(IncidentsIndex::class)
            ->set('type', 'ssl')
            ->viewData('groups');

        $this->assertSame(1, $groups->total());
        $this->assertSame(1, (int) $groups->first()->total, 'only the SSL incident is counted');
    }

    protected function incident(Website $website, $detectedAt, string $type = 'downtime'): Incident
    {
        return Incident::create([
            'website_id' => $website->id,
            'type' => $type,
            'severity' => 'critical',
            'title' => 'Insiden uji',
            'status' => 'open',
            'detected_at' => $detectedAt,
        ]);
    }
}
