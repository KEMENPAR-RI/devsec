<?php

namespace Tests\Feature;

use App\Livewire\Incidents\Index as IncidentsIndex;
use App\Livewire\JudolDetections\Index as JudolDetectionsIndex;
use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Livewire\Websites\Index as WebsitesIndex;
use App\Models\Incident;
use App\Models\JudolDetection;
use App\Models\JudolKeyword;
use App\Models\User;
use App\Models\Website;
use App\Notifications\WebsiteDownNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DataResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_all_websites_cascades_to_their_checks_incidents_and_judol_detections(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();
        $website->checks()->create(['is_up' => true, 'status_code' => 200, 'response_time_ms' => 100, 'checked_at' => now()]);
        $incident = Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now(),
        ]);
        JudolDetection::create([
            'website_id' => $website->id, 'incident_id' => $incident->id, 'url' => 'http://x.example',
            'matched_keywords' => ['slot gacor'], 'status' => 'new', 'detected_at' => now(),
        ]);

        Livewire::actingAs($user)->test(WebsitesIndex::class)->call('deleteAll');

        $this->assertSame(0, Website::count());
        $this->assertDatabaseCount('website_checks', 0);
        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('judol_detections', 0);
    }

    public function test_deleting_all_incidents_leaves_the_judol_side_of_the_app_alone(): void
    {
        // "Hapus Data" on the Insiden page only clears what that page shows - judol
        // findings live on their own page and have their own reset button.
        $user = User::factory()->create();
        $website = Website::factory()->create();
        $downtime = Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now(),
        ]);
        $judolIncident = Incident::create([
            'website_id' => $website->id, 'type' => 'judol', 'severity' => 'critical',
            'title' => 'x', 'status' => 'open', 'detected_at' => now(),
        ]);
        $detection = JudolDetection::create([
            'website_id' => $website->id, 'incident_id' => $judolIncident->id, 'url' => 'http://x.example',
            'matched_keywords' => ['slot gacor'], 'status' => 'new', 'detected_at' => now(),
        ]);

        Livewire::actingAs($user)->test(IncidentsIndex::class)->call('deleteAll');

        $this->assertDatabaseMissing('incidents', ['id' => $downtime->id]);
        $this->assertDatabaseHas('incidents', ['id' => $judolIncident->id]);
        $this->assertDatabaseHas('judol_detections', ['id' => $detection->id, 'incident_id' => $judolIncident->id]);
    }

    public function test_deleting_all_judol_detections_leaves_keywords_untouched(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();
        JudolDetection::create([
            'website_id' => $website->id, 'url' => 'http://x.example',
            'matched_keywords' => ['frasa unik tes'], 'status' => 'new', 'detected_at' => now(),
        ]);
        JudolKeyword::create(['keyword' => 'frasa unik tes', 'is_active' => true]);

        $keywordCountBefore = JudolKeyword::count();

        Livewire::actingAs($user)->test(JudolDetectionsIndex::class)->call('deleteAll');

        $this->assertSame(0, JudolDetection::count());
        $this->assertSame($keywordCountBefore, JudolKeyword::count());
        $this->assertDatabaseHas('judol_keywords', ['keyword' => 'frasa unik tes']);
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
