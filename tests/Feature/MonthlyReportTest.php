<?php

namespace Tests\Feature;

use App\Livewire\Reports\Index as ReportsIndex;
use App\Models\Incident;
use App\Models\Role;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Services\Reporting\MonthlyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The figures an institution files. They have to match the dashboard, and they
 * have to be honest about the months where nothing was measured.
 */
class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    protected Carbon $month;

    protected function setUp(): void
    {
        parent::setUp();

        $this->month = now()->subMonthNoOverflow()->startOfMonth();
    }

    // --- The figures -------------------------------------------------------

    public function test_uptime_is_the_share_of_checks_that_succeeded(): void
    {
        $website = $this->website();

        $this->recordChecks($website, up: 9, down: 1);

        $row = $this->build()['rows']->firstWhere('nama', $website->name);

        $this->assertSame(90.0, $row['uptime']);
        $this->assertSame(10, $row['total_cek']);
    }

    public function test_a_site_with_no_checks_reads_as_no_data_not_as_perfect(): void
    {
        $website = $this->website();

        $row = $this->build()['rows']->firstWhere('nama', $website->name);

        // Reporting an unmeasured month as 100% would be a lie that flatters.
        $this->assertNull($row['uptime']);
        $this->assertSame(0, $row['total_cek']);
    }

    public function test_checks_outside_the_period_are_not_counted(): void
    {
        $website = $this->website();

        $this->recordChecks($website, up: 5, down: 0);
        $this->recordChecks($website, up: 0, down: 5, at: $this->month->copy()->subMonth());

        $row = $this->build()['rows']->firstWhere('nama', $website->name);

        $this->assertSame(100.0, $row['uptime']);
    }

    public function test_incidents_are_broken_down_by_type(): void
    {
        $website = $this->website();

        $this->recordIncident($website, 'downtime');
        $this->recordIncident($website, 'downtime');
        $this->recordIncident($website, 'dns');

        $row = $this->build()['rows']->firstWhere('nama', $website->name);

        $this->assertSame(3, $row['insiden_total']);
        $this->assertSame(2, $row['insiden_downtime']);
        $this->assertSame(1, $row['insiden_dns']);
        $this->assertSame(0, $row['insiden_ssl']);
    }

    public function test_the_average_only_counts_sites_that_have_data(): void
    {
        $measured = $this->website();
        $this->website();

        $this->recordChecks($measured, up: 10, down: 0);

        // A newly added site must not drag the institution-wide figure to 50%.
        $this->assertSame(100.0, $this->build()['totals']['uptime_rata']);
        $this->assertSame(1, $this->build()['totals']['situs_terukur']);
        $this->assertSame(2, $this->build()['totals']['situs']);
    }

    public function test_one_site_can_be_reported_alone(): void
    {
        $mine = $this->website();
        $this->website();

        $report = app(MonthlyReport::class)->build($this->month, $this->month->copy()->endOfMonth(), $mine);

        $this->assertCount(1, $report['rows']);
        $this->assertSame($mine->name, $report['rows']->first()['nama']);
    }

    public function test_the_current_security_grade_is_carried_into_the_report(): void
    {
        $website = $this->website();
        $website->update(['security_grade' => 'B', 'security_score' => 80]);

        $row = $this->build()['rows']->firstWhere('nama', $website->name);

        $this->assertSame('B', $row['nilai_keamanan']);
        $this->assertSame(80, $row['skor_keamanan']);
    }

    // --- CSV ---------------------------------------------------------------

    public function test_the_csv_opens_cleanly_in_a_localised_excel(): void
    {
        $website = $this->website();
        $this->recordChecks($website, up: 10, down: 0);

        $csv = app(MonthlyReport::class)->toCsv($this->build()['rows']);

        // The BOM and the semicolon are what make this openable on an Indonesian
        // Windows install without an import wizard.
        $this->assertStringStartsWith("\u{FEFF}", $csv);
        $this->assertStringContainsString('Nama Situs;URL;Uptime (%)', $csv);
        $this->assertStringContainsString($website->name, $csv);
    }

    public function test_a_name_containing_a_separator_does_not_break_the_row(): void
    {
        $website = $this->website();
        $website->update(['name' => 'Dinas A; Dinas B']);

        $csv = app(MonthlyReport::class)->toCsv($this->build()['rows']);

        $this->assertStringContainsString('"Dinas A; Dinas B"', $csv);
    }

    // --- The page ----------------------------------------------------------

    public function test_the_page_is_closed_without_the_permission(): void
    {
        $user = User::factory()->create(['role_id' => Role::factory()->withPermissions(['dashboard.view'])->create()->id]);

        $this->actingAs($user)->get(route('laporan'))->assertForbidden();
    }

    public function test_the_page_defaults_to_last_month(): void
    {
        // The month that has finished - a report on a period still running is the
        // one nobody wants.
        Livewire::actingAs($this->admin())
            ->test(ReportsIndex::class)
            ->assertSet('month', now()->subMonthNoOverflow()->format('Y-m'));
    }

    public function test_a_nonsense_month_in_the_url_does_not_break_the_page(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ReportsIndex::class, ['month' => 'bukan-bulan'])
            ->assertOk();
    }

    public function test_the_csv_download_is_served_and_audited(): void
    {
        $website = $this->website();
        $this->recordChecks($website, up: 3, down: 0);

        $response = $this->actingAs($this->admin())
            ->get(route('laporan.unduh', ['bulan' => $this->month->format('Y-m')]));

        $response->assertOk();
        $this->assertStringContainsString($website->name, $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['event' => 'laporan.diunduh']);
    }

    public function test_the_csv_download_is_closed_without_the_permission(): void
    {
        $user = User::factory()->create(['role_id' => Role::factory()->withPermissions(['dashboard.view'])->create()->id]);

        $this->actingAs($user)->get(route('laporan.unduh'))->assertForbidden();
    }

    // --- Helpers -----------------------------------------------------------

    /** Reuses the superadmin role - its slug is unique, so a second one collides. */
    protected function admin(): User
    {
        $role = Role::firstWhere('slug', 'superadmin') ?? Role::factory()->superadmin()->create();

        return User::factory()->create(['role_id' => $role->id]);
    }

    protected function website(): Website
    {
        static $sequence = 0;

        return Website::factory()->create(['url' => 'https://laporan-'.(++$sequence).'.example']);
    }

    /**
     * @return array{rows: Collection, totals: array<string, mixed>, period: array<string, Carbon>}
     */
    protected function build(): array
    {
        return app(MonthlyReport::class)->build($this->month, $this->month->copy()->endOfMonth());
    }

    protected function recordChecks(Website $website, int $up, int $down, ?Carbon $at = null): void
    {
        $at ??= $this->month->copy()->addDays(5);

        foreach (range(1, $up + $down) as $index) {
            WebsiteCheck::create([
                'website_id' => $website->id,
                'is_up' => $index <= $up,
                'status_code' => $index <= $up ? 200 : 500,
                'response_time_ms' => 400,
                'checked_at' => $at->copy()->addMinutes($index),
            ]);
        }
    }

    protected function recordIncident(Website $website, string $type): void
    {
        Incident::create([
            'website_id' => $website->id,
            'type' => $type,
            'severity' => 'warning',
            'title' => 'Uji',
            'description' => 'Uji',
            'status' => 'resolved',
            'detected_at' => $this->month->copy()->addDays(3),
        ]);
    }
}
