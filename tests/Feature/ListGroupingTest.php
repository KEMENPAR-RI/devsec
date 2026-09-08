<?php

namespace Tests\Feature;

use App\Livewire\Incidents\Index as IncidentsIndex;
use App\Livewire\JudolDetections\Index as JudolDetectionsIndex;
use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Models\Incident;
use App\Models\JudolFinding;
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
        //
        // Anchored to midday rather than `now()`: the timestamps span about two
        // hours, so a run started just after midnight pushed the earliest incidents
        // into the previous day and produced 13 groups instead of 12. Grouping by
        // day is what is under test here, not what time the suite happens to run.
        $noon = now()->startOfDay()->addHours(12);

        for ($i = 0; $i < 12; $i++) {
            $website = Website::factory()->create();

            for ($j = 0; $j < 3; $j++) {
                $this->incident($website, $noon->copy()->subMinutes($i * 10 + $j));
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

    /**
     * The judol page groups on the EVIDENCE, not on the day. One edit by an
     * attacker routinely lands in two places at once - the same link block pasted
     * into the footer and the sidebar - and stating the whole body of evidence
     * twice is exactly the repetition findings exist to collapse.
     */
    public function test_judol_findings_group_by_identical_evidence(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();

        foreach ([['footer', 'Footer'], ['sidebar', 'Sidebar']] as [$key, $label]) {
            $this->finding($website, $key, $label, ['gacor88.com'], ['http://x.example/a', 'http://x.example/b']);
        }

        // A different domain is a different finding, even though it is one more
        // region on the same site.
        $this->finding($website, 'header', 'Header', ['togel99.net'], ['http://x.example/a']);

        $groups = Livewire::actingAs($user)->test(JudolDetectionsIndex::class)->viewData('groups');

        $this->assertCount(2, $groups, 'identical evidence collapses; different evidence does not');

        $shared = $groups->first(fn (array $group) => $group['domains'] === ['gacor88.com']);

        $this->assertCount(2, $shared['findings'], 'both insertion points hang under one entry');
        // Unioned, not summed: the same two pages carry both regions.
        $this->assertSame(2, $shared['url_count']);
    }

    /**
     * @param  array<int, string>  $domains
     * @param  array<int, string>  $urls
     */
    private function finding(Website $website, string $key, string $label, array $domains, array $urls): JudolFinding
    {
        return JudolFinding::create([
            'website_id' => $website->id,
            'region_key' => $key,
            'region_label' => $label,
            'attack_type' => 'backlink_injection',
            'linked_domains' => $domains,
            'affected_urls' => $urls,
            'affected_count' => count($urls),
            'status' => 'new',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
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
