<?php

namespace Tests\Feature;

use App\Livewire\Websites\Index;
use App\Livewire\Websites\Show;
use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\ComponentAttributeBag;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteStatusLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function spikeIncident(Website $website, int $baseline, int $actual): Incident
    {
        return Incident::create([
            'website_id' => $website->id,
            'type' => 'attack',
            'severity' => 'warning',
            'title' => 'Lonjakan response time',
            'meta' => ['reason' => 'response_time_spike', 'baseline_ms' => $baseline, 'response_time_ms' => $actual],
            'status' => 'open',
            'detected_at' => now(),
        ]);
    }

    protected function downtimeIncident(Website $website, bool $overload, ?int $daysAgo = null): Incident
    {
        return Incident::create([
            'website_id' => $website->id,
            'type' => 'downtime',
            'severity' => 'critical',
            'title' => 'Down',
            'meta' => $overload
                ? ['likely_cause' => 'Kelebihan beban / kemungkinan serangan DDoS', 'cause_is_overload' => true]
                : ['likely_cause' => 'Kesalahan aplikasi atau database', 'cause_is_overload' => false],
            'status' => 'open',
            'detected_at' => $daysAgo ? now()->subDays($daysAgo) : now(),
        ]);
    }

    public function test_a_site_that_is_up_and_quiet_is_green(): void
    {
        $website = Website::factory()->create(['last_status' => 'up']);

        $this->assertSame('up', $website->statusKey());
    }

    public function test_a_slow_site_is_still_online(): void
    {
        // A response-time spike is not a status. The site is answering, and giving
        // it a third colour made an operator scan past the two states that actually
        // need acting on. The slow-down still lives on as an incident, and the load
        // column says how hard the server is working.
        $website = Website::factory()->create(['last_status' => 'up']);
        $this->spikeIncident($website, baseline: 200, actual: 20000);

        $this->assertSame('up', $website->statusKey());
    }

    public function test_an_outage_diagnosed_as_overload_is_flagged_as_an_attack(): void
    {
        $website = Website::factory()->create(['last_status' => 'down']);
        $this->downtimeIncident($website, overload: true);

        $this->assertTrue($website->wasAttacked());
        $this->assertSame('attack', $website->statusKey(false, $website->wasAttacked()));
    }

    public function test_an_outage_from_an_application_error_is_plain_down(): void
    {
        $website = Website::factory()->create(['last_status' => 'down']);
        $this->downtimeIncident($website, overload: false);

        $this->assertFalse($website->wasAttacked());
        $this->assertSame('down', $website->statusKey(false, $website->wasAttacked()));
    }

    public function test_offline_outranks_the_attack_label(): void
    {
        $website = Website::factory()->create(['last_status' => 'down']);

        $this->assertSame('offline', $website->statusKey(true, true));
    }

    public function test_internal_only_still_outranks_everything_else(): void
    {
        $website = Website::factory()->create(['last_status' => 'down', 'is_internal_only' => true]);

        $this->assertSame('internal', $website->statusKey(true, true));
    }

    public function test_offline_ids_finds_offline_sites_inside_a_paginator(): void
    {
        // Regression: collect($paginator) collects the paginator's own properties,
        // not the models in it, so every list view showed a long-dead site as red
        // while its detail page showed it gray.
        $website = Website::factory()->create(['last_status' => 'down']);
        $this->downtimeIncident($website, overload: false, daysAgo: 3);

        $paginator = Website::query()->paginate(10);

        $this->assertTrue($website->isOffline(), 'the detail page sees it as offline');
        $this->assertArrayHasKey($website->id, Website::offlineIds($paginator), 'and so must the list');
    }

    public function test_the_bulk_lookups_agree_with_the_per_record_methods(): void
    {
        $attacked = Website::factory()->create(['last_status' => 'down']);
        $this->downtimeIncident($attacked, overload: true);

        $paginator = Website::query()->paginate(10);

        $this->assertSame($attacked->wasAttacked(), isset(Website::attackedIds($paginator)[$attacked->id]));
    }

    public function test_the_bulk_lookups_also_accept_a_plain_collection(): void
    {
        $website = Website::factory()->create(['last_status' => 'down']);
        $this->downtimeIncident($website, overload: true, daysAgo: 3);

        $rows = Website::all();

        $this->assertArrayHasKey($website->id, Website::offlineIds($rows));
        $this->assertArrayHasKey($website->id, Website::attackedIds($rows));
    }

    public function test_every_status_has_a_name_and_a_description(): void
    {
        // statusKey() must never return a key the display table has no entry for,
        // or the dashboard renders a coloured dot nobody can name.
        foreach (['up', 'attack', 'down', 'offline', 'internal', 'unknown'] as $status) {
            $config = Website::statusConfig($status);

            $this->assertNotSame('', $config['label'], "{$status} needs a name");
            $this->assertNotSame('', $config['description'], "{$status} needs an explanation");
            $this->assertNotSame('', $config['badge'] ?? '', "{$status} needs badge colours");
        }
    }

    public function test_the_badge_renders_in_the_status_colour(): void
    {
        $badge = (string) view('components.status-badge', [
            'status' => 'attack',
            'attributes' => new ComponentAttributeBag,
        ]);

        $this->assertStringContainsString('Down - Dugaan DDoS', $badge);
        $this->assertStringContainsString('bg-red-50', $badge, 'filled in its own colour');
        $this->assertStringContainsString('rounded-full', $badge);
    }

    public function test_an_unrecognised_status_falls_back_instead_of_throwing(): void
    {
        $this->assertSame(Website::STATUSES['unknown'], Website::statusConfig('something_new'));
    }

    public function test_the_two_reds_are_told_apart_by_name(): void
    {
        // Both outages are red, so the name is the only thing distinguishing
        // "it stopped answering" from "it was overwhelmed".
        $attack = Website::statusConfig('attack');
        $down = Website::statusConfig('down');

        $this->assertSame($attack['dot'], $down['dot'], 'same colour by design');
        $this->assertNotSame($attack['label'], $down['label'], 'so the names must differ');
    }

    public function test_the_site_list_shows_the_status_in_words(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['last_status' => 'down']);
        $this->downtimeIncident($website, overload: true);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Down - Dugaan DDoS');
    }

    public function test_the_detail_page_shows_the_status_in_words(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['last_status' => 'down']);
        $this->downtimeIncident($website, overload: true);

        Livewire::actingAs($user)
            ->test(Show::class, ['website' => $website])
            ->assertSee('Down - Dugaan DDoS')
            ->assertSee('Perlu konfirmasi access log server');
    }

    public function test_the_dot_renders_a_distinct_colour_per_status(): void
    {
        $colours = collect(['up', 'attack', 'down', 'offline', 'internal', 'unknown'])
            ->mapWithKeys(fn (string $status) => [
                $status => (string) view('components.status-dot', ['status' => $status, 'attributes' => new ComponentAttributeBag]),
            ]);

        $this->assertStringContainsString('bg-emerald-500', $colours['up']);
        $this->assertStringContainsString('bg-red-500', $colours['attack']);
        $this->assertStringContainsString('bg-red-500', $colours['down']);
        $this->assertStringContainsString('bg-zinc-400', $colours['offline']);

        // Both outage flavours are red, so the label is what distinguishes them.
        $this->assertStringContainsString('DDoS', $colours['attack']);
        $this->assertStringNotContainsString('DDoS', $colours['down']);
    }
}
