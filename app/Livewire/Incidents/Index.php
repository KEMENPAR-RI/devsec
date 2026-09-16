<?php

namespace App\Livewire\Incidents;

use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\Incident;
use App\Models\Website;
use App\Services\Monitoring\IncidentInvestigator;
use App\Support\DailyGroups;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use InteractsWithToasts;
    use WithPagination;

    #[Url]
    public string $type = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    /** @var array<int, string> per-incident investigate() error, keyed by incident id */
    public array $investigateErrors = [];

    public function mount(): void
    {
        $this->authorize('insiden.view');
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function resolve(Incident $incident): void
    {
        $this->authorize('insiden.update');

        $incident->resolve();
    }

    /**
     * Generates (or regenerates) the AI root-cause analysis for one incident and
     * caches it on the row - see IncidentInvestigator.
     */
    public function investigate(Incident $incident, IncidentInvestigator $investigator): void
    {
        $this->authorize('insiden.update');

        unset($this->investigateErrors[$incident->id]);

        try {
            $analysis = $investigator->investigate($incident);
            $incident->update(['ai_analysis' => $analysis, 'ai_analyzed_at' => now()]);
        } catch (Throwable $e) {
            $this->investigateErrors[$incident->id] = $e->getMessage();
        }
    }

    /**
     * Wipes every incident (all types/statuses, ignoring the active filter - see
     * the confirmation dialog). Bound to both "Reset" and "Hapus Data" in the view.
     */
    public function delete(Incident $incident): void
    {
        $this->authorize('insiden.delete');

        $incident->delete();
        $this->toastSuccess('Insiden dihapus.');
    }

    public function deleteAll(): void
    {
        $this->authorize('insiden.delete');

        // Scoped to what this page actually lists - wiping judol incidents from here
        // would silently break the detections that link to them.
        Incident::query()->where('type', '!=', 'judol')->delete();

        $this->resetPage();
        $this->toastSuccess('Semua data insiden website berhasil dihapus.');
    }

    /**
     * The filtered set, before it gets split into (site, day) groups.
     */
    protected function baseQuery()
    {
        return Incident::query()
            // Judol findings have their own page, with their own evidence and
            // workflow; leaving them here buried the website-health incidents.
            ->where('type', '!=', 'judol')
            ->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('title', 'like', "%{$this->search}%")
                ->orWhereHas('website', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))));
    }

    public function render()
    {
        ['groups' => $groups, 'rows' => $rows] = DailyGroups::forPage(
            query: fn () => $this->baseQuery(),
            dateColumn: 'detected_at',
            rowKey: fn (Incident $incident) => DailyGroups::key($incident->website_id, $incident->detected_at->toDateString()),
        );

        return view('livewire.incidents.index', [
            'groups' => $groups,
            'incidentsByGroup' => $rows,
            'websites' => Website::whereIn('id', $groups->pluck('site_key'))->get()->keyBy('id'),
        ]);
    }
}
