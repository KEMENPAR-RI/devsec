<?php

namespace Tests;

use App\Models\Setting;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDnsRecords;

// Must load before DnsMonitor's first dns_get_record() call, or PHP caches the global function.
require_once __DIR__.'/Support/FakeDnsRecords.php';

abstract class TestCase extends BaseTestCase
{
    // Runs inside refreshApplication(), i.e. before setUpTraits() can trigger RefreshDatabase's migrate:fresh.
    public function createApplication()
    {
        $app = parent::createApplication();

        $default = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$default}.database");

        if ($default !== 'sqlite' || $database !== ':memory:') {
            $this->fail("Tests must run on sqlite :memory: (got {$default} / {$database}) — run php artisan config:clear");
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::flush();
        OutboundUrlGuard::flush();
        // No real DNS in tests: factory domains are real names; tests needing answers call fakeDns() themselves.
        OutboundUrlGuard::fakeDns([]);
        // Same for DnsMonitor: an unfaked lookup of a *.example host blocks on resolver timeouts (~100s per check-domains run).
        FakeDnsRecords::fake([]);
    }
}
