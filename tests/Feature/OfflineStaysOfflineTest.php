<?php

namespace Tests\Feature;

use App\Livewire\Websites\Index;
use App\Models\Incident;
use App\Models\Role;
use App\Models\User;
use App\Models\Website;
use App\Services\Monitoring\WebsiteChecker;
use Database\Seeders\RoleSeeder;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * An offline site only comes back online on a check that really reached it.
 * Every failure - probe, crawl, or operator edit - leaves last_status alone.
 */
class OfflineStaysOfflineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Sleep::fake();
    }

    public static function failedProbes(): array
    {
        return [
            'timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
            'dns' => [fn () => throw new ConnectionException('cURL error 6: Could not resolve host: down.example')],
            'connection refused' => [fn () => throw new ConnectionException('cURL error 7: Failed to connect to down.example port 443: Connection refused')],
            'tls' => [fn () => throw new ConnectionException('cURL error 60: SSL certificate problem')],
            'unexpected exception' => [fn () => throw new Exception('boom')],
            '500' => [fn () => Http::response('', 500)],
            '503' => [fn () => Http::response('', 503)],
            '403 blocked' => [fn () => Http::response('Forbidden', 403)],
            '404' => [fn () => Http::response('', 404)],
        ];
    }

    #[DataProvider('failedProbes')]
    public function test_a_failed_probe_keeps_an_offline_site_offline(callable $response): void
    {
        Http::fake(['*' => $response]);
        $website = $this->offlineSite();

        $check = app(WebsiteChecker::class)->check($website->fresh());

        $this->assertFalse($check->is_up);
        $this->assertStillOffline($website, expectedFailures: 4);
    }

    public function test_a_response_slower_than_the_threshold_keeps_an_offline_site_offline(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $website = $this->offlineSite(['response_time_threshold_ms' => -1]);

        app(WebsiteChecker::class)->check($website->fresh());

        $this->assertStillOffline($website, expectedFailures: 4);
    }

    public function test_a_url_refused_by_the_outbound_guard_keeps_an_offline_site_offline(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $website = $this->offlineSite(['url' => 'http://127.0.0.1']);

        app(WebsiteChecker::class)->check($website->fresh());

        $this->assertStillOffline($website, expectedFailures: 4);
        Http::assertNothingSent();
    }

    public function test_a_raised_threshold_below_the_streak_still_keeps_the_site_offline(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        $website = $this->offlineSite();
        $website->update(['down_threshold_failures' => 10]);

        app(WebsiteChecker::class)->check($website->fresh());

        $this->assertStillOffline($website, expectedFailures: 4);
    }

    public function test_only_a_successful_probe_brings_an_offline_site_back_online(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $website = $this->offlineSite();

        app(WebsiteChecker::class)->check($website->fresh());

        $website->refresh();
        $this->assertSame('up', $website->last_status);
        $this->assertSame(0, $website->consecutive_failures);
        $this->assertSame(0, Incident::where('website_id', $website->id)->open()->count());
    }

    // --- Operator edits ----------------------------------------------------------

    public function test_editing_an_offline_site_keeps_it_offline(): void
    {
        (new RoleSeeder)->run();
        $website = $this->offlineSite();

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->call('openEdit', $website->uuid)
            ->set('name', 'Nama Baru')
            ->set('isActive', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Nama Baru', $website->fresh()->name);
        $this->assertStillOffline($website, expectedFailures: 3, expectedChecks: 3);
    }

    public function test_mass_editing_an_offline_site_keeps_it_offline(): void
    {
        (new RoleSeeder)->run();
        $website = $this->offlineSite();

        Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->set('selected', [$website->uuid])
            ->call('openMassEdit')
            ->set('massApply.isActive', true)
            ->set('massIsActive', false)
            ->set('massApply.downThresholdFailures', true)
            ->set('massDownThresholdFailures', 5)
            ->call('massSave')
            ->assertHasNoErrors();

        $this->assertFalse($website->fresh()->is_active);
        $this->assertStillOffline($website, expectedFailures: 3, expectedChecks: 3);
    }

    // --- Helpers -------------------------------------------------------------------

    /** A site already confirmed down: three failed checks, an open downtime incident. */
    protected function offlineSite(array $attributes = [], ?int $lastStatusCode = null): Website
    {
        $website = Website::factory()->create([
            'url' => 'https://down.example',
            'down_threshold_failures' => 3,
            ...$attributes,
            'last_status' => 'down',
            'consecutive_failures' => 3,
        ]);

        foreach (range(3, 1) as $minutesAgo) {
            $website->checks()->create([
                'is_up' => false,
                'status_code' => $lastStatusCode,
                'error_message' => 'down',
                'checked_at' => now()->subMinutes($minutesAgo),
            ]);
        }

        Incident::create([
            'website_id' => $website->id,
            'type' => 'downtime',
            'severity' => 'critical',
            'title' => 'down',
            'status' => 'open',
            'detected_at' => now()->subMinutes(3),
        ]);

        return $website;
    }

    protected function assertStillOffline(Website $website, int $expectedFailures, ?int $expectedChecks = null): void
    {
        $website->refresh();

        $this->assertSame('down', $website->last_status);
        $this->assertSame($expectedFailures, $website->consecutive_failures);
        $this->assertSame(1, Incident::where('website_id', $website->id)->where('type', 'downtime')->open()->count());

        if ($expectedChecks !== null) {
            $this->assertSame($expectedChecks, $website->checks()->count(), 'a crawl or edit must not record uptime checks');
        }
    }

    private function superadmin(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id')]);
    }
}
