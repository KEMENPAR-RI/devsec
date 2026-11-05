<?php

namespace App\Livewire\Websites;

use App\Jobs\CheckWebsiteJob;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\MaintenanceWindow;
use App\Models\Website;
use App\Services\Monitoring\DashboardOverview;
use App\Services\Monitoring\SecurityGrader;
use App\Services\Monitoring\UptimeHistory;
use App\Support\Dates;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('components.layouts.app')]
class Show extends Component
{
    use InteractsWithToasts;

    public Website $website;

    // --- Maintenance window form ------------------------------------------

    public bool $showMaintenanceForm = false;

    public string $maintenanceReason = '';

    /** Datetime-local values, read in the app timezone rather than UTC. */
    public string $maintenanceStartsAt = '';

    public string $maintenanceEndsAt = '';

    /** Chart range: one of DashboardOverview::RANGES. */
    public string $range = DashboardOverview::DEFAULT_RANGE;

    /** Per-request memo of performance(), keyed by range (series and rangeMetrics share it). */
    protected array $performance = [];

    public function mount(Website $website): void
    {
        $this->authorize('situs.view');

        $this->website = $website;
    }

    public function checkNow(): void
    {
        $this->authorize('situs.update');

        $job = new CheckWebsiteJob($this->website);

        // Take the unique lock here so a dropped duplicate can be reported; Bus::dispatch skips the second acquire.
        $lock = new UniqueLock(app(Cache::class));

        if (! $lock->acquire($job)) {
            $this->toastInfo('Pengecekan sudah dalam antrean.');

            return;
        }

        try {
            Bus::dispatch($job);
        } catch (Throwable $e) {
            // Otherwise the button stays "already queued" until uniqueFor expires.
            $lock->release($job);

            throw $e;
        }

        $this->toastSuccess('Pengecekan dikirim ke antrean.');
    }

    /** Arrays (or nested paths) can't be coerced into the string property, so reject them before the set. */
    public function updatingRange(mixed $value, ?string $key = null): void
    {
        if (is_array($value) || is_object($value) || $key !== null) {
            abort(422);
        }
    }

    public function updatedRange(mixed $value): void
    {
        $this->range = DashboardOverview::normalizeRange(is_string($value) ? $value : null);
    }

    /** @return array{series: list<array{label: string, ms: ?int, uptime: ?float, up: ?bool}>, uptime: ?float, avg_ms: ?int} */
    protected function performance(): array
    {
        $range = DashboardOverview::normalizeRange($this->range);

        return $this->performance[$range] ??= app(DashboardOverview::class)->performance($range, $this->website->id);
    }

    /** @return list<array{label: string, ms: ?int, uptime: ?float, up: ?bool}> */
    #[Computed]
    public function series(): array
    {
        return $this->performance()['series'];
    }

    /** @return array{uptime: ?float, avg_ms: ?int} */
    #[Computed]
    public function rangeMetrics(): array
    {
        $performance = $this->performance();

        return ['uptime' => $performance['uptime'], 'avg_ms' => $performance['avg_ms']];
    }

    /** @return array{'24h': ?float, '7d': ?float, '30d': ?float} */
    #[Computed]
    public function uptimeSummary(): array
    {
        return app(UptimeHistory::class)->summary($this->website->id);
    }

    /** @return list<array{uuid: string, started_at: Carbon, ended_at: ?Carbon, duration_seconds: int, ongoing: bool, cause: ?string, status_code: ?int}> */
    #[Computed]
    public function downtimes(): array
    {
        return app(UptimeHistory::class)->downtimes($this->website->id);
    }

    #[Computed]
    public function downtimeTotal30d(): int
    {
        return app(UptimeHistory::class)->downtimeSeconds($this->website->id, 30);
    }

    /** @return array{failures: int, threshold: int}|null set while a failure streak is still below the down threshold */
    #[Computed]
    public function verification(): ?array
    {
        $website = $this->website;

        if ($website->last_status === 'down'
            || $website->consecutive_failures <= 0
            || $website->consecutive_failures >= $website->down_threshold_failures) {
            return null;
        }

        return ['failures' => (int) $website->consecutive_failures, 'threshold' => (int) $website->down_threshold_failures];
    }

    /**
     * Re-grades this one site now, instead of waiting for the nightly pass.
     *
     * Synchronous rather than queued: it is one request plus one handshake, and
     * the whole point of the button is seeing the new grade on the screen you
     * pressed it from.
     */
    public function gradeSecurityNow(): void
    {
        $this->authorize('situs.update');

        $result = app(SecurityGrader::class)->grade($this->website);

        if ($result === null) {
            $this->toastWarning('Situs tidak merespons, jadi nilainya tidak diubah.');

            return;
        }

        $this->website->update([
            'security_score' => $result['score'],
            'security_grade' => $result['grade'],
            'security_findings' => $result['findings'],
            'security_checked_at' => now(),
        ]);

        $this->toastSuccess("Penilaian keamanan diperbarui: {$result['grade']} ({$result['score']}/100).");
    }

    // --- Maintenance windows ----------------------------------------------

    public function openMaintenanceForm(): void
    {
        $this->authorize('situs.update');

        $this->resetErrorBag();
        // Prefilled with a plausible window rather than blank fields - the common
        // case is "starting about now, for an hour or two".
        $this->maintenanceReason = '';
        $this->maintenanceStartsAt = Dates::toLocalInput(now());
        $this->maintenanceEndsAt = Dates::toLocalInput(now()->addHours(2));
        $this->showMaintenanceForm = true;
    }

    public function saveMaintenance(): void
    {
        $this->authorize('situs.update');

        $data = $this->validate([
            'maintenanceReason' => ['required', 'string', 'max:255'],
            'maintenanceStartsAt' => ['required', 'date'],
            'maintenanceEndsAt' => ['required', 'date', 'after:maintenanceStartsAt'],
        ], [
            'maintenanceEndsAt.after' => 'Waktu selesai harus setelah waktu mulai.',
        ], [
            'maintenanceReason' => 'keterangan',
            'maintenanceStartsAt' => 'waktu mulai',
            'maintenanceEndsAt' => 'waktu selesai',
        ]);

        MaintenanceWindow::create([
            'website_id' => $this->website->id,
            'created_by' => auth()->id(),
            'reason' => $data['maintenanceReason'],
            // The form speaks the app's timezone; storage is UTC like every other
            // timestamp here, so the conversion happens once, on the way in.
            'starts_at' => Dates::fromLocalInput($data['maintenanceStartsAt']),
            'ends_at' => Dates::fromLocalInput($data['maintenanceEndsAt']),
        ]);

        $this->showMaintenanceForm = false;
        $this->toastSuccess('Jendela pemeliharaan disimpan. Insiden dan notifikasi untuk situs ini ditahan selama periode tersebut.');
    }

    public function deleteMaintenance(string $uuid): void
    {
        $this->authorize('situs.update');

        $this->website->maintenanceWindows()->where('uuid', $uuid)->first()?->delete();

        $this->toastSuccess('Jendela pemeliharaan dihapus.');
    }

    public function render()
    {
        $checks = $this->website->checks()->latest('checked_at')->latest('id')->limit(30)->get()->reverse()->values();

        return view('livewire.websites.show', [
            'checks' => $checks,
            'incidents' => $this->website->incidents()->latest('detected_at')->limit(10)->get(),
            // Includes the all-sites windows, since those cover this site too.
            'maintenanceWindows' => MaintenanceWindow::query()
                ->forWebsite($this->website->id)
                ->upcoming()
                ->orderBy('starts_at')
                ->with('author')
                ->get(),
            'underMaintenance' => $this->website->isUnderMaintenance(),
        ]);
    }
}
