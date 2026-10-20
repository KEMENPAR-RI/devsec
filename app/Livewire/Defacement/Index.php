<?php

namespace App\Livewire\Defacement;

use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\AuditLog;
use App\Models\DefacementFinding;
use App\Models\Website;
use App\Services\Monitoring\DefacementMonitor;
use App\Services\Monitoring\DefacementSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Defacement: content-defacement and code-injection findings, with "tandai sah"
 * (current page becomes the baseline), "selesai", and the detection thresholds.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    use InteractsWithToasts, WithPagination;

    protected const FILTERS = ['site', 'status', 'kind'];

    /** Website uuid. */
    #[Url]
    public string $site = '';

    /** '' = every status. */
    #[Url]
    public string $status = 'open';

    #[Url]
    public string $kind = '';

    public int $replacePercent = 60;

    public int $shrinkPercent = 85;

    public int $minBaselineChars = 300;

    public function mount(): void
    {
        $this->authorize('defacement.view');

        $settings = app(DefacementSettings::class);
        $this->replacePercent = $settings->replacePercent();
        $this->shrinkPercent = $settings->shrinkPercent();
        $this->minBaselineChars = $settings->minBaselineChars();
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->validateOnly($property, $this->filterRules());
            $this->resetPage();
        }
    }

    /** $contentHash: the snapshot hash the operator was looking at when clicking. */
    public function markLegitimate(DefacementFinding $finding, string $contentHash = ''): void
    {
        $this->authorize('defacement.update');

        if (! app(DefacementMonitor::class)->markLegitimate($finding, Auth::user(), $contentHash)) {
            $this->toastError('Temuan sudah tidak terbuka atau halaman berubah sejak ditampilkan. Muat ulang lalu periksa kembali.');

            return;
        }

        AuditLog::record('defacement.ditandai-sah', $finding->url);

        $this->toastSuccess('Ditandai sebagai perubahan sah - konten saat ini menjadi baseline baru.');
    }

    public function markResolved(DefacementFinding $finding): void
    {
        $this->authorize('defacement.update');

        app(DefacementMonitor::class)->markResolved($finding, Auth::user());
        AuditLog::record('defacement.ditandai-selesai', $finding->url);

        $this->toastSuccess('Temuan ditandai selesai.');
    }

    public function delete(DefacementFinding $finding): void
    {
        $this->authorize('defacement.delete');

        $finding->delete();
        // An incident resting only on this finding must not stay open.
        app(DefacementMonitor::class)->afterClosing($finding);
        AuditLog::record('defacement.dihapus', $finding->url);

        $this->toastSuccess('Temuan defacement dihapus.');
    }

    public function saveThresholds(): void
    {
        $this->authorize('defacement.update');

        $this->validate([
            'replacePercent' => ['required', 'integer', 'min:30', 'max:95'],
            'shrinkPercent' => ['required', 'integer', 'min:50', 'max:99'],
            'minBaselineChars' => ['required', 'integer', 'min:100', 'max:5000'],
        ], [], [
            'replacePercent' => 'ambang konten terganti',
            'shrinkPercent' => 'ambang menyusut',
            'minBaselineChars' => 'panjang minimal baseline',
        ]);

        app(DefacementSettings::class)->save([
            'replace_percent' => $this->replacePercent,
            'shrink_percent' => $this->shrinkPercent,
            'min_baseline_chars' => $this->minBaselineChars,
        ]);

        $this->toastSuccess('Ambang defacement disimpan.');
    }

    /** @return array<string, array<int, string>> */
    protected function filterRules(): array
    {
        return [
            'site' => ['nullable', 'string', 'exists:websites,uuid'],
            'status' => ['nullable', 'in:open,recovered,legitimate,resolved'],
            'kind' => ['nullable', 'in:content,injection'],
        ];
    }

    /** @return array{site: string, status: string, kind: string} valid filters only */
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
        $filters = $this->filters();

        return view('livewire.defacement.index', [
            'findings' => DefacementFinding::query()
                ->with(['website:id,uuid,name', 'incident:id,uuid,status', 'snapshot:id,content_hash'])
                ->when($filters['site'] !== '', fn ($q) => $q->whereHas('website', fn ($w) => $w->where('uuid', $filters['site'])))
                ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
                ->when($filters['kind'] !== '', fn ($q) => $q->where('kind', $filters['kind']))
                ->latest('last_seen_at')
                ->latest('id')
                ->paginate(20),
            'websites' => Website::query()->orderBy('name')->get(['id', 'uuid', 'name']),
        ]);
    }
}
