<?php

namespace Tests\Feature;

use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Livewire\Websites\Index as WebsitesIndex;
use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use App\Notifications\WebsiteDownNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DataResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_all_websites_cascades_to_their_checks_and_incidents(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();
        $website->checks()->create(['is_up' => true, 'status_code' => 200, 'response_time_ms' => 100, 'checked_at' => now()]);
        Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now(),
        ]);

        Livewire::actingAs($user)->test(WebsitesIndex::class)->call('deleteAll');

        $this->assertSame(0, Website::count());
        $this->assertDatabaseCount('website_checks', 0);
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_deleting_all_notifications_only_affects_the_current_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $website = Website::factory()->create();
        $incident = Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now(),
        ]);

        $user->notify(new WebsiteDownNotification($website, $incident));
        $otherUser->notify(new WebsiteDownNotification($website, $incident));

        Livewire::actingAs($user)->test(NotificationsIndex::class)->call('deleteAll');

        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(1, $otherUser->notifications()->count());
    }
}
