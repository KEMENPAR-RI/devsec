<?php

namespace App\Livewire\ContentChanges;

use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\AuditLog;
use App\Models\PageChange;
use App\Models\Website;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Perubahan Konten: content-change events recorded by the judol crawl (see
 * PageContentMonitor), filterable by site, date range, type and URL.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    use InteractsWithToasts, WithPagination;

    protected const FILTERS = ['site', 'from', 'to', 'type', 'search'];

    /** Website uuid. */
    #[Url]
    public string $site = '';

    /** Y-m-d */
    #[Url]
    public string $from = '';

    /** Y-m-d */
    #[Url]
    public string $to = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $search = '';

    /** Which event's diff is expanded; null when all are collapsed. */
    public ?string $expanded = null;

    public function mount(): void
    {
        $this->authorize('perubahan-konten.view');

        if ($this->from === '' && $this->to === '') {
            $this->from = now()->subDays(7)->format('Y-m-d');
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->validateOnly($property, $this->filterRules());
            $this->resetPage();
        }
    }

    public function toggle(string $uuid): void
    {
        $this->expanded = $this->expanded === $uuid ? null : $uuid;
    }

    public function delete(PageChange $change): void
    {
        $this->authorize('perubahan-konten.delete');

        $change->delete();
        AuditLog::record('perubahan-konten.dihapus', $change->url, ['id' => $change->id, 'jenis' => $change->type]);

        $this->toastSuccess('Riwayat perubahan dihapus.');
    }

    /** Deletes every event matching the current filters. */
    public function deleteFiltered(): void
    {
        $this->authorize('perubahan-konten.delete');

        $this->validate($this->filterRules());

        $count = 0;
        $filters = $this->filters();

        PageChange::query()
            ->forFilters($filters)
            ->select('id')
            ->chunkById(500, function ($rows) use (&$count) {
                $count += PageChange::query()->whereIn('id', $rows->pluck('id'))->delete();
            });

        AuditLog::record('perubahan-konten.dihapus', "{$count} riwayat", ['filter' => array_filter($filters, 'filled') ?: 'semua']);
        $this->resetPage();

        $this->toastSuccess("{$count} riwayat perubahan dihapus.");
    }

    /** @return array<string, array<int, string>> */
    protected function filterRules(): array
    {
        return [
            'site' => ['nullable', 'string', 'exists:websites,uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['nullable', 'in:added,changed,removed'],
            'search' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * The filters that pass validation - an invalid value (e.g. hand-edited URL)
     * is simply ignored instead of reaching the query.
     *
     * @return array{site: string, from: string, to: string, type: string, search: string}
     */
    protected function filters(): array
    {
        $values = [];

        foreach (self::FILTERS as $filter) {
            $values[$filter] = trim($this->{$filter});
        }

        $failed = Validator::make($values, $this->filterRules())->errors();

        foreach (self::FILTERS as $filter) {
            if ($failed->has($filter)) {
                $values[$filter] = '';
            }
        }

        return $values;
    }

    public function render()
    {
        return view('livewire.content-changes.index', [
            'changes' => PageChange::query()
                ->with(['website:id,uuid,name', 'judolDetection:id,url', 'defacementFinding:id,uuid,status'])
                ->forFilters($this->filters())
                ->latest('detected_at')
                ->latest('id')
                ->paginate(20),
            'websites' => Website::query()->orderBy('name')->get(['id', 'uuid', 'name']),
        ]);
    }
}
