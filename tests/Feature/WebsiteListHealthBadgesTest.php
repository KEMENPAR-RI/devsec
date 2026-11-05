<?php

namespace Tests\Feature;

use App\Livewire\Websites\Index;
use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteListHealthBadgesTest extends TestCase
{
    use RefreshDatabase;

    protected function siteWithFindings(): Website
    {
        $website = Website::factory()->create([
            'ssl_expires_at' => now()->addDays(2),
            'domain_expires_at' => now()->addDays(100),
            'ssl_hostname_match' => false,
            'security_grade' => 'B',
            'security_score' => 81,
        ]);

        foreach (['open', 'open', 'resolved'] as $status) {
            Incident::create([
                'website_id' => $website->id,
                'type' => 'downtime',
                'severity' => 'critical',
                'title' => 'Down',
                'status' => $status,
                'detected_at' => now(),
            ]);
        }

        return $website;
    }

    protected function renderQueryCount(User $user): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($user)->test(Index::class);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    public function test_row_badges_render_from_the_page_query(): void
    {
        $user = User::factory()->create();
        $this->siteWithFindings();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSeeHtml('2 insiden terbuka')
            ->assertSeeHtml('nama host tidak cocok dengan sertifikat')
            ->assertSeeHtml('Nilai keamanan header &amp; TLS: B (81/100)');
    }

    public function test_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        $user = User::factory()->create();
        $this->siteWithFindings();
        $this->renderQueryCount($user); // warm per-request caches

        $few = $this->renderQueryCount($user);

        foreach (range(1, 8) as $i) {
            $this->siteWithFindings();
        }

        $this->assertSame($few, $this->renderQueryCount($user));
    }
}
