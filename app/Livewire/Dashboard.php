<?php

namespace App\Livewire;

use App\Models\Website;
use App\Services\Monitoring\DashboardOverview;
use App\Support\Dates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    /** The picked site's uuid - no real primary key reaches the browser; set only via selectWebsite(). */
    #[Locked]
    public ?string $selectedWebsiteUuid = null;

    /** Chart range: one of DashboardOverview::RANGES. */
    public string $range = DashboardOverview::DEFAULT_RANGE;

    /** Per-request memo of the cached real-time snapshot (not serialized). */
    protected ?array $snapshot = null;

    /** Per-request memo of performance(), keyed by range + site (metrics and series share it). */
    protected array $performance = [];

    public function mount(): void
    {
        $this->authorize('dashboard.view');
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

    /**
     * Takes the site's uuid as a plain string: the picker's "clear" button sends
     * "" for "no site selected", which a typed ?string parameter can't accept
     * directly, so the empty case is normalized to null here.
     */
    public function selectWebsite(string $websiteUuid): void
    {
        // Unknown uuids fall back to "all sites" instead of an empty chart.
        $this->selectedWebsiteUuid = $websiteUuid !== '' && Website::where('uuid', $websiteUuid)->exists()
            ? $websiteUuid
            : null;
    }

    protected function overview(): DashboardOverview
    {
        return app(DashboardOverview::class);
    }

    /** @return array{summary: array, live_sites: list<array>, load_counts: array<string, int>} */
    protected function snapshot(): array
    {
        return $this->snapshot ??= $this->overview()->realtime();
    }

    /** @return array{series: list<array{label: string, ms: ?int, uptime: ?float, up: ?bool}>, uptime: ?float, avg_ms: ?int} */
    protected function performance(): array
    {
        $range = DashboardOverview::normalizeRange($this->range);
        $websiteId = $this->selectedWebsite?->id;

        return $this->performance[$range.':'.$websiteId] ??= $this->overview()->performance($range, $websiteId);
    }

    #[Computed]
    public function selectedWebsite(): ?Website
    {
        return $this->selectedWebsiteUuid
            ? Website::where('uuid', $this->selectedWebsiteUuid)->first(['id', 'uuid', 'name', 'url', 'response_time_threshold_ms'])
            : null;
    }

    /** @return array{total: int, online: int, problem: int, offline: int, breakdown: array<string, int>} */
    #[Computed]
    public function summary(): array
    {
        return $this->snapshot()['summary'];
    }

    /** @return array{uptime: ?float, avg_ms: ?int, open_incidents: int} */
    #[Computed]
    public function metrics(): array
    {
        $performance = $this->performance();

        return [
            'uptime' => $performance['uptime'],
            'avg_ms' => $performance['avg_ms'],
            'open_incidents' => $this->openIncidentsCount,
        ];
    }

    /** @return list<array{label: string, ms: ?int, uptime: ?float, up: ?bool}> */
    #[Computed]
    public function series(): array
    {
        return $this->performance()['series'];
    }

    #[Computed]
    public function openIncidents(): Collection
    {
        return $this->overview()->openIncidents();
    }

    #[Computed]
    public function openIncidentsCount(): int
    {
        return $this->overview()->openIncidentsCount();
    }

    #[Computed]
    public function recentIncidents(): Collection
    {
        return $this->overview()->recentIncidents();
    }

    #[Computed]
    public function liveSites(): array
    {
        return $this->snapshot()['live_sites'];
    }

    /** @return array<string, int> */
    #[Computed]
    public function loadCounts(): array
    {
        return $this->snapshot()['load_counts'];
    }

    #[Computed]
    public function updatedAt(): Carbon
    {
        return now()->timezone(Dates::timezone());
    }

    // Permission flags, resolved once per render instead of per row in the view.

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('situs.create');
    }

    #[Computed]
    public function canViewSites(): bool
    {
        return Gate::allows('situs.view');
    }

    #[Computed]
    public function canViewIncidents(): bool
    {
        return Gate::allows('insiden.view');
    }

    public function render()
    {
        return view('livewire.dashboard');
    }
}
