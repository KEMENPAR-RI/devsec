<?php

namespace Tests\Feature;

use App\Livewire\Incidents\Index as IncidentsIndex;
use App\Livewire\JudolDetections\Index as JudolDetectionsIndex;
use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Livewire\Websites\Index as WebsitesIndex;
use App\Models\Incident;
use App\Models\JudolFinding;
use App\Models\User;
use App\Models\Website;
use App\Notifications\WebsiteDownNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ListSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_searching_websites_filters_by_name_or_url(): void
    {
        $user = User::factory()->create();
        Website::factory()->create(['name' => 'Portal Kemenpar', 'url' => 'https://kemenpar.go.id']);
        Website::factory()->create(['name' => 'Situs Lain', 'url' => 'https://lain.example']);

        Livewire::actingAs($user)
            ->test(WebsitesIndex::class)
            ->set('search', 'kemenpar')
            ->assertSee('Portal Kemenpar')
            ->assertDontSee('Situs Lain');
    }

    public function test_searching_incidents_filters_by_title_or_website_name(): void
    {
        $user = User::factory()->create();
        $matchSite = Website::factory()->create(['name' => 'Portal Kemenpar']);
        $otherSite = Website::factory()->create(['name' => 'Situs Lain']);

        Incident::create([
            'website_id' => $matchSite->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'Situs down', 'status' => 'open', 'detected_at' => now(),
        ]);
        Incident::create([
            'website_id' => $otherSite->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'Insiden lain', 'status' => 'open', 'detected_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(IncidentsIndex::class)
            ->set('search', 'kemenpar')
            ->assertSee('Situs down')
            ->assertDontSee('Insiden lain');
    }

    /**
     * The judol page lists injection sites, so its search reaches what an operator
     * actually has in hand: a domain, a phrase, a region, or a URL.
     */
    public function test_searching_judol_findings_filters_by_evidence_or_url(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['name' => 'Portal Kemenpar']);

        JudolFinding::create([
            'website_id' => $website->id, 'region_key' => 'footer', 'region_label' => 'Footer',
            'attack_type' => 'backlink_injection', 'linked_domains' => ['gacor88.com'],
            'affected_urls' => ['https://kemenpar.go.id/slot-gacor.html'], 'affected_count' => 1,
            'status' => 'new', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        JudolFinding::create([
            'website_id' => $website->id, 'region_key' => 'header', 'region_label' => 'Header',
            'attack_type' => 'backlink_injection', 'linked_domains' => ['togel99.net'],
            'affected_urls' => ['https://kemenpar.go.id/normal-page.html'], 'affected_count' => 1,
            'status' => 'new', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(JudolDetectionsIndex::class)
            ->set('search', 'gacor88')
            ->assertSee('gacor88.com')
            ->assertDontSee('togel99.net');
    }

    public function test_searching_notifications_filters_by_title_or_message(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['name' => 'Portal Kemenpar']);
        $incident = Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now(),
        ]);

        $user->notify(new WebsiteDownNotification($website, $incident));

        $unrelated = Website::factory()->create(['name' => 'Situs Tidak Terkait']);
        $unrelatedIncident = Incident::create([
            'website_id' => $unrelated->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'y', 'status' => 'open', 'detected_at' => now(),
        ]);
        $user->notify(new WebsiteDownNotification($unrelated, $unrelatedIncident));

        Livewire::actingAs($user)
            ->test(NotificationsIndex::class)
            ->set('search', 'Kemenpar')
            ->assertSee('Portal Kemenpar')
            ->assertDontSee('Situs Tidak Terkait');
    }

    public function test_search_resets_pagination_to_the_first_page(): void
    {
        $user = User::factory()->create();
        Website::factory()->count(15)->create(['name' => 'Situs Umum']);
        Website::factory()->create(['name' => 'Unik Sekali']);

        Livewire::actingAs($user)
            ->test(WebsitesIndex::class)
            ->call('gotoPage', 2)
            ->assertSet('paginators.page', 2)
            ->set('search', 'Unik')
            ->assertSet('paginators.page', 1);
    }

    public function test_the_global_header_search_form_filters_the_situs_page_via_the_query_string(): void
    {
        $user = User::factory()->create();
        Website::factory()->create(['name' => 'Portal Kemenpar', 'url' => 'https://kemenpar.go.id']);
        Website::factory()->create(['name' => 'Situs Lain', 'url' => 'https://lain.example']);

        // Mirrors the plain GET form in the header (name="search", action=route('situs'))
        // landing straight on the #[Url]-bound Livewire property, with no JS involved.
        $this->actingAs($user)
            ->get('/situs?search=kemenpar')
            ->assertOk()
            ->assertSee('Portal Kemenpar')
            ->assertDontSee('Situs Lain');
    }
}
